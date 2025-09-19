<?php

declare(strict_types=1);

namespace Tetthys\Pie\Sink;

use Tetthys\Pie\Contracts\BatchSinkInterface;
use Tetthys\Pie\Domain\BatchEnvelope;

/** Writes a compact NDJSON line per batch (bank-agnostic). */
final class FileLogSink implements BatchSinkInterface
{
    public function __construct(private readonly string $file) {}
    public function send(BatchEnvelope $e): bool
    {
        @is_dir(dirname($this->file)) || mkdir(dirname($this->file), 0777, true);
        $row = ['shard' => $e->shardId, 'window_start' => $e->windowStart, 'window_sec' => $e->windowDuration, 'count' => count($e->items)];
        file_put_contents($this->file, json_encode($row) . "\n", FILE_APPEND);
        return true;
    }
}
