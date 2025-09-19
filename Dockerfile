# syntax=docker/dockerfile:1
FROM php:8.3-cli

# --- System packages for pdo_sqlite build ---
# We need libsqlite3-dev to compile the pdo_sqlite extension.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev ca-certificates git unzip \
 && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ---
# 1) Enable sockets for php-amqplib
# 2) Build pdo_sqlite (pdo core is built-in; no need to install 'pdo')
RUN docker-php-ext-install sockets \
 && docker-php-ext-configure pdo_sqlite --with-pdo-sqlite=/usr \
 && docker-php-ext-install pdo_sqlite

# --- Composer v2 ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
# (Optional) copy composer manifests first for layer caching
COPY composer.json composer.lock* ./

# Then copy the rest of the sources
COPY . .

# Run as non-root for basic hardening
RUN useradd -m -u 1000 appuser
USER appuser

CMD ["php", "-v"]
