#!/usr/bin/env bash
set -euo pipefail
docker compose run --rm php bash -lc 'composer install --no-interaction && composer test'
