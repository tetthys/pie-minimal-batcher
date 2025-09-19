<?php

declare(strict_types=1);

use Tetthys\Pie\Contracts\{BatchSinkInterface, ClockInterface, CooldownRegistryInterface, QueueConsumerInterface};
use Tetthys\Pie\Domain\BatchEnvelope;
use Tetthys\Pie\Worker\ShardedBatchWorker;

it('defers cooled identities and flushes on window open', function () {
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
            return $i === 'A' ? 4000 : null;
        }
        public function clearIfExpired(string $i): void {}
    };

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

    $cap = [];

    // NOTE: You cannot declare a property by reference.
    // Declare a normal property and assign by reference inside the constructor.
    $sink = new class($cap) implements BatchSinkInterface {
        /** @var array<int, BatchEnvelope> */
        private array $c; // not by-reference here

        public function __construct(array &$c)
        {
            // Assign by-reference at construction time
            $this->c = &$c;
        }

        public function send(BatchEnvelope $e): bool
        {
            $this->c[] = $e;
            return true;
        }
    };

    $w = new ShardedBatchWorker(3, $consumer, $sink, $cool, $clock, 3600);

    // drains -> only B buffered
    $w->tick();
    expect($cap)->toHaveCount(0);

    // new window -> flush B
    $clock->t = 3600;
    $w->tick();

    expect($cap)->toHaveCount(1)
        ->and($cap[0]->shardId)->toBe(3)
        ->and($cap[0]->windowStart)->toBe(3600)
        ->and($cap[0]->windowDuration)->toBe(3600);
});
