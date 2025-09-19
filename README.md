# pie-minimal-batcher

A **minimal PHP batching system** built on RabbitMQ.
It shards messages across workers, applies per-identity cooldowns, and flushes batches on fixed-time windows.

Ideal for payout / withdrawal pipelines or any workload that benefits from **sharded batch processing with cooldown logic**.

---

## ✨ Features

* **RabbitMQ publisher & consumer**

  * Direct exchange: `payout.direct`
  * Shard queues: `payout.shard.{id}`
  * Publisher confirms enabled (guaranteed delivery)
  * Optional quorum queues for durability

* **Worker**

  * Processes 1 shard at a time
  * Drains up to 256 msgs per tick
  * Filters by cooldown (per identity)
  * Flushes only on **fixed window rotation**
  * Custom sinks via `BatchSinkInterface`

* **Cooldown Registry**

  * Backed by SQLite (`pdo_sqlite`)
  * Expired entries auto-cleaned

* **Laravel Integration**

  * `PieServiceProvider` + `Pie` facade
  * `publish()`, `setCooldown()`, `tick()` exposed
  * Uses `storage/app/pie` for state & output

* **Dockerized**

  * Comes with `docker-compose.yaml`
  * Scripts for RabbitMQ setup, worker launch, demo publishing

---

## 🚀 Quickstart (Docker)

1. **Start services & install deps**

   ```bash
   bash run/up.sh
   ```

2. **Initialize RabbitMQ**

   ```bash
   bash run/init-rabbit.sh
   ```

   Creates `/pie` vhost and `pie_user/pie_pass` with limited rights.

3. **Start 10 shard workers**

   ```bash
   bash run/workers.sh
   ```

4. **Publish demo messages**

   ```bash
   bash run/publish-demo.sh        # 50 messages
   bash run/publish-demo.sh 200    # 200 messages
   ```

5. **Check outputs**
   Look in `var/out_shard_*.ndjson` for summaries like:

   ```json
   {"shard":3,"window_start":1700000000,"window_sec":3600,"count":42}
   ```

---

## 📦 Usage in Your Own Code

### Publisher

```php
$conn = new RabbitConnection($host, $port, $user, $pass, $vhost);
$pub  = new RabbitPublisher($conn, 'payout.direct');

$identity = 'ACC-12345';
$shard = abs(crc32($identity)) % 10;

$pub->publish([
  'identity' => $identity,
  'payload'  => ['amount_minor' => 12500],
  'uuid'     => bin2hex(random_bytes(8)),
], "shard-$shard");
```

### Worker

```php
$consumer = new RabbitConsumer($conn, 'payout.direct', "payout.shard.$id", "shard-$id", 100, false);
$sink     = new FileLogSink(__DIR__."/var/out_shard_{$id}.ndjson");
$cool     = new SqliteCooldownRegistry(__DIR__.'/var/cooldowns.sqlite');
$worker   = new ShardedBatchWorker($id, $consumer, $sink, $cool, new SystemClock(), 3600);

while (true) {
    $worker->tick();
    usleep(100 * 1000); // adjust polling interval
}
```

---

## 🕊 Laravel Integration

1. Register the provider:

   ```php
   // config/app.php
   'providers' => [
       Tetthys\Pie\Laravel\PieServiceProvider::class,
   ],
   ```

2. Use the facade:

   ```php
   use Tetthys\Pie\Laravel\Facades\Pie;

   // publish a message
   Pie::publish([
     'identity' => 'ACC-12345',
     'payload'  => ['amount_minor' => 12500],
     'uuid'     => bin2hex(random_bytes(8)),
   ], 'shard-3');

   // set a cooldown (1h)
   Pie::setCooldown('ACC-12345', time() + 3600);

   // tick a shard worker
   Pie::tick(3);
   ```

3. Long-running workers
   Wrap `Pie::tick($shardId)` in an Artisan command loop and run it under Supervisor/systemd.

---

## ⚠ Notes & Caveats

* **Prefetch setting**: current consumer uses `basic_get` (polling). Prefetch has **no effect** unless converted to `basic_consume`.
* **Cooldown storage**:

  * Standalone: `var/cooldowns.sqlite`
  * Laravel: `storage/app/pie/cooldowns.sqlite`
* **Polling delay**: workers use `usleep(100–200ms)`. Adjust to balance throughput vs latency.
* **Message size**: publisher enforces **64KB limit**. Store large payloads externally.
* **Sharding**: always hash by `identity` so cooldown guarantees are shard-local.

---

## ✅ Best Practice Flow

1. **Publish** messages with identity-based shard routing.
2. **Worker** batches per shard, flushes on window boundary.
3. **Sink** executes the real action (e.g., payout API call).
4. **Cooldown** is set per identity to avoid duplicate handling.