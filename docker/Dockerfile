ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-cli-alpine
RUN apk add --no-cache git unzip linux-headers icu-dev libzip-dev $PHPIZE_DEPS \
 && pecl install pcov && docker-php-ext-enable pcov \
 && docker-php-ext-install intl zip
# Node is here for one gate and one only: `make verify` drives the package's own
# JS through the real `@vue/reactivity` Alpine pins. It earned its place — a guard
# comparing an object against itself read back off a reactive `this` shipped in
# 1.1.0 and stuck every cell click forever, and the 660 PHP tests could not see it,
# because a JS test that greps the source for a string is not a test of behaviour.
RUN apk add --no-cache nodejs npm
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# The mounted repo belongs to the host user; git refuses "dubious ownership" otherwise.
# `--system` and not `--global`: the container runs as the host user, who never reads root's.
RUN git config --system --add safe.directory /app
WORKDIR /app
