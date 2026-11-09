<script setup>
// PRE-08 : portail d'alertes du médecin (résultat critique, résultats à valider).
import { Head, Link, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../Composants/GabaritPersonnel.vue';

defineProps({ alertes: Array });

const action = useForm({});
const marquer = (a, etat) => action.post(`/personnel/medecin/alertes/${a.id}/${etat}`, { preserveScroll: true });
const couleur = (g) => ({ critique: 'border-red-300 bg-red-50', urgent: 'border-amber-300 bg-amber-50' }[g] ?? 'border-slate-200 bg-white');
</script>

<template>
    <Head title="Alertes" />
    <GabaritPersonnel navigation>
        <h1 class="text-2xl font-bold">Alertes</h1>
        <p v-if="alertes.length === 0" class="mt-6 text-slate-600">Aucune alerte ouverte.</p>
        <ul class="mt-4 space-y-2">
            <li v-for="a in alertes" :key="a.id" class="rounded-lg border p-3 text-sm" :class="couleur(a.gravite)">
                <p class="font-semibold" :class="{ 'text-red-800': a.gravite === 'critique' }">{{ a.message }}</p>
                <p class="text-slate-700">{{ a.patient }} · {{ a.creeeLe }}<template v-if="a.escaladee"> · signalée au coordinateur</template></p>
                <div class="mt-2 flex flex-wrap gap-3">
                    <Link v-if="a.bonId" :href="`/personnel/medecin/resultats/bons/${a.bonId}`" class="text-marque-700 underline">Voir les résultats</Link>
                    <button v-if="!a.lue" type="button" class="underline" :disabled="action.processing" @click="marquer(a, 'lue')">Marquer lue</button>
                    <button type="button" class="text-vert-800 underline" :disabled="action.processing" @click="marquer(a, 'traitee')">Traitée</button>
                </div>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
