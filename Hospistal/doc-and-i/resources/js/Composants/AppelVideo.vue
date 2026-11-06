<script setup>
/*
 * Appel vidéo de la téléconsultation (TLC-01, TLC-02, TLC-04, TLC-06), commun
 * au patient et au médecin. WebRTC natif du navigateur, sans bibliothèque :
 * le son et l'image vont d'un navigateur à l'autre, chiffrés ; le serveur ne
 * relaie que la mise en relation (signalisation par interrogation HTTP, même
 * origine : la CSP connect-src 'self' est respectée).
 *
 * Déroulé : le médecin fait l'offre ; le patient attend dans la salle
 * d'attente et répond à la dernière offre reçue. « pret » demande une offre
 * au médecin (arrivée du patient, rechargement, coupure). Aucun
 * enregistrement : pas de MediaRecorder (TLC-06).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    role: { type: String, required: true },
    signaux: { type: String, required: true },
    iceServers: { type: Array, default: () => [] },
    dernierSignal: { type: Number, default: 0 },
    intervalleMs: { type: Number, default: 1500 },
    seuilVideoKbps: { type: Number, default: 150 },
    correspondant: { type: String, default: '' },
});

const estMedecin = props.role === 'medecin';
const autre = computed(() => props.correspondant || (estMedecin ? 'le patient' : 'le médecin'));

const videoLocale = ref(null);
const videoDistante = ref(null);
const etat = ref('preparation'); // preparation, attente, connexion, connecte, reconnexion, quitte, close, erreur
const etatIce = ref('nouveau');
const message = ref('');
const micro = ref(true);
const camera = ref(true);
const cameraDisponible = ref(true);
const audioSeul = ref(false);
const raisonAudioSeul = ref('');
const envoiEnCours = ref(false);

let pc = null;
let fluxLocal = null;
let expediteurVideo = null;
let dernier = props.dernierSignal;
let actif = true;
let minuteurInterrogation = null;
let minuteurMesure = null;
let minuteurReconnexion = null;
let candidatsEnAttente = [];
let derniereOffre = 0;
let mesurePrecedente = null;
let echantillonsFaibles = 0;

// --- Outils ---------------------------------------------------------------

const attendre = (ms) => new Promise((resoudre) => { setTimeout(resoudre, ms); });

/** UUID v4 ; crypto.randomUUID n'existe qu'en contexte sécurisé. */
const nouvelleCle = () => {
    const o = crypto.getRandomValues(new Uint8Array(16));
    o[6] = (o[6] & 0x0f) | 0x40;
    o[8] = (o[8] & 0x3f) | 0x80;
    const h = [...o].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
};

const jetonXsrf = () => {
    const morceau = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return morceau ? decodeURIComponent(morceau.split('=')[1]) : '';
};

const entetes = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

const terminer = (nouvelEtat, texte) => {
    actif = false;
    etat.value = nouvelEtat;
    message.value = texte;
    fermerConnexion();
};

/** Envoie un signal ; la même clé est renvoyée en cas de nouvel essai (pas de doublon côté serveur). */
const envoyer = async (type, charge) => {
    const corps = JSON.stringify({ type, charge, cle: nouvelleCle() });
    for (let essai = 0; essai < 4 && actif; essai += 1) {
        try {
            const reponse = await fetch(props.signaux, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { ...entetes, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': jetonXsrf() },
                body: corps,
            });
            if (reponse.ok) return true;
            if (reponse.status === 403) {
                const donnees = await reponse.json().catch(() => ({}));
                terminer('close', donnees.message ?? 'La salle de consultation est fermée.');
                return false;
            }
            if (reponse.status === 422) return false;
        } catch {
            // Réseau coupé : nouvel essai, même clé.
        }
        await attendre(800 * (essai + 1));
    }
    return false;
};

// --- Connexion WebRTC -----------------------------------------------------

const fermerConnexion = () => {
    if (!pc) return;
    pc.onicecandidate = null;
    pc.ontrack = null;
    pc.oniceconnectionstatechange = null;
    pc.close();
    pc = null;
    expediteurVideo = null;
    etatIce.value = 'ferme';
    if (videoDistante.value) videoDistante.value.srcObject = null;
};

