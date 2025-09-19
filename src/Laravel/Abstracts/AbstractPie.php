<?php

declare(strict_types=1);

namespace Tetthys\Pie\Laravel\Abstracts;

use Tetthys\Pie\Laravel\Contracts\PieInterface;
use Tetthys\Pie\Contracts\QueuePublisherInterface;
use Tetthys\Pie\Contracts\CooldownRegistryInterface;

/**
 * Base implementation for PieInterface that wires publisher & registry.
 * Subclasses only need to resolve a worker instance per shard id.
 */
abstract class AbstractPie implements PieInterface
{
    protected QueuePublisherInterface $publisher;
    protected CooldownRegistryInterface $registry;

    public function __construct(
        QueuePublisherInterface $publisher,
        CooldownRegistryInterface $registry
    ) {
        $this->publisher = $publisher;
        $this->registry  = $registry;
    }

    /** {@inheritdoc} */
    public function publish(array $message, string $routingKey): void
    {
        // Input hardening left to publisher implementation (e.g., size checks).
        $this->publisher->publish($message, $routingKey);
    }

    /** {@inheritdoc} */
    public function setCooldown(string $identity, int $untilEpoch): void
    {
        $this->registry->setCooldown($identity, $untilEpoch);
    }

    /** {@inheritdoc} */
    public function tick(int $shardId): void
    {
        $worker = $this->resolveWorker($shardId);
        if (!method_exists($worker, 'tick')) {
            throw new \RuntimeException('Worker must expose a tick() method.');
        }
        $worker->tick();
    }

    /** Subclasses must return a worker object that exposes tick(). */
    abstract protected function resolveWorker(int $shardId): object;
}
