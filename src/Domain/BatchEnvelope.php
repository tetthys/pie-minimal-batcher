<?php

declare(strict_types=1);

namespace Tetthys\Pie\Domain;

/** Envelope sent once per window per shard. */
final class BatchEnvelope
{
    /** @param BatchItem[] $items */
    public function __construct(
        public readonly int $shardId,
        public readonly array $items,
        public readonly int $windowStart,
        public readonly int $windowDuration
    ) {
        if ($shardId < 0) throw new \InvalidArgumentException('invalid shard');
        if (!$items) throw new \InvalidArgumentException('empty items');
    }
}
