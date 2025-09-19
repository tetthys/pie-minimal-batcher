<?php

declare(strict_types=1);

namespace Tetthys\Pie\Support;

use Tetthys\Pie\Contracts\ClockInterface;

/** Fixed-window gate that opens once per windowSec. */
final class FixedWindowGate
{
    private int $windowStart;
    public function __construct(private readonly ClockInterface $clock, private readonly int $windowSec = 3600)
    {
        $this->windowStart = intdiv($clock->now(), $this->windowSec) * $this->windowSec;
    }

    public function openIfDue(): bool
    {
        $cur = intdiv($this->clock->now(), $this->windowSec) * $this->windowSec;
        if ($cur > $this->windowStart) {
            $this->windowStart = $cur;
            return true;
        }
        return false;
    }
    public function currentWindowStart(): int
    {
        return $this->windowStart;
    }
    public function windowDuration(): int
    {
        return $this->windowSec;
    }
}
