# ─────────────────────────────────────────────────────────────────────────────
# Viteseo platform — PHP runtime image (tagged `viteseo-php:8.4`)
#
# ONE image serves BOTH PHP containers: the API (`php artisan serve`) and the
# broadcast server (`php artisan reverb:start`). They differ only by `command`
# in docker-compose.yml, never by image — an earlier split into a separate
# `viteseo-php:8.4-reverb` variant existed only as a locally built image and
# left the two runtimes silently drifting apart. Keep them on one image.
#
# The host's own PHP is 8.3 while composer.lock requires 8.4, so every artisan /
# composer command must run inside this image, never on the host.
# ─────────────────────────────────────────────────────────────────────────────

# Debian trixie. Pinned to the 8.4 line rather than a patch release because
# composer.lock's constraint is `^8.4`; last built against 8.4.23.
FROM php:8.4-cli

# System libraries are needed only to COMPILE the extensions below; the compiled
# .so files are what actually ship. `default-mysql-client` is kept deliberately —
# it provides the `mysql` CLI used for `artisan db` and manual DB inspection.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
        libicu-dev libxml2-dev libonig-dev \
        default-mysql-client \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql zip gd intl bcmath exif \
        # pcntl + sockets are REQUIRED BY REVERB, not optional niceties.
        # Without pcntl, `artisan reverb:start` aborts immediately with
        # "Undefined constant SIGINT" — the server installs POSIX signal
        # handlers so it can drain WebSocket connections on shutdown instead of
        # dropping them. Without sockets, the ReactPHP event loop cannot open
        # its listening stream. Neither is compiled into php:8.4-cli by default.
        pcntl sockets \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The application itself is NOT baked in — docker-compose.yml bind-mounts
# ./backend here so edits are live without a rebuild. Rebuild only when this
# file changes.
WORKDIR /app
