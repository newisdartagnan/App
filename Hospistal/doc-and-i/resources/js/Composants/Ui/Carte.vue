<script setup>
// Carte blanche arrondie, ombre légère. Titre et action facultatifs ; le
// contenu va dans le créneau par défaut. `tag` pour une section, un article…
import Pictogramme from './Pictogramme.vue';

defineProps({
    titre: { type: String, default: null },
    sousTitre: { type: String, default: null },
    pictogramme: { type: String, default: null },
    tag: { type: String, default: 'section' },
    // Sans marge intérieure : pour une liste ou un tableau bord à bord.
    plein: { type: Boolean, default: false },
});
</script>

<template>
    <component :is="tag" class="carte" :class="plein ? 'overflow-hidden' : 'p-4 sm:p-5'">
        <header v-if="titre || $slots.action" class="flex items-start justify-between gap-3"
                :class="plein ? 'px-4 pt-4 sm:px-5' : ''">
            <div class="flex min-w-0 items-start gap-2">
                <span v-if="pictogramme" class="mt-0.5 text-marque-700"><Pictogramme :nom="pictogramme" /></span>
                <div class="min-w-0">
                    <h2 v-if="titre" class="titre-section">{{ titre }}</h2>
                    <p v-if="sousTitre" class="mt-0.5 text-sm text-slate-600">{{ sousTitre }}</p>
                </div>
            </div>
            <slot name="action" />
        </header>
        <div :class="[titre || $slots.action ? 'mt-3' : '', plein && (titre || $slots.action) ? 'border-t border-slate-200' : '']">
            <slot />
        </div>
    </component>
</template>
