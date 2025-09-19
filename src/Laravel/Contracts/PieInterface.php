<?php

declare(strict_types=1);

namespace Tetthys\Pie\Laravel\Contracts;

/**
 * Minimal Laravel-facing contract for publishing messages,
 * setting cooldowns, and ticking a shard worker.
 * Bank/business agnostic by design.
 */
interface PieInterface
{
    /** Publish a message with a routing key (e.g., shard key). */
    public function publish(array $message, string $routingKey): void;

    /** Set a per-identity cooldown until the given epoch seconds. */
    public function setCooldown(string $identity, int $untilEpoch): void;

    /** Execute one worker tick for the given shard id. */
    public function tick(int $shardId): void;
}
