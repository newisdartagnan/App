<script setup>
// Pictogrammes en SVG intégré : aucune police d'icônes ni image d'un autre
// domaine (CSP stricte), quelques octets chacun. Trait de 1,8 px, couleur du
// texte (currentColor) : ils suivent le thème. Décoratifs par défaut
// (aria-hidden) ; donner `titre` quand le pictogramme porte seul un sens.
import { computed } from 'vue';

const props = defineProps({
    nom: { type: String, required: true },
    taille: { type: [Number, String], default: 20 },
    titre: { type: String, default: null },
});

// Chemins dessinés sur une grille de 24 × 24.
const chemins = {
    accueil: 'M3 10.5 12 3l9 7.5M5 9v11h5v-6h4v6h5V9',
    calendrier: 'M7 3v3m10-3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm3 7h2m3 0h2m-7 4h2m3 0h2',
    horloge: 'M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    absence: 'M7 3v3m10-3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm5 7 4 4m0-4-4 4',
    portefeuille: 'M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7Zm0 0 12-3v3m-1 7h2',
    taux: 'M7 7h11l-3-3m3 13H6l3 3',
    remboursement: 'M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
    journal: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
    balance: 'M12 4v16m-7 0h14M6 8h12M6 8l-3 6a3 3 0 0 0 6 0L6 8Zm12 0-3 6a3 3 0 0 0 6 0l-3-6Z',
    facture: 'M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm7 0v5h5M9 13h6m-6 4h6',
    utilisateurs: 'M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1m18 0v-1a4 4 0 0 0-3-3.87M15 4.13a4 4 0 0 1 0 7.75M13.5 7.5a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    etiquette: 'M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9Zm5-4h.01',
    batiment: 'M4 21V5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v16m0-10h5a1 1 0 0 1 1 1v9M3 21h18M8 8h2m-2 4h2m-2 4h2',
    graphique: 'M4 20V4m0 16h16M8 16v-4m4 4V8m4 8v-6',
    dossier: 'M3 7a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7Zm9 3v6m-3-3h6',
    ordonnance: 'M10.5 20.5a4.95 4.95 0 0 1-7-7l6-6a4.95 4.95 0 0 1 7 7l-6 6ZM7 10l7 7',
    carte: 'M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Zm0 3h18M7 15h3',
    profil: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0',
    deconnexion: 'M15 4h3a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-3M10 16l4-4-4-4m4 4H4',
    soleil: 'M12 3v2m0 14v2m9-9h-2M5 12H3m15.36-6.36-1.41 1.41M7.05 16.95l-1.41 1.41m12.72 0-1.41-1.41M7.05 7.05 5.64 5.64M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    lune: 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z',
    tasse: 'M5 9h11v5a5 5 0 0 1-5 5h-1a5 5 0 0 1-5-5V9Zm11 1h1.5a2.5 2.5 0 0 1 0 5H16M8 3v3m3-3v3',
    video: 'M15 10l5-3v10l-5-3M4 6h11v12H4z',
    stethoscope: 'M6 3v6a4 4 0 0 0 8 0V3M10 13v2a5 5 0 0 0 10 0v-2m0 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z',
    maison: 'M3 11 12 4l9 7M6 9.5V20h12V9.5M10 20v-5h4v5',
    telephone: 'M8 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Zm3 15h2',
    bouclier: 'M12 3 5 6v6c0 4 3 7.5 7 9 4-1.5 7-5 7-9V6l-7-3Zm-3 9 2 2 4-4',
    coche: 'M5 12.5 10 17l9-10',
    alerte: 'M12 9v4m0 4h.01M10.3 4.3 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z',
    info: 'M12 11v5m0-8h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    plus: 'M12 5v14m-7-7h14',
    fleche: 'M5 12h14m-5-5 5 5-5 5',
    menu: 'M4 6h16M4 12h16M4 18h16',
    message: 'M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4V6a1 1 0 0 1 1-1Z',
    coeur: 'M12 20s-7-4.35-9-9a4.5 4.5 0 0 1 9-2 4.5 4.5 0 0 1 9 2c-2 4.65-9 9-9 9Z',
    cle: 'M15 7a4 4 0 1 1-3.87 5H9v2H7v2H4v-3l6.13-6.13A4 4 0 0 1 15 7Zm1 0h.01',
    recherche: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm5-2 5 5',
};

const d = computed(() => chemins[props.nom] ?? chemins.info);
</script>

<template>
    <svg :width="taille" :height="taille" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
         stroke-linecap="round" stroke-linejoin="round" class="shrink-0"
         :role="titre ? 'img' : undefined" :aria-hidden="titre ? undefined : 'true'" :aria-label="titre ?? undefined"
         focusable="false">
        <path :d="d" />
    </svg>
</template>
