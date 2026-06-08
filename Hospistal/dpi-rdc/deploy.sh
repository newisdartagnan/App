#!/usr/bin/env bash
set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/votre-org/dpi-rdc.git}"
INSTALL_DIR="${INSTALL_DIR:-/opt/dpi}"

echo "=== Déploiement DPI-RDC ==="

if [ ! -f .env ]; then
    if [ ! -f .env.example ]; then
        echo "Erreur: .env.example introuvable"
        exit 1
    fi
    cp .env.example .env
    echo "Fichier .env créé — configurez ESTABLISHMENT_CODE, DB_PASSWORD, etc."
fi

# shellcheck disable=SC1091
source .env 2>/dev/null || true

if [ -z "${APP_KEY:-}" ] || [ "$APP_KEY" = "" ]; then
    docker compose run --rm app php artisan key:generate --force
fi

docker compose build app horizon scheduler
docker compose up -d db redis
echo "Attente PostgreSQL..."
sleep 10
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan db:seed --force
docker compose up -d
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
docker compose restart horizon scheduler

echo "=== Déploiement terminé ==="
echo "Accès: http://localhost:${APP_PORT:-80}"
