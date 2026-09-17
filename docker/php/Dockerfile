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

# ─────────────────────────────────────────────────────────────────────────────
# PYTHON, FOR THE DISCOVERY ENGINE ONLY.
#
# The scraper in ./scraper IS the discovery engine — Google Places, OpenStreetMap,
# the AI strategy client, partitioning and deduplication all live there. The
# queue worker invokes it as a subprocess rather than any of that logic being
# reimplemented in PHP, which would be a second engine to keep in step.
#
# `python3-venv` is separate from `python3` on Debian, and `pip` is needed to
# populate the venv. Nothing here touches the PHP extensions above.
# ─────────────────────────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-venv python3-pip \
 && rm -rf /var/lib/apt/lists/*

# ─────────────────────────────────────────────────────────────────────────────
# THE DISCOVERY ENGINE'S DEPENDENCIES, BAKED IN.
#
# These used to be installed at runtime into a named volume, which needed three
# undocumented manual steps on any new machine: chown the volume (Docker creates
# mountpoints as root, the containers run as uid 1000), create the venv, then pip
# install. None of that was expressed in the repository, so a fresh
# `git clone && docker compose up -d` produced a worker that started, polled, and
# failed the moment it invoked Python.
#
# Baking the venv removes all three. It is built as root and only ever READ at
# runtime — verified that uid 1000 can execute a root-owned venv — so ownership
# stops being a problem and the volume disappears entirely.
#
# ONE SOURCE OF TRUTH: scraper/requirements.txt is copied from the repository
# root rather than duplicated here, which is why the build context is the root
# (see .dockerignore — it allows exactly this file and the Dockerfile).
# Changing that manifest now correctly requires an image rebuild.
# ─────────────────────────────────────────────────────────────────────────────
COPY scraper/requirements.txt /tmp/scraper-requirements.txt
RUN python3 -m venv /opt/scraper-venv \
 && /opt/scraper-venv/bin/python -m pip install --no-cache-dir --upgrade pip \
 && /opt/scraper-venv/bin/python -m pip install --no-cache-dir -r /tmp/scraper-requirements.txt \
 && rm -f /tmp/scraper-requirements.txt \
 # World-readable/executable so the non-root runtime user can run it without
 # owning it. Nothing writes here at runtime.
 && chmod -R a+rX /opt/scraper-venv

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The application itself is NOT baked in — docker-compose.yml bind-mounts
# ./backend here so edits are live without a rebuild. Rebuild only when this
# file changes.
WORKDIR /app
