<?php

declare(strict_types=1);

namespace Tetthys\Pie\Contracts;

/** Minimal pull consumer. Returns decoded message or null. */
interface QueueConsumerInterface
{
    public function getOne(): ?array;
}
