<?php

declare(strict_types=1);

namespace Tetthys\Pie\Laravel;

use Tetthys\Pie\Laravel\Abstracts\AbstractPie;

/**
 * Concrete manager that takes a worker factory callback.
 * The factory receives a shard id and must return an object with tick().
 */
final class PieManager extends AbstractPie
{
    /** @var callable(int):object */
    private $workerFactory;

    /**
     * @param callable(int):object $workerFactory Returns an object exposing tick()
     */
    public function __construct(
        \Tetthys\Pie\Contracts\QueuePublisherInterface $publisher,
        \Tetthys\Pie\Contracts\CooldownRegistryInterface $registry,
        callable $workerFactory
    ) {
        parent::__construct($publisher, $registry);
        $this->workerFactory = $workerFactory;
    }

    protected function resolveWorker(int $shardId): object
    {
        $worker = ($this->workerFactory)($shardId);
        if (!is_object($worker) || !method_exists($worker, 'tick')) {
            throw new \RuntimeException('Worker factory must return an object with tick().');
        }
        return $worker;
    }
}
