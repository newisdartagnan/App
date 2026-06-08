#!/bin/bash
# ─────────────────────────────────────────────────────────────────
# Entrypoint commun à app et csk-app
#
# Ce script s'exécute au démarrage du conteneur (pas pendant le
# docker build). Il installe les dépendances Composer uniquement
# si le dossier vendor/ n'existe pas encore, puis lance Apache.
#
# Librairies installées pour csk-app :
#   - tecnickcom/tcpdf   → generer_pdf_resultat_unique.php
#   - setasign/fpdi      → fusion de pages PDF dans TCPDF
#   - mpdf/mpdf          → pdf_resultat.php (avec fallback HTML)
# ─────────────────────────────────────────────────────────────────
set -e

WEBROOT="/var/www/html"

echo "[entrypoint] Démarrage du conteneur…"

# ── Composer install / update ─────────────────────────────────────
if [ -f "$WEBROOT/composer.json" ]; then

    if [ ! -d "$WEBROOT/vendor" ]; then
        echo "[entrypoint] vendor/ absent — composer install en cours…"
        cd "$WEBROOT"
        composer install --no-interaction --no-dev --optimize-autoloader
        echo "[entrypoint] ✓ composer install terminé"

    else
        echo "[entrypoint] vendor/ présent — vérification des dépendances PDF…"
        cd "$WEBROOT"

        # TCPDF (generer_pdf_resultat_unique.php)
        if [ ! -d "vendor/tecnickcom/tcpdf" ]; then
            echo "[entrypoint] Installation TCPDF…"
            composer require tecnickcom/tcpdf --no-interaction --optimize-autoloader
        fi

        # FPDI (fusion PDF avec TCPDF)
        if [ ! -d "vendor/setasign/fpdi" ]; then
            echo "[entrypoint] Installation FPDI…"
            composer require setasign/fpdi --no-interaction --optimize-autoloader
        fi

        # mPDF (pdf_resultat.php)
        if [ ! -d "vendor/mpdf/mpdf" ]; then
            echo "[entrypoint] Installation mPDF…"
            composer require mpdf/mpdf --no-interaction --optimize-autoloader
        fi

        echo "[entrypoint] ✓ Dépendances PDF OK"
    fi

else
    echo "[entrypoint] Pas de composer.json trouvé dans $WEBROOT — aucune installation."
fi

# ── Dossiers uploadés (au cas où le volume monte un dossier vide) ─
mkdir -p "$WEBROOT/uploads/resultats_pdf"
chown -R www-data:www-data "$WEBROOT/uploads" 2>/dev/null || true
chmod -R 755 "$WEBROOT/uploads" 2>/dev/null || true

# ── Lancer la commande passée (apache2-foreground par défaut) ─────
echo "[entrypoint] Lancement : $*"
exec "$@"
