<?php

declare(strict_types=1);

namespace Tetthys\Pie\Laravel;

use Illuminate\Support\ServiceProvider;
use Tetthys\Pie\Contracts\CooldownRegistryInterface;
use Tetthys\Pie\Contracts\QueuePublisherInterface;
use Tetthys\Pie\Contracts\QueueConsumerInterface;
use Tetthys\Pie\Contracts\ClockInterface;
use Tetthys\Pie\Support\SystemClock;
use Tetthys\Pie\Support\SqliteCooldownRegistry;
use Tetthys\Pie\Infra\Rabbit\RabbitConnection;
use Tetthys\Pie\Infra\Rabbit\RabbitPublisher;
use Tetthys\Pie\Infra\Rabbit\RabbitConsumer;
use Tetthys\Pie\Sink\FileLogSink;
use Tetthys\Pie\Worker\ShardedBatchWorker;

/**
 * Registers the 'pie' singleton in Laravel container.
 * Keeps defaults minimal and bank-agnostic; can be overridden via env.
 */
final class PieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('pie', function ($app) {
            // ---- Resolve core deps ----
            $clock = new SystemClock();

            // SQLite file under storage/app/pie
            $dbFile = storage_path('app/pie/cooldowns.sqlite');
            $registry = new SqliteCooldownRegistry($dbFile);

            // Rabbit connection from env (fallback to sensible defaults)
            $host  = env('PIE_RMQ_HOST', 'rabbit');
            $port  = (int)env('PIE_RMQ_PORT', 5672);
            $user  = env('PIE_RMQ_USER', 'pie_user');
            $pass  = env('PIE_RMQ_PASS', 'pie_pass');
            $vhost = env('PIE_RMQ_VHOST', '/pie');

            $connection = new RabbitConnection($host, $port, $user, $pass, $vhost);

            // Publisher (direct exchange)
            $exchange  = env('PIE_RMQ_EXCHANGE', 'payout.direct');
            $publisher = new RabbitPublisher($connection, $exchange);

            // Worker factory: builds a worker per shard id.
            $prefetch  = (int)env('PIE_RMQ_PREFETCH', 128);
            $windowSec = (int)env('PIE_WINDOW_SEC', 3600);
            $sinkDir   = storage_path('app/pie');

            $workerFactory = function (int $shardId) use (
                $connection,
                $exchange,
                $prefetch,
                $windowSec,
                $registry,
                $clock,
                $sinkDir
            ) {
                $queue = "payout.shard.$shardId";
                $rkey  = "shard-$shardId";

                // Consumer per shard
                $consumer = new RabbitConsumer($connection, $exchange, $queue, $rkey, $prefetch, false);

                // Bank-agnostic batch sink (NDJSON for observability)
                @is_dir($sinkDir) || mkdir($sinkDir, 0777, true);
                $sink = new FileLogSink($sinkDir . "/out_shard_{$shardId}.ndjson");

                // Compose worker
                return new ShardedBatchWorker($shardId, $consumer, $sink, $registry, $clock, $windowSec);
            };

            // Return concrete manager
            return new PieManager($publisher, $registry, $workerFactory);
        });
    }

    public function provides(): array
    {
        return ['pie'];
    }
}
