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

/*
 * Le bouton « Imprimer ».
 *
 * Rien n'invitait à imprimer : il fallait connaître Ctrl+P. Or tout ce qui
 * sort d'ici finit dans la main de quelqu'un — une convocation, un bulletin
 * de sortie, un résultat d'examen.
 *
 * L'appel est branché ici plutôt qu'en attribut onclick : les postes dont la
 * politique de sécurité interdit les scripts en ligne gardent un document
 * imprimable par le menu du navigateur.
 */
document.addEventListener('click', (evenement) => {
    const bouton = evenement.target.closest('[data-imprimer]');

    if (bouton) {
        evenement.preventDefault();
        window.print();
    }
});

/*
 * La quantité d'une ligne d'ordonnance, montrée pendant qu'on la pose.
 *
 * Le champ affichait « auto » : le médecin signait une quantité qu'il ne
 * voyait jamais, et découvrait au comptoir qu'il avait prescrit trois
 * plaquettes au lieu d'une. Le nombre s'inscrit maintenant dès que la dose,
 * les prises et les jours sont posés — dose × prises × jours, la même
 * formule que le serveur, qui reste la référence.
 *
 * Dès que le prescripteur écrit lui-même une quantité, on cesse de la
 * recalculer : c'est lui qui décide, pas le formulaire.
 */
function brancherLeCalculDesQuantites() {
    const champs = document.querySelectorAll('[data-quantite-de]');

    if (champs.length === 0) {
        return;
    }

    champs.forEach((quantite) => {
        const i = quantite.dataset.quantiteDe;
        const dose = document.getElementById(`dose-${i}`);
        const frequence = document.getElementById(`freq-${i}`);
        const duree = document.getElementById(`duree-${i}`);
        const detail = document.querySelector(`[data-detail-quantite="${i}"]`);

        if (!dose || !frequence || !duree) {
            return;
        }

        // Une valeur déjà saisie — un retour d'erreur de validation, par
        // exemple — appartient au prescripteur : on n'y touche plus.
        let corrigeeALaMain = quantite.value !== '';

        quantite.addEventListener('input', () => {
            corrigeeALaMain = true;
        });

        const recalculer = () => {
            const d = parseFloat(dose.value);
            const f = parseInt(frequence.value, 10);
            const j = parseInt(duree.value, 10);

            if (!(d > 0) || !(f > 0) || !(j > 0)) {
                if (detail) {
                    detail.textContent = '';
                }

                return;
            }

            const total = Math.round(d * f * j * 100) / 100;

            if (!corrigeeALaMain) {
                quantite.value = total;
            }

            if (detail) {
                detail.textContent = corrigeeALaMain && parseFloat(quantite.value) !== total
                    ? `schéma : ${d} × ${f} × ${j} = ${total}`
                    : `${d} × ${f} × ${j} jours`;
            }
        };

        [dose, frequence, duree].forEach((champ) => champ.addEventListener('input', recalculer));
        recalculer();
    });
}

brancherLeCalculDesQuantites();

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
