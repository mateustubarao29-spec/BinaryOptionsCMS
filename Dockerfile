# BEATHEX Preview - Laravel 8 / PHP 8.1
# Multi-stage build: compile legacy Vue/Laravel Mix assets, then serve Laravel with Apache.

FROM node:16-bullseye-slim AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci --legacy-peer-deps
COPY . .
RUN npm run production

FROM php:8.1-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libzip-dev libpq-dev libpng-dev libjpeg62-turbo-dev \
    libfreetype6-dev libonig-dev libxml2-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql bcmath gd intl zip pcntl sockets \
    && (a2dismod mpm_event mpm_worker mpm_prefork || true) \
    && find /etc/apache2/mods-enabled -maxdepth 1 -type l -name 'mpm_*.load' -delete \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.7.9 /usr/bin/composer /usr/bin/composer
COPY . .
COPY --from=assets /app/public /var/www/html/public

RUN cp -n .env.example .env \
    && git config --global url.\"https://github.com/\".insteadOf \"git@github.com:\" \
    && git config --global url.\"https://github.com/\".insteadOf \"ssh://git@github.com/\" \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --optimize-autoloader \
    && cp -n .env.example .env \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

CMD ["sh", "-c", "PORT=${PORT:-8080}; sed -ri \"s/^Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf; sed -ri \"s/VirtualHost \\\\*:80/VirtualHost *:${PORT}/\" /etc/apache2/sites-available/000-default.conf; if [ -z \"$APP_KEY\" ]; then php artisan key:generate --force --no-interaction; fi; php artisan storage:link || true; exec apache2-foreground"]
