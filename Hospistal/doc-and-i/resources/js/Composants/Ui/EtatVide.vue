<script setup>
// État vide : rien à montrer, dit simplement, avec une action facultative.
import Pictogramme from './Pictogramme.vue';

defineProps({
    titre: { type: String, required: true },
    texte: { type: String, default: null },
    pictogramme: { type: String, default: 'info' },
});
</script>

<template>
    <div class="flex flex-col items-center rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center">
        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500">
            <Pictogramme :nom="pictogramme" :taille="24" />
        </span>
        <p class="mt-3 font-semibold text-slate-800">{{ titre }}</p>
        <p v-if="texte" class="mt-1 max-w-sm text-sm text-slate-600">{{ texte }}</p>
        <div v-if="$slots.default" class="mt-4 w-full max-w-xs"><slot /></div>
    </div>
</template>
