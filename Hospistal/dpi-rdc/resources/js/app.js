import './bootstrap';

/* ── Le bandeau de coupure ───────────────────────────────────────────── */

function majBandeauHorsLigne() {
    const bandeau = document.getElementById('offline-banner');
    if (!bandeau) {
        return;
    }
    bandeau.classList.toggle('hidden', navigator.onLine);
}

window.addEventListener('online', majBandeauHorsLigne);
window.addEventListener('offline', majBandeauHorsLigne);
majBandeauHorsLigne();

/*
 * Hors connexion, un formulaire envoyé ne part nulle part : la saisie est
 * perdue sans que personne ne le dise. Mieux vaut le dire avant.
 */
document.addEventListener('submit', (evenement) => {
    if (navigator.onLine) {
        return;
    }

    evenement.preventDefault();
    const bandeau = document.getElementById('offline-banner');
    if (bandeau) {
        bandeau.classList.remove('hidden');
        bandeau.scrollIntoView({ block: 'nearest' });
    }
});

/* ── Le service worker ───────────────────────────────────────────────── */

const marqueur = document.querySelector('meta[name="sw-actif"]');
const actif = marqueur ? marqueur.getAttribute('content') === '1' : false;

if ('serviceWorker' in navigator) {
    if (actif) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {
                // Un enregistrement qui échoue ne doit rien casser :
                // l'application marche sans lui, simplement moins vite.
            });
        });

        /*
         * Le poste passe de main en main. Chaque retour à l'écran de
         * connexion vide le cache : rien de ce qu'a consulté l'équipe
         * précédente ne doit survivre à son départ.
         */
        if (document.body && document.body.dataset.ecran === 'connexion') {
            navigator.serviceWorker.ready
                .then((inscription) => inscription.active?.postMessage({ type: 'vider-le-cache' }))
                .catch(() => {});
        }
    } else {
        // Le coupe-circuit : SERVICE_WORKER_ACTIF=false désinstalle le
        // service worker déjà en place et libère son cache, sans qu'il
        // faille redéployer quoi que ce soit.
        navigator.serviceWorker.getRegistrations()
            .then((inscriptions) => inscriptions.forEach((i) => i.unregister()))
            .catch(() => {});

        if (window.caches) {
            caches.keys().then((noms) => noms.forEach((nom) => caches.delete(nom))).catch(() => {});
        }
    }
}
