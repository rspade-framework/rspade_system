# =============================================================================
# RSpade application container
#
# Three targets from one file. Two of them share every base layer:
#
#   --target dev    a complete development environment: MySQL inside the
#                   container, a Docker Engine for the test runner, and OPcache
#                   set to notice file changes immediately.
#
#   --target prod   the same application runtime with no MySQL SERVER (bring
#                   your own), no test tooling, and OPcache holding its
#                   compiled state for 10 seconds instead of re-checking every
#                   request.
#
# The third one shares nothing, on purpose:
#
#   --target docconvert   the document sandbox image - soffice, pdftotext and
#                   fonts on a bare distribution base, no application runtime at
#                   all. It is the machine a hostile uploaded document is handed,
#                   so it carries as little as the conversion allows. The
#                   reasoning is written beside the stage.
#
# The application itself is NOT baked in - it is mounted at /var/www/html. One
# image serves every project.
#
# ARCHITECTURE: authored to build on amd64 and arm64 alike. Everything installs
# through apt, a distribution setup script, or a package manager that picks the
# build's architecture itself (Playwright, pip wheels); the one direct download is
# architecture-independent model data. Nothing downloads an architecture-pinned
# binary, and the base image is pinned by TAG (a single-arch digest would defeat
# this).
# =============================================================================

# -----------------------------------------------------------------------------
# base - the application runtime, shared by both targets
# -----------------------------------------------------------------------------
FROM ubuntu:24.04 AS base

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    PHP_VERSION=8.5

# tzdata configures itself non-interactively from this file, so it is written
# BEFORE the first apt-get - otherwise the install stops on a region prompt.
RUN echo "$TZ" > /etc/timezone

