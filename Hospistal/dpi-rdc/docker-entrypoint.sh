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

# ══════════════════════════════════════════════════════════════════
# Les assets compilés.
#
# `public/` est monté depuis la machine hôte : ce sont donc les fichiers
# du poste qui sont servis, pas ceux que l'image a construits. Si le
# manifeste de Vite manque, est illisible, ou désigne un fichier que le
# dossier ne contient pas — un `git pull` interrompu, une fusion qui a
# laissé des marqueurs de conflit, un dossier copié à moitié — Laravel
# lève « Unable to locate file in Vite manifest » et TOUTES les pages
# répondent 500. Rien à l'écran ne dit que le problème est là.
#
# On regarde donc avant de démarrer, et on reconstruit si besoin. Les
# dépendances npm sont dans l'image : la réparation marche sans réseau,
# ce qui est la moindre des choses pour une salle sans connexion.
# ══════════════════════════════════════════════════════════════════
MANIFESTE=/var/www/public/build/manifest.json

assets_en_ordre() {
    [ -f "$MANIFESTE" ] || return 1

    php -r '
        $manifeste = @json_decode(@file_get_contents($argv[1]), true);

        if (! is_array($manifeste)) {
            exit(1);
        }

        foreach (["resources/css/app.css", "resources/js/app.js"] as $entree) {
            if (empty($manifeste[$entree]["file"])) {
                exit(1);
            }

            if (! is_file(dirname($argv[1])."/".$manifeste[$entree]["file"])) {
                exit(1);
            }
        }

        exit(0);
    ' "$MANIFESTE"
}

if assets_en_ordre; then
    :
else
    echo "DPI-RDC : les assets compilés sont absents ou incohérents — reconstruction."

    if (cd /var/www && npm run build); then
        echo "DPI-RDC : assets reconstruits."
    else
        echo "DPI-RDC : la reconstruction a échoué. Sur le poste, dans le dossier du projet :" >&2
        echo "          git checkout -- public/build   (puis relancer le conteneur)" >&2
    fi
fi

# Publier les assets Livewire dans public/ (partagé avec nginx)
if [ -f /var/www/artisan ]; then
    php /var/www/artisan livewire:publish --assets --force 2>/dev/null || true
    php /var/www/artisan icons:generate 2>/dev/null || true
    # Ce lien était créé après `exec`, c'est-à-dire jamais : `exec` remplace
    # le processus et rien de ce qui suit ne s'exécute.
    php /var/www/artisan storage:link 2>/dev/null || true
fi

exec "$@"
