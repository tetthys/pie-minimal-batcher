<?php

declare(strict_types=1);

use Tetthys\Pie\Contracts\ClockInterface;
use Tetthys\Pie\Support\SystemClock;
use Tetthys\Pie\Support\SqliteCooldownRegistry;
use Tetthys\Pie\Infra\Rabbit\RabbitConnection;
use Tetthys\Pie\Infra\Rabbit\RabbitConsumer;
use Tetthys\Pie\Sink\FileLogSink;
use Tetthys\Pie\Worker\ShardedBatchWorker;

require __DIR__ . '/../vendor/autoload.php';

$shardId = (int)($argv[1] ?? 0);

$host = getenv('PIE_RMQ_HOST') ?: 'rabbit';
$port = (int)(getenv('PIE_RMQ_PORT') ?: 5672);
$user = getenv('PIE_RMQ_USER') ?: 'pie_user';
$pass = getenv('PIE_RMQ_PASS') ?: 'pie_pass';
$vhost = getenv('PIE_RMQ_VHOST') ?: '/pie';

$clock = new SystemClock();
$reg   = new SqliteCooldownRegistry(__DIR__ . '/../var/cooldowns.sqlite');
$conn  = new RabbitConnection($host, $port, $user, $pass, $vhost);

$exchange = 'payout.direct';
$queue    = "payout.shard.$shardId";
$rkey     = "shard-$shardId";

$consumer = new RabbitConsumer($conn, $exchange, $queue, $rkey, 128, false);
$sink     = new FileLogSink(__DIR__ . '/../var/out_shard_' . $shardId . '.ndjson');
$worker   = new ShardedBatchWorker($shardId, $consumer, $sink, $reg, $clock, 3600);

while (true) {
    $worker->tick();
    usleep(1000 * 100);
}