# OS packages. Grouped by why they are here, because an unexplained package list
# is a list nobody can ever prune.
RUN apt-get update && apt-get install -y \
    # web server, cache, process supervision
        nginx libnginx-mod-http-brotli-filter redis-server redis-tools supervisor \
    # automatic security updates at start and every 4 hours (rspade-security-update):
    # Ubuntu's own tool, held to the allow-list in apt/52rspade-security-updates
        unattended-upgrades \
    # talking to a database (the SERVER is a dev-target concern)
        mysql-client \
    # fetching and unpacking. No software-properties-common: it exists to provide
    # add-apt-repository, and the PHP apt source is now shipped as a file instead.
        ca-certificates curl wget gnupg2 unzip \
    # reading JSON from the shell (health output, API answers): every image, not just dev
        jq \
    # image handling: thumbnails, uploads, optimisation
        imagemagick ghostscript optipng \
    # HEIC/HEIF photos (a phone's default format): libheif decodes them for ImageMagick
    # only with an HEVC decoder plugin, which imagemagick does not pull in - without it
    # a HEIC upload reads as "Unsupported codec" and has no size, thumbnail or face
        libheif-plugin-libde265 \
    # video metadata: ffprobe (in ffmpeg) reads an uploaded video's width, height,
    # duration and rotation. ImageMagick has no video coder; without it a video keeps
    # no size or duration and rsx:health's ffprobe row fails
        ffmpeg \
    # document preview and text extraction - RUNTIME features, both targets
        libreoffice poppler-utils fonts-liberation fonts-dejavu-core \
    # Playwright's headless browser dependencies - the shared libraries Chromium
    # links. BASE layer, not dev, and it STAYS in prod: the browser is not only how
    # `php artisan rsx:debug /path` and the browser tests render a page, it is how
    # an APPLICATION makes a PDF on the server (an invoice, a statement) - the
    # `playwright` package the framework ships drives the same Chromium, and a PDF
    # printed by it keeps every <a href> as a link. Removing these from the prod
    # target breaks PDF generation downstream, with nothing here to say why.
        libnss3 libnspr4 libatk-bridge2.0-0 libatspi2.0-0 libdrm2 libgbm1 \
        libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
        libxcursor1 libasound2t64 \
    # one font set for everything a server draws - a browser-printed PDF is set in
    # the SERVER's fonts, so both targets carry the same faces and a document made
    # in development is the document made in production. Chromium prints generic
    # sans-serif and serif in Liberation Sans / Serif and monospace in DejaVu Sans
    # Mono (above); Noto supplies the scripts and symbols those do not cover.
        fontconfig fonts-noto-core \
    # the framework shells out to these. openssh-client is git's transport for an
    # ssh:// or git@host: remote - git only RECOMMENDS it, so it is named here
    # rather than left to be pulled in by something else.
        git openssh-client rsync procps less nano \
    # bin/mysqlpv, the dump/restore pipe viewer. BASE layer, not dev: the schema-cache
    # RESTORE runs on `migrate` in every target, not only in development. Its absence
    # degrades to a silent pipeline rather than a failure, but a progress-less restore of
    # a large database is exactly the wait an operator should not have to sit through
    # blind. python3 (not python3-minimal) - the script needs re and shutil.
        python3 \
    # the face detector's private virtualenv (/opt/rspade/face_detection/venv, below):
    # `python3 -m venv` needs ensurepip, which Ubuntu ships in this package
        python3-venv \
    && rm -rf /var/lib/apt/lists/*

# PHP from the ondrej PPA, which is where a current PHP for Ubuntu lives.
#
# THE APT SOURCE IS SHIPPED, NOT RESOLVED AT BUILD TIME. This used to be
# `add-apt-repository -y ppa:ondrej/php`, which asks LAUNCHPAD'S API to resolve the
# PPA and hand back its signing key - so every build of this image depended on that
# API being healthy at that moment. It is not always: a build failed here with
# Launchpad returning `GPGKeyTemporarilyNotFoundError` (2026-08-24), minutes after
# an identical build had downloaded packages from the same archive perfectly well.
#
# php/ondrej-php.sources is that PPA in deb822 form with the signing key inline
# (B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6, "Launchpad PPA for Ondrej Sury"). The
# ARCHIVE host is unchanged - ppa.launchpadcontent.net, the same place the packages
# always came from - so this removes one third-party service from the build without
# changing where a single byte originates.
#
# NOTE the suite is pinned to `noble` inside that file, matching this image's
# ubuntu:24.04 base. Changing the base image means changing the suite too, and apt
# will say so loudly rather than silently installing nothing.
#
# NOTE: no php${PHP_VERSION}-opcache. OPcache is BUILT INTO the PHP 8.5 core and
# sury publishes no such package - every release from 5.6 to 8.4 has one, 8.5 does
# not. Listing it fails the whole apt-get and breaks the build. It is present:
# `php -m` reports Zend OPcache without it.
# The extension list below is the COUNTERPART of
# App\RSpade\Lib\Operations\Health\Rsx_Php_Requirements::REQUIRED_EXTENSIONS, which every
# request and every artisan run enforces at boot. Adding an extension there means
# adding its package here (and vice versa is how an image grows dead weight).
#
# TWO DECLARED EXTENSIONS HAVE NO LINE HERE, ON PURPOSE:
#   sodium - compiled into sury's PHP binary; no php${PHP_VERSION}-sodium package
#            exists, so naming one fails the whole apt-get exactly like -opcache above.
#   pcntl  - shipped inside php${PHP_VERSION}-cli and deliberately absent from php-fpm
#            (forking an fpm worker corrupts the pool's sockets and DB connections).
#            It is the CLI tier, REQUIRED_CLI_EXTENSIONS, enforced only under PHP_SAPI
#            'cli'.
# `rsx:man health` -> PHP EXTENSION CONTRACT.
#
# SECURITY FLOOR. PHP is unpinned within ${PHP_VERSION}, so a fresh install takes the
# newest patch - but Docker reuses the cached install layer until a line above it
# changes. When a PHP security release must reach every image, change the cachebreak
# line (forcing a fresh install) AND raise PHP_MIN_PATCHED (the build then refuses a
# mirror that has not published the release yet, rather than shipping the old patch).
ARG PHP_MIN_PATCHED=8.5.12
RUN echo "cachebreak: force PHP upgrade to 8.5.12 or newer (2026-10-21 security release, CVE-2026-109618 / CVE-2026-109619)"
COPY php/ondrej-php.sources /etc/apt/sources.list.d/ondrej-php.sources
RUN apt-get update && apt-get install -y \
        php${PHP_VERSION} \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-cli \
        php${PHP_VERSION}-common \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-redis \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-gd \
        php${PHP_VERSION}-imagick \
        php${PHP_VERSION}-intl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-zip \
        php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-gmp \
        php${PHP_VERSION}-imap \
        php${PHP_VERSION}-ldap \
        php${PHP_VERSION}-soap \
        php${PHP_VERSION}-zstd \
    && update-alternatives --set php /usr/bin/php${PHP_VERSION} \
    && rm -rf /var/lib/apt/lists/*

RUN php -r 'exit(version_compare(PHP_VERSION, getenv("PHP_MIN_PATCHED"), ">=") ? 0 : 1);' \
    || { echo "PHP $(php -r 'echo PHP_VERSION;') is below the security floor ${PHP_MIN_PATCHED}: the PHP package mirror has not published the release yet - rebuild once it has." >&2; exit 1; }

# Composer. Verified against the installer's published signature - an unverified
# curl-to-php of a build tool is how a supply chain gets compromised.
RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)" \
    && ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")" \
    && [ "$EXPECTED" = "$ACTUAL" ] \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php

# Node. The framework's own helpers are node: the realtime relay, the full-page
# cache proxy, the lock daemon, and the parser/transform RPC workers.
#
# BOTH ARE PINNED to exact versions: node from NodeSource's apt repository (the
# setup script adds the major line's repository; the install names the exact build),
# npm from the registry. Node 26 is the current LTS line; npm 12 requires node
# ^22.22.2 || ^24.15.0 || >=26. Bumping either is a deliberate edit here, followed by
# a test build and a framework suite run on the new image before it is published.
ARG NODE_VERSION=26.13.1
ARG NPM_VERSION=12.2.3
RUN curl -fsSL "https://deb.nodesource.com/setup_${NODE_VERSION%%.*}.x" | bash - \
    && apt-get update && apt-get install -y "nodejs=${NODE_VERSION}-1nodesource1" \
    && npm install -g "npm@${NPM_VERSION}" \
    && [ "$(node -v)" = "v${NODE_VERSION}" ] \
    && [ "$(npm -v)" = "${NPM_VERSION}" ] \
    && rm -rf /var/lib/apt/lists/* /root/.npm

# Headless Chromium, the browser Playwright drives. BOTH targets, production
# included: it is how `rsx:debug` and the browser tests render a page, and it is how
# an APPLICATION prints a PDF on the server through the `playwright` package the
# framework ships. It is not a test-only dependency - leave it out of prod and
# PDF generation downstream stops working.
#
# THE VERSION IS THE FRAMEWORK'S. A Playwright package launches exactly the browser
# build it was released with, so this pin equals the version of the `playwright`
# package in system/node_modules (a framework test compares the two). Updating that
# package means updating this number and rebuilding the image; a box on an older
# image runs `npx playwright install chromium` to fetch the build it is missing.
#
# ONE COPY, FOUND BY EVERYONE. The browsers live in /opt/ms-playwright and
# PLAYWRIGHT_BROWSERS_PATH says so to every process that inherits the container's
# environment. PHP-FPM clears its workers' environment, so a browser started from a
# web request never sees that variable - which is why the per-user default
# locations for root (development PHP) and www-data (production PHP) are links to
# the same directory. `install chromium` fetches Chromium and its headless shell for
# the architecture being built; nothing here names one.
ARG PLAYWRIGHT_VERSION=1.64.0
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright
RUN npx --yes playwright@${PLAYWRIGHT_VERSION} install chromium \
    && chmod -R a+rX /opt/ms-playwright \
    && mkdir -p /root/.cache /var/www/.cache \
    && ln -sfn /opt/ms-playwright /root/.cache/ms-playwright \
    && ln -sfn /opt/ms-playwright /var/www/.cache/ms-playwright \
    && rm -rf /root/.npm

# FACE DETECTION for face-aware cropped thumbnails: OpenCV's YuNet face detector, run by
# face_detection/detect_face.py (COPY'd with the other /opt/rspade components below) and
# called by the thumbnailer the first time a face crop of an image is asked for. BOTH
# targets: thumbnails are a runtime feature.
#
# A PRIVATE VIRTUALENV, not the system python3: pip is never pointed at the distribution's
# own site-packages, and nothing else in the image sees these packages. The caller runs
# /opt/rspade/face_detection/venv/bin/python by path.
#
# PINNED. opencv-python-headless (Apache-2.0; "headless" = no GUI libraries) and numpy, its
# one dependency, are exact versions published as manylinux wheels for x86_64 and aarch64
# alike, so pip resolves the right binary for the architecture being built and compiles
# nothing. Changing either is a deliberate edit here.
#
# THE MODEL IS DOWNLOADED, NOT COMMITTED, and checked against its sha256 - a mismatch
# (a changed or truncated file upstream) fails the build rather than shipping a detector
# that answers differently. YuNet (MIT) comes from the OpenCV model zoo.
#
# These layers sit here, ahead of the configuration and service files below, because they
# are slow and change almost never: an ordinary framework change does not rebuild them.
ARG OPENCV_HEADLESS_VERSION=4.14.0.94
ARG NUMPY_VERSION=2.5.4
ARG YUNET_URL=https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx
ARG YUNET_SHA256=8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4
RUN python3 -m venv /opt/rspade/face_detection/venv \
    && /opt/rspade/face_detection/venv/bin/pip install --no-cache-dir \
        "opencv-python-headless==${OPENCV_HEADLESS_VERSION}" "numpy==${NUMPY_VERSION}" \
    && /opt/rspade/face_detection/venv/bin/python -c 'import cv2; cv2.FaceDetectorYN' \
    && curl -fsSL "$YUNET_URL" -o /opt/rspade/face_detection/face_detection_yunet_2023mar.onnx \
    && echo "${YUNET_SHA256}  /opt/rspade/face_detection/face_detection_yunet_2023mar.onnx" | sha256sum -c - \
    && chown -R root:root /opt/rspade/face_detection \
    && chmod 755 /opt/rspade/face_detection \
    && chmod 644 /opt/rspade/face_detection/face_detection_yunet_2023mar.onnx

# Service configuration. Copied in one layer; the per-target OPcache policy and
# supervisor programs are layered on top by the targets below.
COPY nginx/nginx.conf              /etc/nginx/nginx.conf
COPY nginx/site.conf               /etc/nginx/sites-enabled/default
COPY php/php.ini                   /etc/php/${PHP_VERSION}/fpm/php.ini
COPY php/www.conf                  /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY redis/redis.conf              /etc/redis/redis.conf
COPY supervisor/supervisord.conf   /etc/supervisor/supervisord.conf
COPY supervisor/conf.d/nginx.conf      /etc/supervisor/conf.d/nginx.conf
COPY supervisor/conf.d/php-fpm.conf    /etc/supervisor/conf.d/php-fpm.conf
COPY supervisor/conf.d/redis.conf      /etc/supervisor/conf.d/redis.conf
# supervisor/conf.d/fpc-proxy.conf is NOT installed: full page caching is disabled
# (Rsx_FPC::ENABLED, backlog B-132) and the full-page cache proxy is in no request path.
# Proxority is: every PHP-bound request nginx receives goes through it (nginx/site.conf),
# so without this program nginx answers 502 for every page.
COPY supervisor/conf.d/proxority.conf  /etc/supervisor/conf.d/proxority.conf
COPY supervisor/conf.d/realtime.conf   /etc/supervisor/conf.d/realtime.conf
COPY supervisor/conf.d/rsx-lockd.conf  /etc/supervisor/conf.d/rsx-lockd.conf
COPY supervisor/conf.d/rsx-companiond.conf /etc/supervisor/conf.d/rsx-companiond.conf
COPY supervisor/conf.d/tasks.conf      /etc/supervisor/conf.d/tasks.conf
COPY supervisor/conf.d/security-updates.conf /etc/supervisor/conf.d/security-updates.conf
# The log echo onto `docker logs` (rspade-log-to-docker); idle unless RSPADE_LOG_TO_DOCKER=1.
COPY supervisor/conf.d/log-to-docker.conf /etc/supervisor/conf.d/log-to-docker.conf
# AUTOMATIC SECURITY UPDATES. What unattended-upgrades may install: the Ubuntu security
# pocket and PHP patch releases from the ondrej PPA (rspade-security-update says why and
# when). Runs before PHP serves anything at every start and every 4 hours after; off with
# PHP_SECURITY_UPDATES=0 in the container's environment.
COPY apt/52rspade-security-updates /etc/apt/apt.conf.d/52rspade-security-updates
# ImageMagick reads raster coders only: its SVG/MVG/MSL/TEXT/LABEL coders turn an uploaded
# file into a local-file read. rsx:health "ImageMagick Coder Policy" FAILs without this.
COPY imagemagick/policy.xml        /etc/ImageMagick-6/policy.xml
# THE .PHP URL POLICY (nginx/site.conf's header): every `location ~ \.php$` includes
# /etc/nginx/rspade/php-urls.conf, which the entrypoint (step 0) overwrites at every start
# with the checkout or the no-checkout variant. The checkout variant (refuse) is also baked
# in as the active one, so nginx's configuration is complete before any entrypoint runs.
COPY nginx/php-urls-checkout.conf    /etc/nginx/rspade/php-urls-checkout.conf
COPY nginx/php-urls-no-checkout.conf /etc/nginx/rspade/php-urls-no-checkout.conf
COPY nginx/php-urls-checkout.conf    /etc/nginx/rspade/php-urls.conf

# Normalise permissions on everything copied above. The source tree these files
# come from is world-writable (a legacy of the chmod-777 arrangement this image
# replaces) and COPY preserves the source mode - so without this, mysqld.cnf
# arrives as 0777 and MySQL SILENTLY IGNORES IT: "World-writable config file is
# ignored", with every setting in it quietly not applied.
RUN chmod 644 /etc/nginx/nginx.conf \
              /etc/nginx/sites-enabled/default \
              /etc/php/${PHP_VERSION}/fpm/php.ini \
              /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
              /etc/redis/redis.conf \
              /etc/supervisor/supervisord.conf \
              /etc/supervisor/conf.d/*.conf \
              /etc/apt/apt.conf.d/52rspade-security-updates \
              /etc/ImageMagick-6/policy.xml \
              /etc/nginx/rspade/*.conf

# PROXORITY, the fair-queueing proxy in front of php-fpm, is part of the IMAGE: its one copy
# of source is resource/docker/proxority/ (the build context), installed here at
# /opt/rspade/proxority/ and run by supervisor as `bash /opt/rspade/proxority/proxority-run.sh`.
# So it runs whether or not an RSpade checkout is mounted - nginx sends PHP-bound requests
# through it either way. It reads nothing from a checkout but its whitelist refresh
# (`php /var/www/html/system/artisan rsx:proxority:routes --json`), which without one fails
# each interval and keeps the empty list. Node core only - no npm packages, nothing installed
# at build or at start; it runs on the node installed above. Root-owned and world-readable;
# the run script is invoked through bash, so nothing here needs an exec bit.
# /usr/local/bin/proxority is the operator's command: `proxority status`, `proxority log`.
COPY proxority/ /opt/rspade/proxority/
COPY proxority-cli.sh /usr/local/bin/proxority
RUN chown -R root:root /opt/rspade/proxority \
    && find /opt/rspade/proxority -type d -exec chmod 755 {} + \
    && find /opt/rspade/proxority -type f -exec chmod 644 {} + \
    && chmod 755 /usr/local/bin/proxority

# rspade-taskd - the task scheduler: it runs the task tick (php artisan rsx:task:process) at the
# moment the last tick said work comes due, and when the application pokes it, instead of once
# a minute. Source: resource/docker/rspade-taskd/, installed at /opt/rspade/rspade-taskd/ and run
# by supervisor as the `tasks` program (supervisor/conf.d/tasks.conf). Like Proxority it is part
# of the image and starts with no checkout mounted. /usr/local/bin/rspade-taskd is the
# operator's command: `rspade-taskd status`, `rspade-taskd poke`.
COPY rspade-taskd/ /opt/rspade/rspade-taskd/
COPY rspade-taskd-cli.sh /usr/local/bin/rspade-taskd
RUN chown -R root:root /opt/rspade/rspade-taskd \
    && find /opt/rspade/rspade-taskd -type d -exec chmod 755 {} + \
    && find /opt/rspade/rspade-taskd -type f -exec chmod 644 {} + \
    && chmod 755 /usr/local/bin/rspade-taskd

# rsx-lockd - the cluster lock daemon every application lock is granted by. Source:
# resource/docker/rsx-lockd/, installed at /opt/rspade/rsx-lockd/ and run by supervisor as
# `bash /opt/rspade/rsx-lockd/lockd-run.sh`, so a change to it ships with the image. It serves
# the project at /var/www/html and reads that project's .env (APP_KEY, the storage roots,
# through system/bin/lib/rsx_paths.js) - with no checkout it has no key, fails loudly, and the
# retry runner tries again. Node core only, nothing installed. Root-owned, world-readable.
COPY rsx-lockd/ /opt/rspade/rsx-lockd/
RUN chown -R root:root /opt/rspade/rsx-lockd \
    && find /opt/rspade/rsx-lockd -type d -exec chmod 755 {} + \
    && find /opt/rspade/rsx-lockd -type f -exec chmod 644 {} +

# The face detector script (face_detection/detect_face.py; its header holds the contract),
# beside the virtualenv and model installed above. Run as
# `/opt/rspade/face_detection/venv/bin/python /opt/rspade/face_detection/detect_face.py
# <model> <image>`, so it needs no exec bit. Root-owned, world-readable.
COPY face_detection/detect_face.py /opt/rspade/face_detection/detect_face.py
RUN chown root:root /opt/rspade/face_detection/detect_face.py \
    && chmod 644 /opt/rspade/face_detection/detect_face.py

COPY entrypoint.sh     /usr/local/bin/rspade-entrypoint
COPY realtime-run.sh   /usr/local/bin/rspade-realtime-run
COPY retry-run.sh      /usr/local/bin/rspade-retry-run
COPY healthcheck.sh    /usr/local/bin/rspade-healthcheck
# The startup record the entrypoint writes, and the command that waits on it:
# `docker compose exec <service> rspade-wait-started` returns once the start has finished
# (startup-state.sh's header has the contract).
COPY startup-state.sh  /usr/local/bin/rspade-startup
COPY wait-started.sh   /usr/local/bin/rspade-wait-started
COPY sizing.sh         /usr/local/bin/rspade-sizing
COPY trusted-proxies.sh /usr/local/bin/rspade-trusted-proxies
COPY security-update.sh /usr/local/bin/rspade-security-update
COPY security-updates-run.sh /usr/local/bin/rspade-security-updates-run
COPY log-to-docker.sh  /usr/local/bin/rspade-log-to-docker
COPY claude-run.sh     /usr/local/bin/claude

# git IS the RSpade git proxy in this container.
#
# /usr/local/bin precedes /usr/bin on PATH, so `git` routes through
# `php artisan rsx:git` without anyone having to remember to type that. The real
# binary stays at /usr/bin/git, and the shim steps aside for it whenever the
# proxy is not wanted - see the script's own header for the three cases.
#
# This is a CONTAINER-only arrangement. Taking over `git` machine-wide on
# somebody's own server would be presumptuous; inside an image that exists to run
# one project, it is just the project's git.
COPY git-shim.sh       /usr/local/bin/git

# npm, same arrangement and for the same reason: the project has TWO npm layers
# (the framework's inside system/, the application's at the project root) and bare
# npm silently gets the second one half-right. The shim routes to rsx:npm, which
# knows the difference - and steps aside inside system/, where framework
# dependency work legitimately uses plain npm. See the script's header.
COPY npm-shim.sh       /usr/local/bin/npm

# 755, not +x: the source tree these come from is world-writable, and `chmod +x`
# would preserve that - a world-WRITABLE executable on PATH is a different thing
# entirely from an executable one.
RUN chmod 755 /usr/local/bin/rspade-entrypoint \
              /usr/local/bin/rspade-realtime-run \
              /usr/local/bin/rspade-retry-run \
              /usr/local/bin/rspade-healthcheck \
              /usr/local/bin/rspade-startup \
              /usr/local/bin/rspade-wait-started \
              /usr/local/bin/rspade-sizing \
              /usr/local/bin/rspade-trusted-proxies \
              /usr/local/bin/rspade-security-update \
              /usr/local/bin/rspade-security-updates-run \
              /usr/local/bin/rspade-log-to-docker \
              /usr/local/bin/claude \
              /usr/local/bin/git \
              /usr/local/bin/npm

# Runtime directories the services expect to exist before they start.
RUN mkdir -p /run/php /var/log/supervisor /var/run/redis /var/log/redis /var/lib/redis /var/lib/rspade \
    && chown -R redis:redis /var/run/redis /var/log/redis /var/lib/redis \
    && chmod 755 /var/lib/rspade

# RESOURCE SIZING. The entrypoint rewrites these at every start (rspade-sizing, which
# holds the reasoning): the Redis cap redis.conf includes, and the memory_limit php-fpm and
# the CLI share. The values baked here are what a PHP run DURING THE BUILD gets, and what
# Redis would start with if it were ever started without the entrypoint; each target bakes
# its own default limit below.
RUN printf '%s\n' 'maxmemory 64mb' > /etc/redis/rspade-memory.conf \
    && chmod 644 /etc/redis/rspade-memory.conf

# The RSpade container marker.
#
# /.dockerenv only says "some container". This says "the container RSpade's own
# tooling was written for" - one that runs supervisor, with the service names and
# the data-directory layout that things like the migrate snapshot depend on.
# Somebody running RSpade in a container they built themselves would satisfy
# /.dockerenv and none of those assumptions, so the operations that need them
# check for THIS file and refuse rather than half-work.
#
# Rsx::is_rspade_container() is the reader.
RUN printf '%s\n' \
    "# This is an RSpade application container." \
    "# Framework tooling that drives supervisor, the bundled database or the" \
    "# container's service layout checks for this file before acting." \
    "target=${RSPADE_CONTAINER_TARGET:-base}" \
    > /.rspade_container

# The project is a bind mount owned by whoever owns it on the host, which is
# almost never the uid running in here. Git refuses to operate on a repository
# owned by somebody else ("dubious ownership") - a sensible default on a shared
# machine, and pure obstruction inside a single-tenant container where that
# mismatch is the NORMAL case and every alternative is worse. Scoped to this
# image; it says nothing about the host's git.
RUN git config --global --add safe.directory '*'

WORKDIR /var/www/html
EXPOSE 80 8000

# Unhealthy when any supervised service is failing: a runner reporting five or more
# consecutive exits, a supervisor program FATAL / BACKOFF / EXITED / UNKNOWN, or rsx-lockd
# not answering a ping. Healthy under maintenance mode and with no checkout mounted. The
# script's header holds the reasoning. Docker's own interval, timeout and retries apply.
# It only reports: nothing restarts an unhealthy container.
HEALTHCHECK CMD ["bash", "/usr/local/bin/rspade-healthcheck"]

# bash explicitly, never the exec bit: downstream checkouts carry unreliable
# permission bits and a bare path dies with "Permission denied".
ENTRYPOINT ["bash", "/usr/local/bin/rspade-entrypoint"]

# -----------------------------------------------------------------------------
# dev - MySQL in the container, browser testing, live code reloading
# -----------------------------------------------------------------------------
FROM base AS dev

ENV RSPADE_CONTAINER_TARGET=dev

# Two markers, and the second one matters: /.rspade_container says "an RSpade
# container", this says "the DEVELOPMENT one". They gate different things -
# the migrate snapshot runs in both targets, but only the dev container throws
# its snapshot away afterwards. On a production container the snapshot is the
# thing you would actually want to still have.
RUN sed -i "s/^target=.*/target=dev/" /.rspade_container \
    && printf '%s\n' "# This is the RSpade DEVELOPMENT container." > /.rspade_container_dev

