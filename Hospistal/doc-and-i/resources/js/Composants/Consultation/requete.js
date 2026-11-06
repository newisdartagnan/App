// Requêtes JSON des composants de la consultation (même origine, cookies de
// session). Le jeton anti-CSRF est lu dans le cookie XSRF-TOKEN posé par Laravel.

function jetonCsrf() {
    const trouve = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return trouve ? decodeURIComponent(trouve.slice('XSRF-TOKEN='.length)) : '';
}

/**
 * @param {string} adresse
 * @param {{ methode?: string, corps?: FormData|object }} options
 * @returns {Promise<{ ok: boolean, statut: number, donnees: any }>}
 */
export async function requete(adresse, { methode = 'GET', corps = null } = {}) {
    const entetes = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    let body = null;

    if (methode !== 'GET') {
        entetes['X-XSRF-TOKEN'] = jetonCsrf();
        if (corps instanceof FormData) {
            body = corps;
        } else if (corps !== null) {
            entetes['Content-Type'] = 'application/json';
            body = JSON.stringify(corps);
        }
    }

    try {
        const reponse = await fetch(adresse, { method: methode, headers: entetes, body, credentials: 'same-origin' });
        const donnees = await reponse.json().catch(() => ({}));
        return { ok: reponse.ok, statut: reponse.status, donnees };
    } catch {
        return { ok: false, statut: 0, donnees: { message: 'Connexion perdue. Nouvel essai dans un instant.' } };
    }
}

/** Premier message d'erreur lisible d'une réponse (validation 422 ou refus 403). */
export function messageErreur(donnees) {
    if (donnees?.errors) {
        const premier = Object.values(donnees.errors)[0];
        return Array.isArray(premier) ? premier[0] : String(premier);
    }
    return donnees?.message ?? 'Une erreur est survenue. Réessayez.';
}
