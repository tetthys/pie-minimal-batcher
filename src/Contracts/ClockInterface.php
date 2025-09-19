<?php

declare(strict_types=1);

namespace Tetthys\Pie\Contracts;

/** Clock abstraction for testability. */
interface ClockInterface
{
    public function now(): int;
}
