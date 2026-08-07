# syntax=docker/dockerfile:1.7

# ----------------------------------------------------------------------------
# TryPost Dockerfile — multi-stage with `dev` and `production` targets.
#
#   docker build --target dev        -t trypost:dev  -f docker/Dockerfile .
#   docker build --target production -t trypost:prod -f docker/Dockerfile .
# ----------------------------------------------------------------------------

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

FROM node:${NODE_VERSION}-bookworm-slim AS node-runtime

# ----------------------------------------------------------------------------
# Stage 1: system-base — PHP-FPM + system packages + extensions
# ----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS system-base

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        curl \
        git \
        unzip \
        shadow \
        tzdata \
        postgresql-client \
        postgresql-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libwebp-dev \
        libavif-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        opcache \
        bcmath \
        exif \
        pcntl \
        intl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ----------------------------------------------------------------------------
# Stage 2: composer-deps — full PHP deps (incl. dev) for tooling/asset build
# ----------------------------------------------------------------------------
FROM system-base AS composer-deps

COPY composer.json composer.lock ./
RUN composer install \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ----------------------------------------------------------------------------
# Stage 3: composer-deps-prod — production deps only (no dev tooling)
# ----------------------------------------------------------------------------
FROM system-base AS composer-deps-prod

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ----------------------------------------------------------------------------
# Stage 4: asset-build — wayfinder TS gen + Vite + Inertia SSR build
#
# PHP and Node together in one stage because @laravel/vite-plugin-wayfinder
# invokes `php artisan wayfinder:generate` during vite build — splitting
# the stages would mean the vite phase has no PHP available.
#
# Reverb settings are provided by Laravel in the initial Inertia payload, so
# the same image can be promoted through environments without rebuilding it.
# PostHog remains optional build-time instrumentation.
# ----------------------------------------------------------------------------
FROM composer-deps AS asset-build

# Node 22 for Vite + npm scripts.
RUN apk add --no-cache nodejs npm

ARG VITE_APP_NAME=TryPost
ARG VITE_POSTHOG_ENABLED=false
ARG VITE_POSTHOG_API_KEY=
ARG VITE_POSTHOG_HOST=https://us.i.posthog.com

# Stub PHP envs so artisan can boot (overridden at runtime).
ENV APP_KEY=base64:c3R1Yi13YXlmaW5kZXItZ2VuLWtleS1mb3ItYnVpbGRpbmctYXNzZXRzMA== \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_POSTHOG_ENABLED=${VITE_POSTHOG_ENABLED} \
    VITE_POSTHOG_API_KEY=${VITE_POSTHOG_API_KEY} \
    VITE_POSTHOG_HOST=${VITE_POSTHOG_HOST}

COPY --from=composer-deps /var/www/html/vendor ./vendor
COPY . .

# Ensure Laravel runtime dirs exist — .dockerignore strips their contents
# (correct: caches shouldn't bloat builds), but artisan boot needs the dirs.
RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache

RUN composer dump-autoload --no-scripts --optimize \
    && php artisan wayfinder:generate --with-form \
    && npm ci --no-audit --no-fund \
    && npm run build \
    && npm run build:ssr

# ----------------------------------------------------------------------------
# Stage 5: ci — short-lived quality gate (never promoted to production)
# ----------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-bookworm AS ci

COPY --from=node-runtime /usr/local /usr/local
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
        postgresql-client \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libavif-dev \
        libzip-dev \
        libonig-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        opcache \
        bcmath \
        exif \
        pcntl \
        intl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

WORKDIR /var/www/html

# Exercise media handling under the same bounded PHP memory ceiling as the
# production runtime. The CLI base otherwise defaults to 128M, below the
# application's supported GD image workload.
COPY docker/php.prod.ini /usr/local/etc/php/conf.d/99-trypost.ini

# Composer's package-discovery hook boots Laravel while the CI image is built.
# Provide only inert build-time values here; clarity/ci.sh replaces them with
# .env.ci before any test or migration runs.
ENV APP_ENV=testing \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_URL=http://localhost

COPY composer.json composer.lock package.json package-lock.json ./
RUN composer install --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    && npm ci --no-audit --no-fund \
    && npx playwright install --with-deps chromium

COPY . .

RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
    && composer dump-autoload --optimize \
    && install -D -m 0755 clarity/ci.sh /clarity/ci.sh

ENTRYPOINT ["/clarity/ci.sh"]

# ----------------------------------------------------------------------------
# Stage 6: dev — local development image (bind-mount the source at runtime)
# ----------------------------------------------------------------------------
FROM system-base AS dev

ARG UID=1000
ARG GID=1000

# Node + npm for in-container Vite, npm scripts, ad-hoc tooling.
RUN apk add --no-cache nodejs npm

# Create non-root app user matching host UID/GID for clean bind-mount writes.
RUN groupmod -g "${GID}" www-data 2>/dev/null || groupadd -g "${GID}" app \
    && (id -u app >/dev/null 2>&1 || useradd -u "${UID}" -g "${GID}" -d /home/app -m -s /bin/bash app) \
    && chown -R "${UID}:${GID}" /var/www/html

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.dev.ini /usr/local/etc/php/conf.d/99-trypost.ini
COPY docker/supervisord.dev.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/.env.docker.example /var/www/html/.env.docker.example
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV TRYPOST_TARGET=dev

EXPOSE 80 5173 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=5 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ----------------------------------------------------------------------------
# Stage 7: production — self-contained image for self-hosters
# ----------------------------------------------------------------------------
FROM system-base AS production

# php-fpm runs as www-data (Alpine default pool config) — that's the user
# storage and bootstrap/cache must be owned by, so Laravel can write logs,
# session files, view cache, etc.

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php.prod.ini /usr/local/etc/php/conf.d/99-trypost.ini
COPY docker/supervisord.prod.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && sed -i 's/listen 80;/listen 8081;/; s/listen \[::\]:80;/listen [::]:8081;/' \
        /etc/nginx/http.d/default.conf

# Application source + generated wayfinder TS + built assets — all from
# asset-build (the merged stage that ran php artisan wayfinder:generate,
# npm run build, and npm run build:ssr).
COPY --from=asset-build /var/www/html /var/www/html

# Replace the dev-equipped vendor/ with production-only deps. composer-deps-prod
# is built independently and is much smaller (no phpunit, no debugbar, no pail,
# no pint). Also wipe bootstrap/cache/*.php — package:discover ran with dev
# deps and recorded providers (Pail, Telescope etc) that no longer exist;
# Laravel rediscovers cleanly at first boot.
RUN rm -rf /var/www/html/vendor /var/www/html/bootstrap/cache/*.php
COPY --from=composer-deps-prod /var/www/html/vendor /var/www/html/vendor

RUN mkdir -p /var/lib/nginx /var/log/nginx /run/nginx \
    && chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
        /var/lib/nginx \
        /var/log/nginx \
        /run/nginx

RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && ln -s ../storage/app/public /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

ENV TRYPOST_TARGET=production

EXPOSE 8080 8081

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8081/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
