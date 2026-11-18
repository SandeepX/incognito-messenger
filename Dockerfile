# ============================================================
# Stage 1: build the frontend (Echo + Vite)
#
# The browser connects to the WebSocket through the HOST, so the
# baked-in VITE_REVERB_* vars point at localhost:8080 — the port
# docker-compose maps from the `reverb` container to your machine.
# ============================================================
FROM node:23-alpine AS frontend

# Only the app key and port are baked into the bundle; the WS host is
# derived from window.location.hostname at runtime (see resources/js/echo.js),
# so the image works from localhost AND from other machines via LAN IP.
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_PORT=8080

ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT}

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-fund --no-audit
COPY resources/js ./resources/js
COPY resources/css ./resources/css
COPY vite.config.js ./
RUN npm run build

# ============================================================
# Stage 2: PHP-FPM runtime
# pdo_sqlite ships with the official php image, so no extra
# extension installs are needed for the demo.
# ============================================================
FROM php:8.4-fpm-alpine

RUN apk add --no-cache bash linux-headers \
    # pcntl is required by Reverb (SIGINT/SIGTERM signal handling)
    && docker-php-ext-install pcntl

# Composer from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Dependencies first (layer cached when only app code changes)
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# Application code
COPY --chown=www-data:www-data . /var/www

# ...plus the built frontend assets from stage 1
COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/public/build

RUN chown -R www-data:www-data /var/www/storage \
    # The sqlite DB lives outside database/ (docker-compose mounts a
    # volume here; a file-path volume mount isn't possible).
    && mkdir -p /var/www/storage/db \
    && touch /var/www/storage/db/database.sqlite \
    && chown -R www-data:www-data /var/www/storage/db \
    && chmod +x /var/www/docker/entrypoint.sh

ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
CMD ["php-fpm"]
