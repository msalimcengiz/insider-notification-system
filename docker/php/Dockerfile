# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base: PHP runtime with every extension the app needs
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
        bash \
        fcgi \
        icu-libs \
        libpq \
        libzip \
        postgresql-client \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
        linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# dev: source is bind-mounted, dependencies are installed on first boot
# ---------------------------------------------------------------------------
FROM base AS dev

ENV APP_ENV=local

# ---------------------------------------------------------------------------
# prod: self-contained image with the code and production dependencies baked in
# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=production

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache
