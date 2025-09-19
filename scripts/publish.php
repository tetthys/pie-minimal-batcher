<?php

declare(strict_types=1);

use Tetthys\Pie\Infra\Rabbit\RabbitConnection;
use Tetthys\Pie\Infra\Rabbit\RabbitPublisher;

require __DIR__ . '/../vendor/autoload.php';

$N = (int)($argv[1] ?? 50);
$shardOf = static fn(string $id): int => (abs(crc32($id)) % 10);

$host = getenv('PIE_RMQ_HOST') ?: 'rabbit';
$port = (int)(getenv('PIE_RMQ_PORT') ?: 5672);
$user = getenv('PIE_RMQ_USER') ?: 'pie_user';
$pass = getenv('PIE_RMQ_PASS') ?: 'pie_pass';
$vhost = getenv('PIE_RMQ_VHOST') ?: '/pie';

$conn = new RabbitConnection($host, $port, $user, $pass, $vhost);
$pub  = new RabbitPublisher($conn, 'payout.direct');

for ($i = 0; $i < $N; $i++) {
    $id = 'ID-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
    $rk = 'shard-' . $shardOf($id);
    $msg = [
        'identity' => $id,
        'payload'  => ['amount_minor' => random_int(100, 10000)],
        'uuid'     => bin2hex(random_bytes(8))
    ];
    $pub->publish($msg, $rk);
    echo "published {$id} -> {$rk}\n";
}
