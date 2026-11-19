# ============================================================
# Stage 1: build the frontend (Echo + Vite)
#
# The browser derives the WebSocket host, port and TLS from window.location
# at runtime (see resources/js/echo.js). Only the app key is baked into the
# bundle, so one image works on localhost, a LAN IP, and a single-origin
# HTTPS deployment without rebuilding.
# ============================================================
FROM node:23-alpine AS frontend

ARG VITE_REVERB_APP_KEY
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}

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
# extension installs are needed (SQLite is the Docker database).
# ============================================================
FROM php:8.4-fpm-alpine

RUN apk add --no-cache bash linux-headers \
    # pcntl is required by Reverb (SIGINT/SIGTERM signal handling)
    && docker-php-ext-install pcntl

# Allow real-world photos through PHP; Laravel validates the 10 MB cap.
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

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
