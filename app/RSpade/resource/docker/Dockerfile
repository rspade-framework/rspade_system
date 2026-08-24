# =============================================================================
# RSpade application container
#
# Two targets from one file, sharing every base layer:
#
#   --target dev    a complete development environment: MySQL inside the
#                   container, Playwright's browser libraries for rsx:debug,
#                   and OPcache set to notice file changes immediately.
#
#   --target prod   the same application runtime with no MySQL SERVER (bring
#                   your own), no browser libraries, and OPcache holding its
#                   compiled state for 10 seconds instead of re-checking every
#                   request.
#
# The application itself is NOT baked in - it is mounted at /var/www/html. One
# image serves every project.
#
# ARCHITECTURE: authored to build on amd64 and arm64 alike. Everything installs
# through apt or a distribution setup script; nothing downloads an
# architecture-pinned binary, and the base image is pinned by TAG (a
# single-arch digest would defeat this).
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
        nginx redis-server redis-tools supervisor \
    # talking to a database (the SERVER is a dev-target concern)
        mysql-client \
    # fetching and unpacking. No software-properties-common: it exists to provide
    # add-apt-repository, and the PHP apt source is now shipped as a file instead.
        ca-certificates curl wget gnupg2 unzip \
    # image handling: thumbnails, uploads, optimisation
        imagemagick ghostscript optipng \
    # document preview and text extraction - RUNTIME features, both targets
        libreoffice poppler-utils fonts-liberation fonts-dejavu-core \
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
        php${PHP_VERSION}-soap \
    && update-alternatives --set php /usr/bin/php${PHP_VERSION} \
    && rm -rf /var/lib/apt/lists/*

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
# npm is pinned to 11 deliberately: npm 12 requires node ^22.22.2 || ^24.15.0 ||
# >=26, so on node 23 an upgrade to latest aborts with EBADENGINE and takes the
# whole image build down with it.
RUN curl -fsSL https://deb.nodesource.com/setup_23.x | bash - \
    && apt-get update && apt-get install -y nodejs \
    && npm install -g npm@11 \
    && rm -rf /var/lib/apt/lists/*

# Service configuration. Copied in one layer; the per-target OPcache policy and
# supervisor programs are layered on top by the targets below.
COPY nginx/nginx.conf              /etc/nginx/nginx.conf
COPY nginx/site.conf               /etc/nginx/sites-enabled/default
COPY php/php.ini                   /etc/php/${PHP_VERSION}/fpm/php.ini
COPY php/www.conf                  /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY php/ajax.conf                 /etc/php/${PHP_VERSION}/fpm/pool.d/ajax.conf
COPY redis/redis.conf              /etc/redis/redis.conf
COPY supervisor/supervisord.conf   /etc/supervisor/supervisord.conf
COPY supervisor/conf.d/nginx.conf      /etc/supervisor/conf.d/nginx.conf
COPY supervisor/conf.d/php-fpm.conf    /etc/supervisor/conf.d/php-fpm.conf
COPY supervisor/conf.d/redis.conf      /etc/supervisor/conf.d/redis.conf
COPY supervisor/conf.d/fpc-proxy.conf  /etc/supervisor/conf.d/fpc-proxy.conf
COPY supervisor/conf.d/realtime.conf   /etc/supervisor/conf.d/realtime.conf
COPY supervisor/conf.d/rsx-lockd.conf  /etc/supervisor/conf.d/rsx-lockd.conf
COPY supervisor/conf.d/tasks.conf      /etc/supervisor/conf.d/tasks.conf

# Normalise permissions on everything copied above. The source tree these files
# come from is world-writable (a legacy of the chmod-777 arrangement this image
# replaces) and COPY preserves the source mode - so without this, mysqld.cnf
# arrives as 0777 and MySQL SILENTLY IGNORES IT: "World-writable config file is
# ignored", with every setting in it quietly not applied.
RUN chmod 644 /etc/nginx/nginx.conf \
              /etc/nginx/sites-enabled/default \
              /etc/php/${PHP_VERSION}/fpm/php.ini \
              /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
              /etc/php/${PHP_VERSION}/fpm/pool.d/ajax.conf \
              /etc/redis/redis.conf \
              /etc/supervisor/supervisord.conf \
              /etc/supervisor/conf.d/*.conf

COPY entrypoint.sh     /usr/local/bin/rspade-entrypoint
COPY realtime-run.sh   /usr/local/bin/rspade-realtime-run
COPY task-run.sh       /usr/local/bin/rspade-task-run
COPY retry-run.sh      /usr/local/bin/rspade-retry-run
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
              /usr/local/bin/rspade-task-run \
              /usr/local/bin/rspade-retry-run \
              /usr/local/bin/claude \
              /usr/local/bin/git \
              /usr/local/bin/npm

# Runtime directories the services expect to exist before they start.
RUN mkdir -p /run/php /var/log/supervisor /var/run/redis /var/log/redis /var/lib/redis \
    && chown -R redis:redis /var/run/redis /var/log/redis /var/lib/redis

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
    # Playwright's headless browser dependencies - this is what makes
    # `php artisan rsx:debug /path` able to render a real page
        libnss3 libnspr4 libatk-bridge2.0-0 libatspi2.0-0 libdrm2 libgbm1 \
        libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
        libxcursor1 libasound2t64 \
    # developer quality of life for anyone who shells in
        fzf jq man-db gh \
    && rm -rf /var/lib/apt/lists/*

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

# MySQL runs under supervisor only in this target.
COPY supervisor/conf.d/mysql.conf /etc/supervisor/conf.d/mysql.conf
RUN chmod 644 /etc/supervisor/conf.d/mysql.conf

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

# Production pools: www-data rather than root, and a dynamic worker pool rather
# than ondemand. These REPLACE the development pools of the same names.
COPY php/www-prod.conf  /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf
COPY php/ajax-prod.conf /etc/php/${PHP_VERSION}/fpm/pool.d/ajax.conf
RUN chmod 644 /etc/php/${PHP_VERSION}/fpm/conf.d/10-opcache.ini \
              /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
              /etc/php/${PHP_VERSION}/fpm/pool.d/ajax.conf

# mysql-client is present (from base) on purpose: migrations, db:query and
# operational access all need to reach the database server you provide.
