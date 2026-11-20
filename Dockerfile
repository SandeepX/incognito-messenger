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

FROM php:8.4-fpm-alpine

RUN apk add --no-cache bash linux-headers \
    && docker-php-ext-install pcntl

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY --chown=www-data:www-data . /var/www

COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/public/build

RUN chown -R www-data:www-data /var/www/storage \
    && mkdir -p /var/www/storage/db \
    && touch /var/www/storage/db/database.sqlite \
    && chown -R www-data:www-data /var/www/storage/db \
    && chmod +x /var/www/docker/entrypoint.sh

ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
CMD ["php-fpm"]
