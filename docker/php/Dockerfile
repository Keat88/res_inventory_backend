FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .

RUN composer dump-autoload --optimize --no-scripts

FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        bash \
        curl \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && apk del freetype-dev icu-dev libjpeg-turbo-dev libpng-dev libzip-dev oniguruma-dev

COPY --from=vendor /app /var/www
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-posmaster.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

WORKDIR /var/www

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]

CMD ["php-fpm"]
