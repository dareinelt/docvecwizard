# PHP application container (used for `app` FPM, `worker` CLI, and `migrate`).
# Vanilla PHP, no framework. Runs as the non-root `app` user via docker-entrypoint.sh.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        $PHPIZE_DEPS \
        oniguruma-dev \
        libzip-dev \
        curl-dev \
        su-exec \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        fileinfo \
        zip \
        pcntl \
        posix \
        curl \
    && apk del $PHPIZE_DEPS

RUN addgroup -g 10003 app && adduser -D -u 10003 -G app app

WORKDIR /app

COPY --chown=app:app . /app

# Replace the stock pool config (www-data / 127.0.0.1) with our app pool
# (user app / 0.0.0.0:9000 / clear_env=no).
COPY php-fpm.d/www.conf /usr/local/etc/php-fpm.d/www.conf

RUN mkdir -p /app/storage && chown -R app:app /app/storage

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
