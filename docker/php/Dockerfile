FROM php:8.3-fpm-alpine

RUN apk add --no-cache git unzip bash icu-dev libzip-dev postgresql-dev oniguruma-dev \
        freetype-dev libjpeg-turbo-dev libpng-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath pcntl mbstring gd opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