const creerConnexion = () => {
    fermerConnexion();
    pc = new RTCPeerConnection({ iceServers: props.iceServers });
    candidatsEnAttente = [];
    mesurePrecedente = null;
    echantillonsFaibles = 0;

    for (const piste of fluxLocal.getTracks()) {
        const expediteur = pc.addTrack(piste, fluxLocal);
        if (piste.kind === 'video') {
            expediteurVideo = expediteur;
            if (audioSeul.value) expediteur.replaceTrack(null);
        }
    }

    pc.onicecandidate = (evenement) => {
        if (evenement.candidate && evenement.candidate.candidate) envoyer('candidat', evenement.candidate.toJSON());
    };
    pc.ontrack = (evenement) => {
        if (videoDistante.value) videoDistante.value.srcObject = evenement.streams[0] ?? new MediaStream([evenement.track]);
    };
    pc.oniceconnectionstatechange = surEtatIce;

    return pc;
};

const surEtatIce = () => {
    if (!pc) return;
    etatIce.value = pc.iceConnectionState;
    if (['connected', 'completed'].includes(pc.iceConnectionState)) {
        clearTimeout(minuteurReconnexion);
        etat.value = 'connecte';
        message.value = '';
    } else if (pc.iceConnectionState === 'disconnected') {
        // TLC-04 : une coupure brève se rattrape souvent seule ; sinon on relance.
        etat.value = 'reconnexion';
        clearTimeout(minuteurReconnexion);
        minuteurReconnexion = setTimeout(relancer, 4000);
    } else if (pc.iceConnectionState === 'failed') {
        etat.value = 'reconnexion';
        relancer();
    }
};

/** TLC-04 : nouvelle offre (médecin) ou demande d'offre (patient), sans quitter la consultation. */
const relancer = () => {
    if (!actif || etat.value === 'quitte') return;
    if (pc && ['connected', 'completed'].includes(pc.iceConnectionState)) return;
    if (estMedecin) nouvelleOffre();
    else envoyer('pret', {});
};

const nouvelleOffre = async () => {
    derniereOffre = Date.now();
    creerConnexion();
    if (etat.value !== 'reconnexion') etat.value = 'attente';
    const offre = await pc.createOffer();
    await pc.setLocalDescription(offre);
    await envoyer('offre', { type: 'offer', sdp: pc.localDescription.sdp });
};

const ajouterCandidat = async (charge) => {
    try {
        await pc.addIceCandidate(charge);
    } catch {
        // Candidat d'une connexion précédente : sans objet.
    }
};

const viderCandidats = async () => {
    const enAttente = candidatsEnAttente;
    candidatsEnAttente = [];
    for (const charge of enAttente) await ajouterCandidat(charge);
};

const traiter = async (signal) => {
    const { type, charge } = signal;
    if (type === 'offre' && !estMedecin) {
        // Toujours une connexion neuve : le médecin a pu recharger sa page.
        creerConnexion();
        await pc.setRemoteDescription(charge);
        const reponse = await pc.createAnswer();
        await pc.setLocalDescription(reponse);
        if (etat.value !== 'reconnexion') etat.value = 'connexion';
        await envoyer('reponse', { type: 'answer', sdp: pc.localDescription.sdp });
    } else if (type === 'reponse' && estMedecin) {
        if (!pc || pc.signalingState !== 'have-local-offer') return; // réponse à une offre dépassée
        await pc.setRemoteDescription(charge);
        etat.value = etat.value === 'reconnexion' ? 'reconnexion' : 'connexion';
        await viderCandidats();
    } else if (type === 'candidat') {
        if (!pc) return;
        if (!pc.remoteDescription) candidatsEnAttente.push(charge);
        else await ajouterCandidat(charge);
    } else if (type === 'pret' && estMedecin) {
        // Une offre toute récente attend déjà sa réponse : pas de seconde offre.
        if (pc && pc.signalingState === 'have-local-offer' && Date.now() - derniereOffre < 5000) return;
        await nouvelleOffre();
    } else if (type === 'raccroche') {
        fermerConnexion();
        etat.value = 'attente';
        message.value = `${autre.value.charAt(0).toUpperCase()}${autre.value.slice(1)} a quitté l'appel. Vous pouvez attendre son retour ici.`;
    } else if (type === 'audio_seul') {
        await basculerAudioSeul(charge.actif, charge.raison, false);
    }
};

/** Ne garde, d'un lot de signaux, que ce qui suit la dernière offre (côté patient). */
const utiles = (signaux) => {
    if (estMedecin) return signaux;
    let depart = 0;
    signaux.forEach((s, i) => { if (s.type === 'offre') depart = i; });
    return signaux.filter((s, i) => i >= depart || !['offre', 'candidat'].includes(s.type));
};

