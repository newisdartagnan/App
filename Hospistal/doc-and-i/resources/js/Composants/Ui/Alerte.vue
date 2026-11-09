<script setup>
// Message d'état : info, succès, attention ou erreur. Les erreurs sont
// annoncées tout de suite (role="alert"), le reste poliment (role="status").
import { computed } from 'vue';
import Pictogramme from './Pictogramme.vue';

const props = defineProps({
    ton: { type: String, default: 'info' }, // info, succes, attention, erreur
    titre: { type: String, default: null },
});

const tons = {
    info: { classes: 'border-marque-200 bg-marque-50 text-marque-900', picto: 'info' },
    succes: { classes: 'border-vert-200 bg-vert-50 text-vert-900', picto: 'coche' },
    attention: { classes: 'border-amber-300 bg-amber-50 text-amber-900', picto: 'alerte' },
    erreur: { classes: 'border-red-200 bg-red-50 text-red-900', picto: 'alerte' },
};
const t = computed(() => tons[props.ton] ?? tons.info);
</script>

<template>
    <div class="flex gap-3 rounded-xl border px-4 py-3 text-sm" :class="t.classes" :role="ton === 'erreur' ? 'alert' : 'status'">
        <Pictogramme :nom="t.picto" class="mt-0.5" />
        <div class="min-w-0">
            <p v-if="titre" class="font-semibold">{{ titre }}</p>
            <div><slot /></div>
        </div>
    </div>
</template>
