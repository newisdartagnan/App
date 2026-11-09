<script setup>
// Tuile de chiffre colorée (tableaux de bord) : un nombre, son libellé, un
// pictogramme. Lien facultatif (toute la tuile est cliquable, cible ≥ 44 px).
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Pictogramme from './Pictogramme.vue';

const props = defineProps({
    valeur: { type: [String, Number], default: '—' },
    libelle: { type: String, required: true },
    detail: { type: String, default: null },
    pictogramme: { type: String, default: 'graphique' },
    couleur: { type: String, default: 'marque' }, // marque, vert, ambre, rouge
    href: { type: String, default: null },
});

const teintes = {
    marque: { bande: 'bg-marque-600', pastille: 'bg-marque-50 text-marque-700', chiffre: 'text-marque-800' },
    vert: { bande: 'bg-vert-600', pastille: 'bg-vert-50 text-vert-700', chiffre: 'text-vert-800' },
    ambre: { bande: 'bg-amber-500', pastille: 'bg-amber-50 text-amber-800', chiffre: 'text-amber-900' },
    rouge: { bande: 'bg-red-600', pastille: 'bg-red-50 text-red-700', chiffre: 'text-red-800' },
};
const t = computed(() => teintes[props.couleur] ?? teintes.marque);
</script>

<template>
    <component :is="href ? Link : 'div'" :href="href ?? undefined"
               class="carte group relative flex min-h-24 items-center gap-4 overflow-hidden p-4 transition-shadow"
               :class="href ? 'hover:shadow-md focus-visible:shadow-md' : ''">
        <span class="absolute inset-y-0 left-0 w-1.5" :class="t.bande" aria-hidden="true" />
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" :class="t.pastille">
            <Pictogramme :nom="pictogramme" :taille="24" />
        </span>
        <span class="min-w-0">
            <span class="block text-3xl leading-tight font-bold tabular-nums" :class="t.chiffre">{{ valeur }}</span>
            <span class="block text-sm font-medium text-slate-700">{{ libelle }}</span>
            <span v-if="detail" class="block text-xs text-slate-600">{{ detail }}</span>
        </span>
        <span v-if="href" class="ml-auto text-slate-400 transition-transform group-hover:translate-x-0.5" aria-hidden="true">
            <Pictogramme nom="fleche" />
        </span>
    </component>
</template>