const interroger = async () => {
    if (!actif) return;
    let encore = false;
    try {
        const reponse = await fetch(`${props.signaux}?apres=${dernier}`, { credentials: 'same-origin', headers: entetes });
        if (reponse.status === 403) {
            const donnees = await reponse.json().catch(() => ({}));
            terminer('close', donnees.message ?? 'La salle de consultation est fermée.');
            return;
        }
        if (reponse.ok) {
            const donnees = await reponse.json();
            if (donnees.signaux.length) dernier = donnees.signaux[donnees.signaux.length - 1].id;
            for (const signal of utiles(donnees.signaux)) {
                try {
                    await traiter(signal);
                } catch {
                    // Un signal inexploitable n'arrête pas l'appel.
                }
            }
            encore = donnees.signaux.length >= 100;
        }
    } catch {
        // Réseau coupé : on réessaie au tour suivant.
    }
    minuteurInterrogation = setTimeout(interroger, encore ? 0 : props.intervalleMs);
};

// --- TLC-02 : audio seul ---------------------------------------------------

const basculerAudioSeul = async (activer, raison, prevenir = true) => {
    if (activer === audioSeul.value) return;
    envoiEnCours.value = true;
    audioSeul.value = activer;
    raisonAudioSeul.value = activer ? raison : '';
    const pisteVideo = fluxLocal?.getVideoTracks()[0] ?? null;
    if (expediteurVideo) await expediteurVideo.replaceTrack(activer || !camera.value ? null : pisteVideo);
    echantillonsFaibles = 0;
    mesurePrecedente = null;
    if (prevenir) await envoyer('audio_seul', { actif: activer, raison });
    envoiEnCours.value = false;
};

/** Débit vidéo reçu (getStats) : sous le seuil trois mesures de suite, on passe en audio seul. */
const mesurer = async () => {
    if (!pc || audioSeul.value || etat.value !== 'connecte') return;
    let octets = null;
    const rapport = await pc.getStats();
    rapport.forEach((r) => {
        if (r.type === 'inbound-rtp' && r.kind === 'video') octets = (octets ?? 0) + (r.bytesReceived ?? 0);
    });
    if (octets === null) return;
    const instant = performance.now();
    if (mesurePrecedente) {
        const kbps = ((octets - mesurePrecedente.octets) * 8) / (instant - mesurePrecedente.instant);
        // 0 : l'autre a coupé sa caméra, ce n'est pas une connexion faible.
        echantillonsFaibles = kbps > 0 && kbps < props.seuilVideoKbps ? echantillonsFaibles + 1 : 0;
        if (echantillonsFaibles >= 3) await basculerAudioSeul(true, 'debit');
    }
    mesurePrecedente = { octets, instant };
};

// --- Commandes ------------------------------------------------------------

const basculerMicro = () => {
    micro.value = !micro.value;
    fluxLocal?.getAudioTracks().forEach((p) => { p.enabled = micro.value; });
};

const basculerCamera = async () => {
    camera.value = !camera.value;
    const pisteVideo = fluxLocal?.getVideoTracks()[0] ?? null;
    if (expediteurVideo && !audioSeul.value) await expediteurVideo.replaceTrack(camera.value ? pisteVideo : null);
};

const raccrocher = async () => {
    envoiEnCours.value = true;
    await envoyer('raccroche', {});
    fermerConnexion();
    etat.value = 'quitte';
    message.value = '';
    envoiEnCours.value = false;
};

const rejoindre = async () => {
    envoiEnCours.value = true;
    message.value = '';
    if (estMedecin) await nouvelleOffre();
    else {
        etat.value = 'attente';
        await envoyer('pret', {});
    }
    envoiEnCours.value = false;
};

// --- Cycle de vie ---------------------------------------------------------

const obtenirMedias = async () => {
    const contraintesVideo = { width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 15, max: 24 } };
    try {
        return await navigator.mediaDevices.getUserMedia({ audio: true, video: contraintesVideo });
    } catch {
        cameraDisponible.value = false;
        camera.value = false;
        return navigator.mediaDevices.getUserMedia({ audio: true });
    }
};

onMounted(async () => {
    if (!window.RTCPeerConnection || !navigator.mediaDevices?.getUserMedia) {
        etat.value = 'erreur';
        message.value = 'Ce navigateur ne permet pas l\'appel vidéo. Utilisez Chrome ou Firefox à jour.';
        return;
    }
    try {
        fluxLocal = await obtenirMedias();
    } catch {
        etat.value = 'erreur';
        message.value = 'Autorisez l\'accès au micro (et à la caméra) pour cette page, puis rechargez-la.';
        return;
    }
    if (videoLocale.value) videoLocale.value.srcObject = fluxLocal;
    if (!cameraDisponible.value) message.value = 'Caméra indisponible : l\'appel se fait en audio.';

    interroger();
    minuteurMesure = setInterval(mesurer, 2000);
    window.addEventListener('online', relancer);

    if (estMedecin) await nouvelleOffre();
    else {
        etat.value = 'attente';
        await envoyer('pret', {});
    }
});

