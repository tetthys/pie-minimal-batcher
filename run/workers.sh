#!/usr/bin/env bash
set -euo pipefail
# Launch 10 shard workers
for s in $(seq 0 9); do
  docker compose run -d --rm php bash -lc "php scripts/worker.php $s"
  echo "started shard-$s"
done
