<?php

declare(strict_types=1);

use Tetthys\Pie\Contracts\{BatchSinkInterface, ClockInterface, CooldownRegistryInterface, QueueConsumerInterface};
use Tetthys\Pie\Domain\BatchEnvelope;
use Tetthys\Pie\Worker\ShardedBatchWorker;

describe('Virtual Bank domain payouts (practical examples)', function () {

    it('aggregates multiple payouts into a single hourly bank batch', function () {
        // --- Virtual time: start at t=0, 1h windows for bank batch
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        // --- No cooldowns in this scenario
        $cool = new class implements CooldownRegistryInterface {
            public function setCooldown(string $i, int $u): void {}
            public function getCooldownUntil(string $i): ?int
            {
                return null;
            }
            public function clearIfExpired(string $i): void {}
        };

        // --- Queue: two BuyerPaid-like payout intents (bank-agnostic shape)
        // identity ~ destination account, payload.amount_minor ~ integer amount
        $msgs = [
            ['identity' => 'ACC-1001', 'payload' => ['amount_minor' => 12500], 'uuid' => 'u-1'],
            ['identity' => 'ACC-1002', 'payload' => ['amount_minor' => 25000], 'uuid' => 'u-2'],
        ];

        // Minimal pull consumer that drains an in-memory array
        $consumer = new class($msgs) implements QueueConsumerInterface {
            private array $q;
            public function __construct(array $q)
            {
                $this->q = $q;
            }
            public function getOne(): ?array
            {
                return array_shift($this->q) ?? null;
            }
        };

        // --- Virtual Bank sink: adapts batch envelope to a bank "wire file" shape
        // For the test, capture batches and compute a naive total in minor units.
        $captured = [];
        $sink = new class($captured) implements BatchSinkInterface {
            /** @var array<int, array{window_start:int, items:array<int,array{account:string,amount_minor:int}>, total_minor:int}> */
            private array $cap;
            public function __construct(array &$ref)
            {
                $this->cap = &$ref;
            }
            public function send(BatchEnvelope $e): bool
            {
                $items = [];
                $total = 0;
                foreach ($e->items as $it) {
                    $amt = (int)($it->payload['amount_minor'] ?? 0);
                    $items[] = ['account' => $it->identity, 'amount_minor' => $amt];
                    $total += $amt;
                }
                $this->cap[] = [
                    'window_start' => $e->windowStart,
                    'items'        => $items,
                    'total_minor'  => $total,
                ];
                return true;
            }
        };

        // --- Worker for shard #0 (hourly window)
        $w = new ShardedBatchWorker(0, $consumer, $sink, $cool, $clock, 3600);

        // Drain inside same window: no flush yet
        $w->tick();
        expect($captured)->toHaveCount(0);

        // Cross boundary: 0 -> 3600 triggers exactly one bank batch
        $clock->t = 3600;
        $w->tick();

        expect($captured)->toHaveCount(1)
            ->and($captured[0]['items'])->toHaveCount(2)
            ->and($captured[0]['items'][0]['account'])->toBe('ACC-1001')
            ->and($captured[0]['items'][0]['amount_minor'])->toBe(12500)
            ->and($captured[0]['items'][1]['account'])->toBe('ACC-1002')
            ->and($captured[0]['items'][1]['amount_minor'])->toBe(25000)
            ->and($captured[0]['total_minor'])->toBe(37500);
    });

    it('honors travel-rule style cooldown: recent deposit cannot be withdrawn within the window', function () {
        // --- Virtual time & 1h windows
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        // --- Cooldown registry: identity `ACC-TRAVEL` is cooling until t=4000
        $cool = new class implements CooldownRegistryInterface {
            public function setCooldown(string $i, int $u): void {}
            public function getCooldownUntil(string $i): ?int
            {
                return $i === 'ACC-TRAVEL' ? 4000 : null;
            }
            public function clearIfExpired(string $i): void {}
        };

        // --- Consumer that returns one eligible payout now (ACC-FREE),
        // and returns the cooled payout (ACC-TRAVEL) only after t >= 4000.
        $consumer = new class($clock) implements QueueConsumerInterface {
            private ClockInterface $clock;
            private bool $gaveFree = false;
            private bool $gaveTravel = false;
            public function __construct(ClockInterface $c)
            {
                $this->clock = $c;
            }
            public function getOne(): ?array
            {
                if (!$this->gaveFree) {
                    $this->gaveFree = true;
                    return ['identity' => 'ACC-FREE', 'payload' => ['amount_minor' => 1000], 'uuid' => 'uf'];
                }
                if (!$this->gaveTravel && $this->clock->now() >= 4000) {
                    $this->gaveTravel = true;
                    return ['identity' => 'ACC-TRAVEL', 'payload' => ['amount_minor' => 2000], 'uuid' => 'ut'];
                }
                return null;
            }
        };

        // --- Virtual Bank sink
        $captured = [];
        $sink = new class($captured) implements BatchSinkInterface {
            private array $cap;
            public function __construct(array &$ref)
            {
                $this->cap = &$ref;
            }
            public function send(BatchEnvelope $e): bool
            {
                $this->cap[] = [
                    'window_start' => $e->windowStart,
                    'accounts'     => array_map(fn($i) => $i->identity, $e->items),
                ];
                return true;
            }
        };

        $w = new ShardedBatchWorker(5, $consumer, $sink, $cool, $clock, 3600);

        // t=0: buffer ACC-FREE only, no flush
        $w->tick();
        expect($captured)->toHaveCount(0);

        // t=3600: flush batch #1 (ACC-FREE only)
        $clock->t = 3600;
        $w->tick();
        expect($captured)->toHaveCount(1)
            ->and($captured[0]['accounts'])->toBe(['ACC-FREE']);

        // t=4000: cooled payout now becomes readable by consumer
        $clock->t = 4000;
        $w->tick(); // buffers ACC-TRAVEL

        // Next boundary t=7200: flush batch #2 (ACC-TRAVEL only)
        $clock->t = 7200;
        $w->tick();
        expect($captured)->toHaveCount(2)
            ->and($captured[1]['accounts'])->toBe(['ACC-TRAVEL']);
    });

    it('isolates shards like independent bank services: each shard gets one hourly batch', function () {
        // --- Virtual time
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        // --- Cooldown unused in this scenario
        $cool = new class implements CooldownRegistryInterface {
            public function setCooldown(string $i, int $u): void {}
            public function getCooldownUntil(string $i): ?int
            {
                return null;
            }
            public function clearIfExpired(string $i): void {}
        };

        // --- Shard 0 queue (ACC-A, ACC-B)
        $q0 = [
            ['identity' => 'ACC-A', 'payload' => ['amount_minor' => 3000], 'uuid' => 'a1'],
            ['identity' => 'ACC-B', 'payload' => ['amount_minor' => 2000], 'uuid' => 'b1'],
        ];
        $c0 = new class($q0) implements QueueConsumerInterface {
            private array $q;
            public function __construct(array $q)
            {
                $this->q = $q;
            }
            public function getOne(): ?array
            {
                return array_shift($this->q) ?? null;
            }
        };

        // --- Shard 1 queue (ACC-C)
        $q1 = [
            ['identity' => 'ACC-C', 'payload' => ['amount_minor' => 7000], 'uuid' => 'c1'],
        ];
        $c1 = new class($q1) implements QueueConsumerInterface {
            private array $q;
            public function __construct(array $q)
            {
                $this->q = $q;
            }
            public function getOne(): ?array
            {
                return array_shift($this->q) ?? null;
            }
        };

        // --- Two bank sinks, one per shard (to observe isolation)
        $cap0 = [];
        $cap1 = [];
        $s0 = new class($cap0) implements BatchSinkInterface {
            private array $cap;
            public function __construct(array &$r)
            {
                $this->cap = &$r;
            }
            public function send(BatchEnvelope $e): bool
            {
                $this->cap[] = $e;
                return true;
            }
        };
        $s1 = new class($cap1) implements BatchSinkInterface {
            private array $cap;
            public function __construct(array &$r)
            {
                $this->cap = &$r;
            }
            public function send(BatchEnvelope $e): bool
            {
                $this->cap[] = $e;
                return true;
            }
        };

        $w0 = new ShardedBatchWorker(0, $c0, $s0, $cool, $clock, 3600);
        $w1 = new ShardedBatchWorker(1, $c1, $s1, $cool, $clock, 3600);

        // t=0: both workers buffer their messages
        $w0->tick();
        $w1->tick();
        expect($cap0)->toHaveCount(0)->and($cap1)->toHaveCount(0);

        // Cross boundary t=3600: each shard flushes exactly one batch
        $clock->t = 3600;
        $w0->tick();
        $w1->tick();

        expect($cap0)->toHaveCount(1)
            ->and($cap0[0]->shardId)->toBe(0)
            ->and($cap0[0]->items)->toHaveCount(2);

        expect($cap1)->toHaveCount(1)
            ->and($cap1[0]->shardId)->toBe(1)
            ->and($cap1[0]->items)->toHaveCount(1);
    });
});
