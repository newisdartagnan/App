# Doc&I — image PHP de développement et de test.
# Le projet est monté en entier dans /var/www (docker-compose.yml) ; les
# dépendances Composer vont dans le volume « vendor », installées au
# démarrage par docker/php/entrypoint.sh. Aucune étape npm : les assets
# compilés sont versionnés dans public/build.
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        postgresql-dev libzip-dev icu-dev oniguruma-dev linux-headers \
        autoconf g++ make git unzip util-linux \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl mbstring bcmath opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY docker/php/entrypoint.sh /usr/local/bin/doc-and-i-entrypoint
RUN chmod +x /usr/local/bin/doc-and-i-entrypoint

ENTRYPOINT ["doc-and-i-entrypoint"]
EXPOSE 9000
CMD ["php-fpm"]
