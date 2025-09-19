<?php

declare(strict_types=1);

namespace Tetthys\Pie\Support;

use Tetthys\Pie\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}
