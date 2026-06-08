FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    su-exec \
    autoconf dpkg-dev dpkg file g++ gcc libc-dev make pkgconf re2c \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql pgsql zip intl mbstring bcmath opcache pcntl gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf dpkg-dev dpkg file g++ gcc libc-dev make pkgconf re2c \
    && sed -i 's|error_log = /proc/self/fd/2|error_log = /dev/stderr|g' /usr/local/etc/php-fpm.d/docker.conf \
    && sed -i 's|access.log = /proc/self/fd/2|access.log = /dev/stdout|g' /usr/local/etc/php-fpm.d/docker.conf \
    && sed -i 's|listen = 9000|listen = 9000|g' /usr/local/etc/php-fpm.d/zz-docker.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

RUN mkdir -p storage/logs bootstrap/cache \
    && chmod 777 storage bootstrap/cache

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-autoloader --prefer-dist --no-scripts

COPY . .

RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p storage/logs bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
EXPOSE 9000
CMD ["php-fpm"]
