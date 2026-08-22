/*
 * Le service worker de DPI-RDC.
 *
 * Il avait été désactivé pour une bonne raison : il gardait les pages en
 * cache et les rendait telles quelles, jetons CSRF compris. Une infirmière
 * se reconnectait, la session repartait à zéro, mais la page servie par le
 * cache portait encore le jeton de la session précédente — et le formulaire
 * était refusé (419). On ne pouvait pas corriger cela au cas par cas : tant
 * qu'une page HTML sort du cache, elle porte un jeton qui n'a plus cours.
 *
 * D'où la règle qui tient tout ce fichier :
 *
 *     AUCUNE page de l'application n'entre jamais dans le cache.
 *
 * Ce qui est mis en cache est immuable et anonyme : les fichiers de build
 * (leur nom contient leur empreinte), les icônes, le manifeste, et une page
 * « pas de connexion » qui ne contient aucun formulaire. Rien qui porte un
 * jeton, rien qui porte le nom d'un patient.
 *
 * Cette seconde règle compte autant que la première. Un poste d'hôpital
 * passe de main en main : garder sur le disque du navigateur les dossiers
 * consultés par l'équipe du matin, c'est les ouvrir à l'équipe du soir.
 * Hors connexion, l'application dit qu'elle est hors connexion — elle ne
 * ressort pas le dossier de quelqu'un d'autre.
 *
 * Ce qu'on y gagne : sur une liaison lente, les feuilles de style et les
 * scripts ne repartent plus sur le réseau à chaque écran, et une coupure
 * donne une page de l'hôpital au lieu du dinosaure de Chrome.
 */

const VERSION = 'dpi-v1';
const CACHE = `${VERSION}-statique`;

/* Le strict nécessaire pour que la page de coupure s'affiche. */
const SOCLE = [
    '/hors-ligne',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

/*
 * Les fichiers de build s'accumuleraient à chaque déploiement : leur nom
 * change à chaque fois. On garde les plus récents et on jette le reste.
 */
const PLAFOND = 60;

self.addEventListener('install', (event) => {
    event.waitUntil(garnirLeSocle().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((noms) => Promise.all(
                noms.filter((nom) => nom !== CACHE).map((nom) => caches.delete(nom))
            ))
            // Le socle a pu être emporté par un ménage précédent : on le
            // remet, sinon la page de coupure n'aurait rien à servir.
            .then(() => garnirLeSocle())
            .then(() => self.clients.claim())
    );
});

/*
 * Le ménage, demandé par la page à chaque retour sur l'écran de connexion.
 *
 * Il ne s'agit pas de tout jeter : ce serait emporter la page de coupure et
 * les feuilles de style, qui n'appartiennent à personne. Il s'agit de faire
 * disparaître ce qui pourrait, lui, appartenir à quelqu'un — au cas où une
 * modification future laisserait passer une page en cache. Le poste change
 * de mains : ce qui n'est pas anonyme s'en va.
 */
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'vider-le-cache') {
        event.waitUntil(faireLeMenage());
    }
});

async function garnirLeSocle() {
    const cache = await caches.open(CACHE);
    const connu = new Set((await cache.keys()).map((requete) => requete.url));

    // Une entrée du socle qui manque ne doit pas faire échouer
    // l'installation : mieux vaut un cache partiel que pas de cache.
    await Promise.allSettled(
        SOCLE
            .filter((url) => !connu.has(new URL(url, self.location.origin).href))
            .map((url) => cache.add(url))
    );
}

async function faireLeMenage() {
    await Promise.all(
        (await caches.keys()).filter((nom) => nom !== CACHE).map((nom) => caches.delete(nom))
    );

    const cache = await caches.open(CACHE);
    const socle = new Set(SOCLE.map((url) => new URL(url, self.location.origin).href));

    await Promise.all((await cache.keys())
        .filter((requete) => {
            const chemin = new URL(requete.url).pathname;

            return !socle.has(requete.url) && !estUnFichierFige(chemin);
        })
        .map((requete) => cache.delete(requete)));

    await garnirLeSocle();
}

self.addEventListener('fetch', (event) => {
    const requete = event.request;

    // Un envoi de formulaire ne passe jamais par ici : le service worker
    // n'a rien à voir avec une écriture.
    if (requete.method !== 'GET') {
        return;
    }

    const url = new URL(requete.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (estUnFichierFige(url.pathname)) {
        event.respondWith(depuisLeCacheDabord(requete));

        return;
    }

    // Toute navigation part sur le réseau, toujours : c'est ce qui garantit
    // que le jeton de la page est celui de la session en cours.
    if (requete.mode === 'navigate') {
        event.respondWith(reseauPuisPageDeCoupure(requete));
    }

    // Le reste (XHR, JSON, PDF) n'est pas notre affaire.
});

function estUnFichierFige(chemin) {
    return chemin.startsWith('/build/')
        || chemin.startsWith('/icons/')
        || chemin === '/manifest.json';
}

async function depuisLeCacheDabord(requete) {
    const cache = await caches.open(CACHE);
    const connu = await cache.match(requete);

    if (connu) {
        return connu;
    }

    const reponse = await fetch(requete);

    if (reponse && reponse.ok && reponse.type === 'basic') {
        await cache.put(requete, reponse.clone());
        elaguer(cache);
    }

    return reponse;
}

async function reseauPuisPageDeCoupure(requete) {
    try {
        return await fetch(requete);
    } catch (erreur) {
        const cache = await caches.open(CACHE);
        const coupure = await cache.match('/hors-ligne');

        // Sans page de coupure en réserve, on laisse le navigateur dire
        // lui-même qu'il n'y a pas de réseau.
        return coupure || Response.error();
    }
}

/*
 * Sans plafond, chaque déploiement laisserait derrière lui l'intégralité des
 * fichiers du précédent. On coupe par la fin, les entrées les plus anciennes
 * étant les premières inscrites.
 */
async function elaguer(cache) {
    const entrees = await cache.keys();
    const aJeter = entrees.length - PLAFOND;

    if (aJeter <= 0) {
        return;
    }

    const socle = new Set(SOCLE.map((url) => new URL(url, self.location.origin).href));
    const candidates = entrees.filter((requete) => !socle.has(requete.url));

    await Promise.all(candidates.slice(0, aJeter).map((requete) => cache.delete(requete)));
}
