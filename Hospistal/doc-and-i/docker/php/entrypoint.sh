#!/bin/sh
# Prépare le projet monté avant de lancer la commande demandée.
set -e
cd /var/www


# 1. Dépendances Composer, une seule installation à la fois (app, worker et
#    tests partagent le volume vendor), refaite quand composer.lock change.
mkdir -p vendor
(
    flock 9
    if [ ! -f vendor/autoload.php ] || [ ! -f vendor/.composer-lock.sha ] \
        || [ "$(sha1sum composer.lock | cut -d' ' -f1)" != "$(cat vendor/.composer-lock.sha)" ]; then
        echo "Doc&I : installation des dépendances Composer…"
        composer install --no-interaction --no-progress --prefer-dist
        sha1sum composer.lock | cut -d' ' -f1 > vendor/.composer-lock.sha
    fi
) 9> vendor/.install.lock

# 2. Fichier .env créé depuis l'exemple, clé générée si absente.
if [ ! -f .env ]; then
    cp .env.example .env
    # Même propriétaire que le projet : sous Linux, le développeur doit
    # pouvoir modifier son .env sans sudo.
    chown "$(stat -c %u:%g composer.json)" .env 2>/dev/null || true
    echo "Doc&I : .env créé depuis .env.example"
fi
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
# Dossiers seulement : un chmod récursif marquerait aussi les fichiers suivis
# par Git (.gitignore) comme exécutables, et git status changerait sous Linux.
find storage bootstrap/cache -type d -exec chmod 777 {} + 2>/dev/null || true

# 3. Migrations et données de référence, seulement si on le demande
#    (DOC_AND_I_AUTO_MIGRATE=true, réglé par le service app).
if [ "${DOC_AND_I_AUTO_MIGRATE:-false}" = "true" ]; then
    php artisan migrate --force
    php artisan db:seed --force
fi

exec "$@"
