<script setup>
// PRE-08, RG-10 : escalades vers le coordinateur.
import { Head, Link, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../Composants/GabaritPersonnel.vue';

defineProps({ escalades: Array });

const action = useForm({});
const traiter = (e) => action.post(`/personnel/coordination/escalades/${e.id}/traitee`, { preserveScroll: true });
</script>

<template>
    <Head title="Coordination" />
    <GabaritPersonnel>
        <h1 class="text-2xl font-bold">Coordination</h1>
        <p class="mt-2 text-sm text-slate-700">Résultats critiques non traités depuis 2 h, et résultats à valider depuis plus de 48 h (que vous pouvez valider à la place du médecin).</p>

        <p v-if="escalades.length === 0" class="mt-6 text-slate-600">Aucune escalade ouverte.</p>
        <ul class="mt-4 space-y-2">
            <li v-for="e in escalades" :key="e.id" class="rounded-lg border p-3 text-sm" :class="e.type === 'critique_non_traite' ? 'border-red-300 bg-red-50' : 'border-amber-300 bg-amber-50'">
                <p class="font-semibold">{{ e.message }}</p>
                <p class="text-slate-700">{{ e.patient }} · {{ e.medecin }}<template v-if="e.medecinTelephone"> ({{ e.medecinTelephone }})</template> · {{ e.creeeLe }}</p>
                <div class="mt-2 flex flex-wrap gap-3">
                    <Link :href="`/personnel/coordination/bons/${e.bonId}`" class="text-marque-700 underline">Voir le bon</Link>
                    <button type="button" class="text-vert-800 underline" :disabled="action.processing" @click="traiter(e)">Traitée</button>
                </div>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
