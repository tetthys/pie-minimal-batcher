# README — Usage & Code Examples

This document shows **how to run** the project with the provided scripts **and** how to use the **code** (framework-agnostic and Laravel).

---

## Shell Usage (Docker + Composer)

### 0) First run: bring up containers & install deps

```bash
bash run/up.sh
# Builds the PHP image, starts RabbitMQ + PHP containers,
# and runs `composer install` inside the PHP container.
```

### 1) Initialize RabbitMQ (least privilege)

```bash
bash run/init-rabbit.sh
# Creates /pie vhost and a least-privileged user (pie_user).
```

### 2) Run tests (Pest)

```bash
bash run/test.sh
# Ensures dependencies and then runs `composer test` inside the PHP container.
```

### 3) Start shard workers (parallel processing)

```bash
bash run/workers.sh
# Launches 10 shard workers in detached containers.
# Each worker runs: php scripts/worker.php <shardId>
```

### 4) Publish demo messages

```bash
bash run/publish-demo.sh        # default 50 messages
bash run/publish-demo.sh 200    # publish 200 messages
# Publishes messages with routing keys shard-0..shard-9 to RabbitMQ.
```

### 5) Tear down

```bash
bash run/down.sh
# Stops and removes containers, networks, and volumes.
```

### Composer (manual, inside container)

```bash
# Install dependencies
docker compose run --rm php bash -lc 'composer install --no-interaction'

# Run tests
docker compose run --rm php bash -lc 'composer test'
```

---

## Code Usage (Framework-agnostic PHP)

### Publish messages

```php
<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Tetthys\Pie\Infra\Rabbit\RabbitConnection;
use Tetthys\Pie\Infra\Rabbit\RabbitPublisher;

// 1) Connect (env or defaults)
$host  = getenv('PIE_RMQ_HOST') ?: 'rabbit';
$port  = (int)(getenv('PIE_RMQ_PORT') ?: 5672);
$user  = getenv('PIE_RMQ_USER') ?: 'pie_user';
$pass  = getenv('PIE_RMQ_PASS') ?: 'pie_pass';
$vhost = getenv('PIE_RMQ_VHOST') ?: '/pie';

// 2) Publisher to a direct exchange
$exchange = getenv('PIE_RMQ_EXCHANGE') ?: 'payout.direct';
$conn = new RabbitConnection($host, $port, $user, $pass, $vhost);
$pub  = new RabbitPublisher($conn, $exchange);

// 3) Simple shard function
$shardCount = 10;
$shardOf = static fn(string $identity): int => abs(crc32($identity)) % $shardCount;

// 4) Publish a message
$identity = 'ACC-12345';
$routingKey = 'shard-'.$shardOf($identity);

$msg = [
  'identity' => $identity,
  'payload'  => ['amount_minor' => 12500], // opaque bank-agnostic payload
  'uuid'     => bin2hex(random_bytes(8)),
];

$pub->publish($msg, $routingKey);
echo "Published to {$routingKey}\n";
```

### Run a worker (1 shard)

```php
<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Tetthys\Pie\Infra\Rabbit\RabbitConnection;
use Tetthys\Pie\Infra\Rabbit\RabbitConsumer;
use Tetthys\Pie\Support\SqliteCooldownRegistry;
use Tetthys\Pie\Support\SystemClock;
use Tetthys\Pie\Sink\FileLogSink;
use Tetthys\Pie\Worker\ShardedBatchWorker;

// 1) Dependencies
$shardId   = (int)($argv[1] ?? 0);
$host      = getenv('PIE_RMQ_HOST') ?: 'rabbit';
$port      = (int)(getenv('PIE_RMQ_PORT') ?: 5672);
$user      = getenv('PIE_RMQ_USER') ?: 'pie_user';
$pass      = getenv('PIE_RMQ_PASS') ?: 'pie_pass';
$vhost     = getenv('PIE_RMQ_VHOST') ?: '/pie';
$exchange  = getenv('PIE_RMQ_EXCHANGE') ?: 'payout.direct';
$prefetch  = (int)(getenv('PIE_RMQ_PREFETCH') ?: 128);
$windowSec = (int)(getenv('PIE_WINDOW_SEC') ?: 3600);

// 2) Compose components
$clock = new SystemClock();
$registry = new SqliteCooldownRegistry(__DIR__.'/var/cooldowns.sqlite');

$conn = new RabbitConnection($host, $port, $user, $pass, $vhost);
$queue = "payout.shard.$shardId";
$rkey  = "shard-$shardId";
$consumer = new RabbitConsumer($conn, $exchange, $queue, $rkey, $prefetch, false);

// Minimal sink example (NDJSON with batch metadata)
$sink = new FileLogSink(__DIR__."/var/out_shard_{$shardId}.ndjson");

// 3) Worker
$worker = new ShardedBatchWorker($shardId, $consumer, $sink, $registry, $clock, $windowSec);

// 4) Loop
while (true) {
    $worker->tick();       // drain messages, flush on window boundary
    usleep(1000 * 200);    // small sleep to avoid a hot loop
}
```

---

## Code Usage (Laravel)

> Register the provider once, then use the `Pie` facade.

### Register provider

```php
// config/app.php
'providers' => [
    // ...
    Tetthys\Pie\Laravel\PieServiceProvider::class,
],
```

### Use the facade

```php
<?php
// e.g., in a controller/command/job
use Tetthys\Pie\Laravel\Facades\Pie;

// Publish a message
Pie::publish([
  'identity' => 'ACC-12345',
  'payload'  => ['amount_minor' => 12500],
  'uuid'     => bin2hex(random_bytes(8)),
], 'shard-3');

// Set per-identity cooldown (e.g., 1 hour)
Pie::setCooldown('ACC-12345', time() + 3600);

// Tick a shard worker once (cron/scheduler)
Pie::tick(3);
```

> For long-running workers, create an Artisan command that calls `Pie::tick($shardId)` in a loop, similar to the framework-agnostic example.