RUN apt-get update && apt-get install -y \
    # the database, for a self-contained development environment
        mysql-server \
    # the development mail sink: an SMTP-only catcher, no web UI
    # (supervisor/conf.d/mail-catcher.conf says why not Mailpit)
        python3-aiosmtpd \
    # developer quality of life for anyone who shells in
        fzf man-db gh \
    && rm -rf /var/lib/apt/lists/*

# MySQL is held back from the automatic security updates here: its package scripts run
# maintenance on the data directory, which a migrate may be snapshotting.
COPY apt/53rspade-security-updates-dev /etc/apt/apt.conf.d/53rspade-security-updates-dev
RUN chmod 644 /etc/apt/apt.conf.d/53rspade-security-updates-dev

# -----------------------------------------------------------------------------
# No Docker Engine in this image
# -----------------------------------------------------------------------------
#
# This image deliberately does NOT install Docker or run a dockerd program.
# `rsx:test`'s parallel mode (sibling test containers) is a framework-AUTHOR
# tool: only the framework's own development container runs a nested engine,
# and that container provides it itself (its host-side build adds Docker Engine
# and starts the container with the capabilities nested docker needs). An
# application's development container has no use for one, and shipping an engine
# that cannot start without extra host capabilities only produced an EXITED
# supervisor program and an unhealthy container. Without a daemon, `rsx:test`'s
# gate finds none and runs sequentially (`rsx:man testing`).

COPY mysql/mysqld.cnf   /etc/mysql/mysql.conf.d/mysqld.cnf
COPY mysql/provision.sql /opt/rspade/provision.sql
RUN chmod 644 /etc/mysql/mysql.conf.d/mysqld.cnf /opt/rspade/provision.sql

# A pristine copy of the initialised data directory.
#
# WHY THIS IS BACK. It existed once, and was removed as redundant when the
# database lived in a NAMED VOLUME: Docker copies the image's own directory
# content into a named volume the first time that volume is mounted empty, so
# the datadir arrived already initialised for free.
#
# A BIND MOUNT gets no such copy. storage/mysql_data on the host starts as an
# empty directory and stays empty, and mysqld will not start against one. So the
# initialised tree the mysql-server package built at image-build time is
# preserved here, and the entrypoint unpacks it on first boot - reproducing by
# hand exactly what the named volume used to do for us.
#
# Tarred rather than copied so it costs one compressed layer instead of a second
# full datadir, and gzip because this is written once and read once.
RUN tar -C /var/lib/mysql -czf /opt/rspade/mysql-datadir-template.tgz . \
    && chmod 644 /opt/rspade/mysql-datadir-template.tgz

# Claude Code, when the image is built with --claude.
#
# OPT-IN on purpose. RSpade works with any agentic tooling or none, and an image
# that installs an AI assistant nobody asked for is making a decision that is not
# the framework's to make. The LAUNCHER ships either way (it installs on first
# use if the binary is absent), so `--claude` is really about baking it into the
# image rather than fetching it the first time somebody runs it.
ARG INSTALL_CLAUDE=false
RUN if [ "$INSTALL_CLAUDE" = "true" ]; then \
        echo 'export PATH="$HOME/.local/bin:$PATH"' >> /root/.bashrc; \
        curl -fsSL https://claude.ai/install.sh | CLAUDE_INSTALL_ALLOW_SUDO=1 bash \
            || echo "WARNING: Claude Code install failed; the launcher will retry on first use."; \
    fi

# Development OPcache: revalidate on every request, so editing a file is
# immediately live. This is what makes "edit, refresh, see it" true.
COPY php/opcache-dev.ini /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini
RUN chmod 644 /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini

# Development PHP: 320 MB a process, php-fpm and the CLI alike - lower than production on
# purpose, so a request or a command that grows past what production allows fails here
# first (rspade-sizing rewrites it at every start, from RSPADE_PHP_MEMORY_LIMIT_MB when set).
RUN for sapi in fpm cli; do \
        printf '%s\n' 'memory_limit = 320M' > /etc/php/${PHP_VERSION}/${sapi}/conf.d/95-rspade-memory.ini \
        && chmod 644 /etc/php/${PHP_VERSION}/${sapi}/conf.d/95-rspade-memory.ini; \
    done

# MySQL runs under supervisor only in this target.
COPY supervisor/conf.d/mysql.conf /etc/supervisor/conf.d/mysql.conf
RUN chmod 644 /etc/supervisor/conf.d/mysql.conf

# The mail catcher runs under supervisor only in this target. Same chmod 644 as
# every other config copied into the image, for the reason given in base: COPY
# preserves the world-writable source mode, and a world-writable config file is
# at best ignored and at worst writable by anything in the container.
COPY supervisor/conf.d/mail-catcher.conf /etc/supervisor/conf.d/mail-catcher.conf
RUN chmod 644 /etc/supervisor/conf.d/mail-catcher.conf

# Its source: resource/docker/mail-catcher/, installed at /opt/rspade/mail-catcher/ (the
# python3-aiosmtpd it runs on is installed above). Root-owned, world-readable.
COPY mail-catcher/ /opt/rspade/mail-catcher/
RUN chown -R root:root /opt/rspade/mail-catcher \
    && find /opt/rspade/mail-catcher -type d -exec chmod 755 {} + \
    && find /opt/rspade/mail-catcher -type f -exec chmod 644 {} +

# THE IMAGE RECORD - the LAST layer of this stage, so a new revision rebuilds this layer
# and nothing else (an ARG in base would invalidate every layer after it on every build).
# image_contract.conf is copied verbatim (its header has the rule); build.sh passes the
# framework revision it was built from (a plain `docker build` records "unknown"), and
# system/bin/publish_docker_images passes the published image version (`image_version=`,
# "local" for any other build, which every checkout accepts).
# Read by bootstrap/rsx_image_contract.php and system/bin/lib/rsx_image_contract.sh against
# the checkout's min_image_version: inside the container an older image is fatal.
ARG RSPADE_BUILT_FROM=unknown
ARG RSPADE_IMAGE_VERSION=local
COPY image_contract.conf /opt/rspade/image.conf
RUN sed -i -e '$a\' /opt/rspade/image.conf \
    && printf 'built_from=%s\n' "$RSPADE_BUILT_FROM" >> /opt/rspade/image.conf \
    && printf 'image_version=%s\n' "$RSPADE_IMAGE_VERSION" >> /opt/rspade/image.conf \
    && chown root:root /opt/rspade/image.conf \
    && chmod 644 /opt/rspade/image.conf

# -----------------------------------------------------------------------------
# prod - application runtime only; the database lives elsewhere
# -----------------------------------------------------------------------------
FROM base AS prod

ENV RSPADE_CONTAINER_TARGET=prod
RUN sed -i "s/^target=.*/target=prod/" /.rspade_container

