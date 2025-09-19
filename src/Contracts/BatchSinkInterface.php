<?php

declare(strict_types=1);

namespace Tetthys\Pie\Contracts;

use Tetthys\Pie\Domain\BatchEnvelope;

/** Abstract sink that receives a batch. Bank-agnostic. */
interface BatchSinkInterface
{
    public function send(BatchEnvelope $e): bool;
}
