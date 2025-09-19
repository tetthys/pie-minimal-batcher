<?php

declare(strict_types=1);

use Tetthys\Pie\Support\FixedWindowGate;
use Tetthys\Pie\Contracts\ClockInterface;

describe('FixedWindowGate', function () {

    it('returns false within the same window and true exactly once when crossing the boundary', function () {
        // Fake controllable clock: start at 3599, window=3600
        $clock = new class implements ClockInterface {
            public int $t = 3599;
            public function now(): int
            {
                return $this->t;
            }
        };

        $gate = new FixedWindowGate($clock, 3600);

        // Still inside [0, 3599] -> should NOT open
        expect($gate->openIfDue())->toBeFalse();

        // Cross boundary to 3600 -> MUST open exactly once
        $clock->t = 3600;
        expect($gate->openIfDue())->toBeTrue();

        // Calling again within the same new window -> MUST be false
        expect($gate->openIfDue())->toBeFalse();
    });

    it('never opens twice without boundary crossing even after many polls', function () {
        $clock = new class implements ClockInterface {
            public int $t = 100;
            public function now(): int
            {
                return $this->t;
            }
        };

        $gate = new FixedWindowGate($clock, 60);

        // Cross once to [120, 179]
        $clock->t = 120;
        expect($gate->openIfDue())->toBeTrue();

        // Many polls inside the same window -> still false
        for ($i = 0; $i < 10; $i++) {
            expect($gate->openIfDue())->toBeFalse();
        }

        // Next boundary -> open again exactly once
        $clock->t = 180;
        expect($gate->openIfDue())->toBeTrue()
            ->and($gate->openIfDue())->toBeFalse();
    });

    it('works with arbitrary window sizes and preserves state in accessors', function () {
        $clock = new class implements ClockInterface {
            public int $t = 7; // arbitrary start
            public function now(): int
            {
                return $this->t;
            }
        };

        $gate = new FixedWindowGate($clock, 10); // window buckets: [0..9], [10..19], ...

        // Initial window should be [0..9]
        expect($gate->openIfDue())->toBeFalse();
        expect($gate->currentWindowStart())->toBe(0)
            ->and($gate->windowDuration())->toBe(10);

        // Cross into [10..19]
        $clock->t = 10;
        expect($gate->openIfDue())->toBeTrue();
        expect($gate->currentWindowStart())->toBe(10)
            ->and($gate->windowDuration())->toBe(10);

        // Stay in [10..19]
        $clock->t = 19;
        expect($gate->openIfDue())->toBeFalse();
        expect($gate->currentWindowStart())->toBe(10);
    });

    it('does not drift after multiple boundary crossings', function () {
        $clock = new class implements ClockInterface {
            public int $t = 0;
            public function now(): int
            {
                return $this->t;
            }
        };

        $gate = new FixedWindowGate($clock, 5); // fast windows for test

        // Cross 3 boundaries in a row
        foreach ([5, 10, 15] as $t) {
            $clock->t = $t;
            expect($gate->openIfDue())->toBeTrue();
            // Re-check immediately: still false until the next boundary
            expect($gate->openIfDue())->toBeFalse();
            expect($gate->currentWindowStart())->toBe($t);
        }
    });
});
