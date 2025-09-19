<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Tetthys\Pie\Laravel\Facades\Pie;
use Tetthys\Pie\Laravel\PieManager;
use Tetthys\Pie\Contracts\QueuePublisherInterface;
use Tetthys\Pie\Contracts\CooldownRegistryInterface;

describe('Pie facade', function () {

    it('proxies publish/setCooldown/tick to the underlying container binding', function () {
        // Ensure the Facade uses the same container instance as app()
        // This is required outside a full Laravel application bootstrap.
        Facade::setFacadeApplication(app());

        // Prepare fakes (no external dependencies)
        $published = [];
        $cooldowns = [];
        $ticks     = [];

        // Fake publisher: captures publish calls
        $fakePublisher = new class($published) implements QueuePublisherInterface {
            /** @var array<int, array{msg: array, rk: string}> */
            private array $pub;
            public function __construct(array &$p)
            {
                $this->pub = &$p;
            }
            public function publish(array $msg, string $rk): void
            {
                $this->pub[] = ['msg' => $msg, 'rk' => $rk];
            }
        };

        // Fake registry: captures cooldowns
        $fakeRegistry = new class($cooldowns) implements CooldownRegistryInterface {
            /** @var array<string,int> */
            private array $cd;
            public function __construct(array &$c)
            {
                $this->cd = &$c;
            }
            public function setCooldown(string $i, int $u): void
            {
                $this->cd[$i] = $u;
            }
            public function getCooldownUntil(string $i): ?int
            {
                return $this->cd[$i] ?? null;
            }
            public function clearIfExpired(string $i): void
            {
                unset($this->cd[$i]);
            }
        };

        // Worker factory: returns an object exposing tick() that records shard ids
        $factory = function (int $shardId) use (&$ticks) {
            return new class($shardId, $ticks) {
                private int $id;
                /** @var array<int,int> */
                private array $t;
                public function __construct(int $id, array &$t)
                {
                    $this->id = $id;
                    $this->t = &$t;
                }
                public function tick(): void
                {
                    $this->t[] = $this->id;
                }
            };
        };

        // Bind the facade root: Pie::class resolves 'pie' from the container.
        app()->singleton('pie', fn() => new PieManager($fakePublisher, $fakeRegistry, $factory));

        // Use the Facade and assert behavior
        Pie::publish(['a' => 1], 'shard-3');
        expect($published)->toHaveCount(1)
            ->and($published[0]['rk'])->toBe('shard-3')
            ->and($published[0]['msg'])->toBe(['a' => 1]);

        Pie::setCooldown('ID-1', 999);
        expect($cooldowns)->toHaveKey('ID-1')
            ->and($cooldowns['ID-1'])->toBe(999);

        Pie::tick(3);
        expect($ticks)->toBe([3]);
    });
});