onBeforeUnmount(() => {
    actif = false;
    clearTimeout(minuteurInterrogation);
    clearTimeout(minuteurReconnexion);
    clearInterval(minuteurMesure);
    window.removeEventListener('online', relancer);
    fermerConnexion();
    fluxLocal?.getTracks().forEach((p) => p.stop());
});

const bandeau = computed(() => ({
    preparation: 'Préparation du micro et de la caméra…',
    attente: estMedecin ? `En attente de ${autre.value}…` : 'Salle d\'attente : le médecin va vous rejoindre.',
    connexion: 'Connexion en cours…',
    connecte: '',
    reconnexion: 'Connexion perdue, reconnexion…',
    quitte: 'Vous avez quitté l\'appel.',
    close: '',
    erreur: '',
}[etat.value]));
</script>

<template>
    <div class="appel" :data-etat="etat" :data-etat-ice="etatIce" :data-audio-seul="audioSeul ? 'oui' : 'non'">
        <p v-if="bandeau" class="rounded-lg px-4 py-3 text-sm font-medium" role="status" aria-live="polite"
           :class="etat === 'reconnexion' ? 'bg-amber-50 text-amber-900' : 'bg-marque-50 text-marque-900'">
            {{ bandeau }}
        </p>
        <p v-if="message" class="mt-2 rounded-lg px-4 py-3 text-sm" role="alert"
           :class="['close', 'erreur'].includes(etat) ? 'bg-red-50 text-red-800' : 'bg-slate-100 text-slate-800'">
            {{ message }}
        </p>
        <p v-if="audioSeul" class="mt-2 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            Appel en audio seul{{ raisonAudioSeul === 'debit' ? ' : la connexion est trop faible pour la vidéo' : '' }}.
        </p>

        <div class="relative mt-3 aspect-[4/3] overflow-hidden rounded-lg bg-slate-900">
            <video ref="videoDistante" class="h-full w-full object-cover" autoplay playsinline aria-label="Image de votre correspondant" />
            <p v-if="etat !== 'connecte' || audioSeul" class="absolute inset-0 flex items-center justify-center p-4 text-center text-white">
                {{ audioSeul && etat === 'connecte' ? 'Audio seul' : (etat === 'connecte' ? '' : 'En attente de l\'image') }}
            </p>
            <video v-show="camera && !audioSeul" ref="videoLocale" class="absolute bottom-2 right-2 w-1/3 rounded border-2 border-white object-cover"
                   autoplay playsinline muted aria-label="Votre image" />
        </div>

        <div v-if="!['close', 'erreur'].includes(etat)" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <button type="button" class="bouton-secondaire" :aria-pressed="!micro" @click="basculerMicro">
                {{ micro ? 'Couper le micro' : 'Activer le micro' }}
            </button>
            <button type="button" class="bouton-secondaire" :disabled="!cameraDisponible || audioSeul" :aria-pressed="!camera" @click="basculerCamera">
                {{ camera ? 'Couper la caméra' : 'Activer la caméra' }}
            </button>
            <button type="button" class="bouton-secondaire" :disabled="envoiEnCours || etat === 'quitte'"
                    @click="basculerAudioSeul(!audioSeul, 'manuel')">
                {{ audioSeul ? 'Rétablir la vidéo' : 'Audio seul' }}
            </button>
            <button v-if="etat !== 'quitte'" type="button" class="bouton bg-red-700 hover:bg-red-800" :disabled="envoiEnCours" @click="raccrocher">
                Raccrocher
            </button>
            <button v-else type="button" class="bouton" :disabled="envoiEnCours" @click="rejoindre">Rejoindre de nouveau</button>
        </div>

        <p class="mt-3 text-xs text-slate-500">
            Cet appel n'est jamais enregistré, ni le son ni l'image. Il passe directement entre vous et votre correspondant, chiffré.
        </p>

        <!-- Emplacement réservé au lot suivant (développeur B) : dossier du
             patient, pièces jointes, messagerie (TLC-03) et boutons de fin de
             consultation. Laissé vide dans ce lot. -->
        <slot />
    </div>
</template>