# Production OPcache: hold compiled bytecode and re-check the source at most
# every 10 seconds, rather than on every single request. Deploying new code
# becomes visible within that window without a restart.
COPY php/opcache-prod.ini /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini

# The production pool: www-data rather than root, and a dynamic worker pool rather
# than ondemand. It REPLACES the development pool of the same name. The worker counts in
# it are placeholders: the entrypoint sizes the pool to the machine at every start
# (rspade-sizing), before supervisor starts php-fpm.
COPY php/www-prod.conf  /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
RUN chmod 644 /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini \
              /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf

# Production PHP: 352 MB a process (development's 320 and a tenth more), php-fpm and the CLI
# alike (rspade-sizing rewrites it at
# every start, from RSPADE_PHP_MEMORY_LIMIT_MB when set).
RUN for sapi in fpm cli; do \
        printf '%s\n' 'memory_limit = 352M' > /etc/php/${PHP_VERSION}/${sapi}/conf.d/95-rspade-memory.ini \
        && chmod 644 /etc/php/${PHP_VERSION}/${sapi}/conf.d/95-rspade-memory.ini; \
    done

# mysql-client is present (from base) on purpose: migrations, db:query and
# operational access all need to reach the database server you provide.

# THE IMAGE RECORD - the LAST layer of this stage, so a new revision rebuilds this layer
# and nothing else (an ARG in base would invalidate every layer after it on every build).
# image_contract.conf is copied verbatim (its header has the rule); build.sh passes the
# framework revision it was built from (a plain `docker build` records "unknown"), and
# system/bin/publish_docker_images passes the published image version (`image_version=`,
# "local" for any other build, which every checkout accepts).
# Read by bootstrap/rsx_image_contract.php and system/bin/lib/rsx_image_contract.sh against
# the checkout's min_image_version: inside the container an older image is fatal.
ARG RSPADE_BUILT_FROM=unknown
ARG RSPADE_IMAGE_VERSION=local
COPY image_contract.conf /opt/rspade/image.conf
RUN sed -i -e '$a\' /opt/rspade/image.conf \
    && printf 'built_from=%s\n' "$RSPADE_BUILT_FROM" >> /opt/rspade/image.conf \
    && printf 'image_version=%s\n' "$RSPADE_IMAGE_VERSION" >> /opt/rspade/image.conf \
    && chown root:root /opt/rspade/image.conf \
    && chmod 644 /opt/rspade/image.conf

