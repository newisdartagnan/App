<script setup>
// En-tête de page : titre (h1), sous-titre, actions à droite (en dessous sur
// téléphone), lien de retour facultatif.
import { Link } from '@inertiajs/vue3';
import Pictogramme from './Pictogramme.vue';

defineProps({
    titre: { type: String, required: true },
    sousTitre: { type: String, default: null },
    retour: { type: String, default: null },
    retourLibelle: { type: String, default: 'Retour' },
    pictogramme: { type: String, default: null },
});
</script>

<template>
    <header class="mb-5">
        <Link v-if="retour" :href="retour" class="mb-2 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-marque-700 hover:underline">
            <Pictogramme nom="fleche" class="rotate-180" :taille="16" /> {{ retourLibelle }}
        </Link>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
                <span v-if="pictogramme" class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-marque-50 text-marque-700 sm:flex">
                    <Pictogramme :nom="pictogramme" :taille="24" />
                </span>
                <div class="min-w-0">
                    <h1 class="text-2xl leading-tight font-bold text-slate-900">{{ titre }}</h1>
                    <p v-if="sousTitre || $slots.sousTitre" class="mt-1 text-sm text-slate-600"><slot name="sousTitre">{{ sousTitre }}</slot></p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap gap-2 sm:shrink-0"><slot name="actions" /></div>
        </div>
    </header>
</template>
