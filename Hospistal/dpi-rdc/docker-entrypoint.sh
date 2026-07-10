#!/bin/sh
set -e

mkdir -p \
    /var/www/storage/logs \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/framework/cache \
    /var/www/public/vendor/livewire \
    /var/www/public/icons

chmod -R 777 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# Publier les assets Livewire dans public/ (partagé avec nginx)
if [ -f /var/www/artisan ]; then
    php /var/www/artisan livewire:publish --assets --force 2>/dev/null || true
    php /var/www/artisan icons:generate 2>/dev/null || true
fi

exec "$@"
