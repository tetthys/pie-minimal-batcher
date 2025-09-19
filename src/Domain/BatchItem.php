<?php

declare(strict_types=1);

namespace Tetthys\Pie\Domain;

/** One batched unit with opaque payload (bank-agnostic). */
final class BatchItem
{
    public function __construct(
        public readonly string $identity,
        public readonly array $payload,
        public readonly string $uuid
    ) {}
}
