<?php

declare(strict_types=1);

namespace Tetthys\Pie\Contracts;

/** Minimal publisher for tests/demos. */
interface QueuePublisherInterface
{
    /** @param array{identity:string,payload:mixed,uuid:string} $msg */
    public function publish(array $msg, string $routingKey): void;
}
