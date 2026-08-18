ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli-alpine
RUN apk add --no-cache git unzip linux-headers icu-dev libzip-dev $PHPIZE_DEPS \
 && pecl install pcov && docker-php-ext-enable pcov \
 && docker-php-ext-install intl zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# The mounted repo belongs to the host user; git refuses "dubious ownership" otherwise.
# `--system` and not `--global`: the container runs as the host user, who never reads root's.
RUN git config --system --add safe.directory /app
WORKDIR /app
