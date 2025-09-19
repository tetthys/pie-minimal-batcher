<?php

declare(strict_types=1);

use Tetthys\Pie\Contracts\{BatchSinkInterface, ClockInterface, CooldownRegistryInterface, QueueConsumerInterface};
use Tetthys\Pie\Domain\BatchEnvelope;
use Tetthys\Pie\Worker\ShardedBatchWorker;

describe('ShardedBatchWorker', function () {

    it('buffers non-cooled messages and flushes exactly once at the next window boundary', function () {
        // Fake clock: controlled time
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        // Cooldown: 'A' is blocked until t=4000; 'B' is free
        $cool = new class implements CooldownRegistryInterface {
            public function setCooldown(string $i, int $u): void {}
            public function getCooldownUntil(string $i): ?int
            {
                return $i === 'A' ? 4000 : null;
            }
            public function clearIfExpired(string $i): void {}
        };

        // Queue: A (will be dropped), B (will be buffered)
        $msgs = [
            ['identity' => 'A', 'payload' => ['x' => 1], 'uuid' => 'u1'],
            ['identity' => 'B', 'payload' => ['x' => 2], 'uuid' => 'u2'],
        ];
        $consumer = new class($msgs) implements QueueConsumerInterface {
            /** @var array<int,array> */
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

        // Capture sink
        $captured = [];
        $sink = new class($captured) implements BatchSinkInterface {
            /** @var array<int, BatchEnvelope> */
            private array $cap;
            public function __construct(array &$c)
            {
                $this->cap = &$c;
            }
            public function send(BatchEnvelope $e): bool
            {
                $this->cap[] = $e;
                return true;
            }
        };

        $w = new ShardedBatchWorker(3, $consumer, $sink, $cool, $clock, 3600);

        // t=0: drain -> only 'B' stays in buffer, no flush yet
        $w->tick();
        expect($captured)->toHaveCount(0);

        // Cross boundary to t=3600: should flush one batch with only 'B'
        $clock->t = 3600;
        $w->tick();

        expect($captured)->toHaveCount(1)
            ->and($captured[0]->shardId)->toBe(3)
            ->and($captured[0]->windowStart)->toBe(3600)
            ->and($captured[0]->windowDuration)->toBe(3600)
            ->and($captured[0]->items)->toHaveCount(1)
            ->and($captured[0]->items[0]->identity)->toBe('B')
            ->and($captured[0]->items[0]->payload)->toBe(['x' => 2])
            ->and($captured[0]->items[0]->uuid)->toBe('u2');
    });

    it('does nothing when buffer is empty even if the window opens', function () {
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        $cool = new class implements CooldownRegistryInterface {
            public function setCooldown(string $i, int $u): void {}
            public function getCooldownUntil(string $i): ?int
            {
                return null;
            }
            public function clearIfExpired(string $i): void {}
        };

        // Empty queue
        $consumer = new class implements QueueConsumerInterface {
            public function getOne(): ?array
            {
                return null;
            }
        };

        $captured = [];
        $sink = new class($captured) implements BatchSinkInterface {
            private array $cap;
            public function __construct(array &$c)
            {
                $this->cap = &$c;
            }
            public function send(BatchEnvelope $e): bool
            {
                $this->cap[] = $e;
                return true;
            }
        };

        $w = new ShardedBatchWorker(1, $consumer, $sink, $cool, $clock, 60);

        // t=0 tick with empty queue -> nothing
        $w->tick();
        expect($captured)->toHaveCount(0);

        // Move to next window boundary -> still nothing because buffer is empty
        $clock->t = 60;
        $w->tick();
        expect($captured)->toHaveCount(0);
    });

    it('accumulates multiple messages across ticks within the same window and flushes once', function () {
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        $cool = new class implements CooldownRegistryInterface {
            public function setCooldown(string $i, int $u): void {}
            public function getCooldownUntil(string $i): ?int
            {
                return null;
            } // nobody cooling
            public function clearIfExpired(string $i): void {}
        };

        // Three messages, all eligible
        $msgs = [
            ['identity' => 'B1', 'payload' => ['p' => 1], 'uuid' => 'u1'],
            ['identity' => 'B2', 'payload' => ['p' => 2], 'uuid' => 'u2'],
            ['identity' => 'B3', 'payload' => ['p' => 3], 'uuid' => 'u3'],
        ];
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

        $captured = [];
        $sink = new class($captured) implements BatchSinkInterface {
            private array $cap;
            public function __construct(array &$c)
            {
                $this->cap = &$c;
            }
            public function send(BatchEnvelope $e): bool
            {
                $this->cap[] = $e;
                return true;
            }
        };

        $w = new ShardedBatchWorker(2, $consumer, $sink, $cool, $clock, 100);

        // Same window: call tick multiple times to simulate staggered arrivals
        $w->tick(); // consume B1
        $w->tick(); // consume B2
        $w->tick(); // consume B3
        expect($captured)->toHaveCount(0);

        // Cross boundary -> a single flush with all 3 items
        $clock->t = 100;
        $w->tick();
        expect($captured)->toHaveCount(1)
            ->and($captured[0]->items)->toHaveCount(3);
    });

    it('flushes batches on consecutive window crossings (multiple cycles)', function () {
        // Fake clock
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        // No cooldown
        $cool = new class implements CooldownRegistryInterface {
            public function setCooldown(string $i, int $u): void {}
            public function getCooldownUntil(string $i): ?int
            {
                return null;
            }
            public function clearIfExpired(string $i): void {}
        };

        // Clock-aware consumer:
        // - Return X at the very beginning.
        // - Return NULL until time strictly after the first boundary (t >= 11).
        // - Return Y only when t >= 11 (i.e., in the next window [10..19]).
        $consumer = new class($clock) implements QueueConsumerInterface {
            private ClockInterface $clock;
            private int $step = 0;
            public function __construct(ClockInterface $c)
            {
                $this->clock = $c;
            }
            public function getOne(): ?array
            {
                if ($this->step === 0) { // first call: X
                    $this->step++;
                    return ['identity' => 'X', 'payload' => ['n' => 1], 'uuid' => 'ux'];
                }
                // Defer Y until after the first flush has happened at t=10
                if ($this->clock->now() >= 11 && $this->step === 1) {
                    $this->step++;
                    return ['identity' => 'Y', 'payload' => ['n' => 2], 'uuid' => 'uy'];
                }
                return null;
            }
        };

        // Capture sink
        $captured = [];
        $sink = new class($captured) implements BatchSinkInterface {
            private array $cap;
            public function __construct(array &$c)
            {
                $this->cap = &$c;
            }
            public function send(BatchEnvelope $e): bool
            {
                $this->cap[] = $e;
                return true;
            }
        };

        $w = new ShardedBatchWorker(7, $consumer, $sink, $cool, $clock, 10);

        // Window #1: t=0 consume X, no flush yet
        $w->tick();

        // Cross boundary to t=10 -> flush #1 (only X)
        $clock->t = 10;
        $w->tick();
        expect($captured)->toHaveCount(1)
            ->and($captured[0]->items)->toHaveCount(1)
            ->and($captured[0]->items[0]->identity)->toBe('X');

        // Move into the same (second) window and allow Y to appear
        $clock->t = 11;
        $w->tick(); // buffer Y in window [10..19]

        // Next boundary t=20 -> flush #2 (only Y)
        $clock->t = 20;
        $w->tick();
        expect($captured)->toHaveCount(2)
            ->and($captured[1]->items)->toHaveCount(1)
            ->and($captured[1]->items[0]->identity)->toBe('Y');
    });
});
