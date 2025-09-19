#!/usr/bin/env bash
set -euo pipefail
N=${1:-50}
docker compose run --rm php bash -lc "php scripts/publish.php $N"
