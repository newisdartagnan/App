<script setup>
// PRE-07 : bons dont les résultats attendent la validation du médecin.
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../Composants/GabaritPersonnel.vue';

defineProps({ bons: Array });
</script>

<template>
    <Head title="Résultats à valider" />
    <GabaritPersonnel navigation>
        <h1 class="text-2xl font-bold">Résultats à valider</h1>
        <p class="mt-2 text-sm text-slate-700">Le patient ne voit ses résultats qu'après votre validation.</p>

        <p v-if="bons.length === 0" class="mt-6 text-slate-600">Rien à valider.</p>
        <ul class="mt-4 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            <li v-for="b in bons" :key="b.id">
                <Link :href="`/personnel/medecin/resultats/bons/${b.id}`" class="block px-4 py-3">
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ b.patient }}</span>
                        <span v-if="b.critique" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-800">Critique</span>
                    </span>
                    <span class="block text-sm text-slate-600">Bon {{ b.numero }} · {{ b.laboratoire }} · {{ b.aValider }} à valider</span>
                </Link>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
