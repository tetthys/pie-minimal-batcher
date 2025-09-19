<?php

declare(strict_types=1);

namespace Tetthys\Pie\Contracts;

/** Tracks per-identity cooldown windows. */
interface CooldownRegistryInterface
{
    public function setCooldown(string $identity, int $untilEpoch): void;
    public function getCooldownUntil(string $identity): ?int;
    public function clearIfExpired(string $identity): void;
}
