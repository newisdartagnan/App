<script setup>
// Badge de statut : ton neutre, info (marque), succès (vert), attention
// (ambre), danger (rouge). Le texte porte le sens, la couleur l'accompagne.
import { computed } from 'vue';

const props = defineProps({
    ton: { type: String, default: 'neutre' },
});

const tons = {
    neutre: 'bg-slate-100 text-slate-700 ring-slate-200',
    info: 'bg-marque-50 text-marque-800 ring-marque-200',
    succes: 'bg-vert-50 text-vert-900 ring-vert-200',
    attention: 'bg-amber-50 text-amber-900 ring-amber-200',
    danger: 'bg-red-50 text-red-900 ring-red-200',
};
const classes = computed(() => tons[props.ton] ?? tons.neutre);
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap ring-1 ring-inset" :class="classes">
        <slot />
    </span>
</template>