# -----------------------------------------------------------------------------
# docconvert - the document sandbox image: soffice, pdftotext, fonts, nothing else
# -----------------------------------------------------------------------------
#
# WHAT IT IS FOR. When rsx.libreoffice.sandbox is 'docker', the render worker spawns
# ONE throwaway `docker run --rm` per document and runs soffice or pdftotext inside
# it (rsx:man libreoffice, THE DOCUMENT SANDBOX). This is the image that run uses.
#
# WHY IT IS NOT BUILT FROM `base`. The whole point of the sandbox is that a hostile
# document meets the smallest possible machine: every binary, library and service
# the image carries is something a converter exploit could reach for. `base` is an
# application runtime - PHP, nginx, redis, supervisor, mysql-client, git, ssh - and
# none of it converts a document. So this stage starts from the distribution image
# directly and installs the four packages the conversion actually needs. It is also
# a fraction of the size, which matters on a host that pulls it to render one file.
#
# NO ENTRYPOINT, deliberately: the sandbox seam already passes `--entrypoint ''`,
# and a bare image means the argv the framework builds IS the process, with nothing
# bringing an environment up around it.
#
# An operator may point LIBREOFFICE_SANDBOX_IMAGE at an image of their own instead;
# it then has to carry soffice, pdftotext and fonts, and it is theirs to distribute.
FROM ubuntu:24.04 AS docconvert

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC

# tzdata configures itself non-interactively from this file, so it is written
# BEFORE the first apt-get - otherwise the install stops on a region prompt.
RUN echo "$TZ" > /etc/timezone

RUN apt-get update && apt-get install -y \
    # the two converters the framework spawns, and the fonts they substitute with.
    # Without the fonts a conversion still succeeds and renders the wrong glyphs,
    # which is the worse failure: it looks like a document rather than an error.
        libreoffice poppler-utils fonts-liberation fonts-dejavu-core \
    # apt fetches over https from the distribution archive; the root store is what
    # makes that verifiable.
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*
