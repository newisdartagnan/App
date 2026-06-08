#!/bin/sh
set -e
mkdir -p /var/www/storage/logs /var/www/storage/framework/sessions /var/www/storage/framework/views /var/www/storage/framework/cache
chmod -R 777 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
exec "$@"
