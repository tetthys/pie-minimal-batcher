<?php

declare(strict_types=1);

namespace Tetthys\Pie\Worker;

use Tetthys\Pie\Contracts\BatchSinkInterface;
use Tetthys\Pie\Contracts\ClockInterface;
use Tetthys\Pie\Contracts\CooldownRegistryInterface;
use Tetthys\Pie\Contracts\QueueConsumerInterface;
use Tetthys\Pie\Domain\BatchEnvelope;
use Tetthys\Pie\Domain\BatchItem;
use Tetthys\Pie\Support\FixedWindowGate;

/**
 * Sharded worker with per-identity cooldown and fixed-window batching.
 * Minimalism: in-memory buffer per window; gate opens once/hour and flushes.
 */
final class ShardedBatchWorker
{
    /** @var array<int, array{identity:string,payload:array,uuid:string}> */
    private array $buffer = [];
    private FixedWindowGate $gate;

    public function __construct(
        private readonly int $shardId,
        private readonly QueueConsumerInterface $consumer,
        private readonly BatchSinkInterface $sink,
        private readonly CooldownRegistryInterface $cooldowns,
        private readonly ClockInterface $clock,
        int $windowSec = 3600
    ) {
        $this->gate = new FixedWindowGate($this->clock, $windowSec);
    }

    /** One iteration: drain burst, apply cooldown, maybe flush. */
    public function tick(): void
    {
        for ($i = 0; $i < 256; $i++) {
            $m = $this->consumer->getOne();
            if (!$m) break;
            $id = (string)($m['identity'] ?? '');
            $until = $this->cooldowns->getCooldownUntil($id) ?? 0;
            if ($until > $this->clock->now()) continue; // still cooling
            $this->buffer[] = $m;
        }

        if ($this->gate->openIfDue() && $this->buffer) {
            $items = array_map(
                fn($r) => new BatchItem((string)$r['identity'], (array)($r['payload'] ?? []), (string)$r['uuid']),
                $this->buffer
            );
            $env = new BatchEnvelope($this->shardId, $items, $this->gate->currentWindowStart(), $this->gate->windowDuration());
            $this->sink->send($env);
            $this->buffer = [];
        }
    }
}
