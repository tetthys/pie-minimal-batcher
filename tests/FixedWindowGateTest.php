<?php

declare(strict_types=1);

use Tetthys\Pie\Support\FixedWindowGate;
use Tetthys\Pie\Contracts\ClockInterface;

it('opens once across window boundary', function () {
    $clock = new class implements ClockInterface {
        public int $t = 3599;
        public function now(): int
        {
            return $this->t;
        }
    };
    $gate = new FixedWindowGate($clock, 3600);
    expect($gate->openIfDue())->toBeFalse();
    $clock->t = 3600;
    expect($gate->openIfDue())->toBeTrue()->and($gate->openIfDue())->toBeFalse();
});
