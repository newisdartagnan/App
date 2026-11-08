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
        echo "MediHome : installation des dépendances Composer…"
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
    echo "MediHome : .env créé depuis .env.example"
fi
# .env créé par une version antérieure : nom et adresse par défaut d'origine
# remplacés (seulement s'ils n'ont pas été personnalisés). Sans cela, l'ancien
# nom resterait affiché et les liens d'activation pointeraient vers l'ancien port.
sed -i \
    -e 's#^APP_NAME="Doc&I"$#APP_NAME="MediHome"#' \
    -e 's#^APP_URL=http://localhost:8090$#APP_URL=http://localhost:8097#' \
    .env

# Lot paiement : un .env plus ancien n'a pas ces lignes.
grep -q '^PAIEMENT_FOURNISSEUR=' .env || printf '\nPAIEMENT_FOURNISSEUR=simulation\n' >> .env
grep -q '^DOC_AND_I_COMMISSION_POURCENT=' .env || printf 'DOC_AND_I_COMMISSION_POURCENT=20\n' >> .env
# Secret des rappels de paiement : généré une fois, propre à ce poste.
if ! grep -q '^PAIEMENT_SECRET_RAPPEL=.\{32,\}' .env; then
    secret=$(php -r 'echo bin2hex(random_bytes(32));')
    if grep -q '^PAIEMENT_SECRET_RAPPEL=' .env; then
        sed -i "s#^PAIEMENT_SECRET_RAPPEL=.*#PAIEMENT_SECRET_RAPPEL=${secret}#" .env
    else
        printf 'PAIEMENT_SECRET_RAPPEL=%s\n' "$secret" >> .env
    fi
    echo "MediHome : secret des rappels de paiement généré"
fi

# Sceau des documents signés : généré une fois, propre à ce poste.
if ! grep -q '^DOSSIER_SECRET_SIGNATURE=.\{32,\}' .env; then
    secret=$(php -r 'echo bin2hex(random_bytes(32));')
    if grep -q '^DOSSIER_SECRET_SIGNATURE=' .env; then
        sed -i "s#^DOSSIER_SECRET_SIGNATURE=.*#DOSSIER_SECRET_SIGNATURE=${secret}#" .env
    else
        printf 'DOSSIER_SECRET_SIGNATURE=%s\n' "$secret" >> .env
    fi
    echo "MediHome : clé du sceau des documents signés générée"
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
