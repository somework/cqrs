ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache $PHPIZE_DEPS linux-headers libzip-dev \
    && docker-php-ext-install pcntl zip \
    && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
