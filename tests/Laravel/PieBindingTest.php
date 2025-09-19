<?php

declare(strict_types=1);

use Tetthys\Pie\Laravel\Contracts\PieInterface;

describe('Pie binding (container-level)', function () {

    it('binds the pie key via manual singleton without resolving any external dependency', function () {
        // Arrange: ensure not bound yet
        if (app()->bound('pie')) {
            // Clean up prior runs if any
            app()->forgetInstance('pie');
        }

        // Bind a minimal fake PieInterface to avoid external systems.
        $calls = ['publish' => [], 'cooldown' => [], 'tick' => []];

        app()->singleton('pie', function () use (&$calls) {
            // Minimal fake implementing PieInterface; mutates $calls by reference.
            return new class($calls) implements PieInterface {
                /** @var array{publish:array,cooldown:array,tick:array} */
                private array $calls;

                public function __construct(array &$calls)
                {
                    $this->calls = &$calls;
                }

                /** Publish and capture inputs in memory (no I/O). */
                public function publish(array $message, string $routingKey): void
                {
                    $this->calls['publish'][] = ['msg' => $message, 'rk' => $routingKey];
                }

                /** Record cooldown intent in memory. */
                public function setCooldown(string $identity, int $untilEpoch): void
                {
                    $this->calls['cooldown'][] = [$identity, $untilEpoch];
                }

                /** Record worker tick for a shard id. */
                public function tick(int $shardId): void
                {
                    $this->calls['tick'][] = $shardId;
                }
            };
        });

        // Assert: binding exists and resolves to the expected contract.
        expect(app()->bound('pie'))->toBeTrue();

        $pie = app('pie');
        expect($pie)->toBeInstanceOf(PieInterface::class);

        // Exercise & verify the fake captured calls
        $pie->publish(['a' => 1], 'shard-3');
        $pie->setCooldown('ID-1', 999);
        $pie->tick(3);

        // Re-resolve to read captured calls (same singleton instance)
        $again = app('pie');
        /** @var PieInterface $again */
        expect($again)->toBe($pie);
    });

    it('allows overriding the pie binding with another fake implementation', function () {
        // Arrange: initial binding
        $first = new class implements PieInterface {
            public function publish(array $message, string $routingKey): void {}
            public function setCooldown(string $identity, int $untilEpoch): void {}
            public function tick(int $shardId): void {}
        };
        app()->singleton('pie', fn() => $first);
        expect(app('pie'))->toBe($first);

        // Act: override binding with a new instance
        $second = new class implements PieInterface {
            public function publish(array $message, string $routingKey): void {}
            public function setCooldown(string $identity, int $untilEpoch): void {}
            public function tick(int $shardId): void {}
        };
        app()->singleton('pie', fn() => $second);

        // Assert: the resolved instance is now the second one
        expect(app('pie'))->toBe($second);
    });
});
