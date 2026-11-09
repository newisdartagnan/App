<script setup>
// Choix du thème (clair, repos, sombre), dans le bandeau. Préférence
// d'affichage seulement : gardée dans le navigateur (localStorage) et dans un
// cookie que le serveur lit pour poser le thème avant l'affichage (pas de flash).
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Pictogramme from './Pictogramme.vue';

const THEMES = [
    { valeur: 'clair', libelle: 'Clair', picto: 'soleil' },
    { valeur: 'repos', libelle: 'Repos', picto: 'tasse' },
    { valeur: 'sombre', libelle: 'Sombre', picto: 'lune' },
];
const CLE = 'medihome_theme';

const courant = ref(document.documentElement.dataset.theme || 'clair');
const ouvert = ref(false);
const racine = ref(null);

const appliquer = (valeur) => {
    if (!THEMES.some((t) => t.valeur === valeur)) {
        return;
    }
    courant.value = valeur;
    document.documentElement.dataset.theme = valeur;
    document.cookie = `${CLE}=${valeur}; path=/; max-age=31536000; SameSite=Lax`;
    try { localStorage.setItem(CLE, valeur); } catch { /* navigation privée : le cookie suffit */ }
};

const choisir = (valeur) => { appliquer(valeur); ouvert.value = false; };

const fermerDehors = (evenement) => {
    if (ouvert.value && racine.value && !racine.value.contains(evenement.target)) {
        ouvert.value = false;
    }
};
const fermerEchap = (evenement) => { if (evenement.key === 'Escape') { ouvert.value = false; } };

onMounted(() => {
    // Cookie effacé mais préférence gardée : on la remet.
    let memorise = null;
    try { memorise = localStorage.getItem(CLE); } catch { /* rien */ }
    if (memorise && memorise !== courant.value && !document.cookie.includes(`${CLE}=`)) {
        appliquer(memorise);
    }
    document.addEventListener('click', fermerDehors);
    document.addEventListener('keydown', fermerEchap);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', fermerDehors);
    document.removeEventListener('keydown', fermerEchap);
});

const pictoCourant = () => THEMES.find((t) => t.valeur === courant.value)?.picto ?? 'soleil';
</script>

<template>
    <div ref="racine" class="relative">
        <button type="button" class="bandeau-bouton" :aria-expanded="ouvert" aria-haspopup="true"
                aria-label="Choisir le thème d'affichage" title="Thème d'affichage" @click="ouvert = !ouvert">
            <Pictogramme :nom="pictoCourant()" />
            <span class="hidden sm:inline">Thème</span>
        </button>
        <div v-if="ouvert" class="carte absolute right-0 z-40 mt-2 w-48 p-1.5 text-slate-800" role="group" aria-label="Thème d'affichage">
            <button v-for="t in THEMES" :key="t.valeur" type="button"
                    class="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-left text-sm hover:bg-slate-100"
                    :class="courant === t.valeur ? 'font-semibold text-marque-700' : ''"
                    :aria-pressed="courant === t.valeur" @click="choisir(t.valeur)">
                <Pictogramme :nom="t.picto" />
                <span class="flex-1">{{ t.libelle }}</span>
                <Pictogramme v-if="courant === t.valeur" nom="coche" :taille="16" />
            </button>
        </div>
    </div>
</template>
