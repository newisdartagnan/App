<script setup>
import { Head } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ reversements: Array });
</script>

<template>
    <Head title="Mes reversements" />
    <GabaritPersonnel navigation>
        <h1 class="text-2xl font-bold">Mes reversements</h1>
        <p class="mt-2 text-slate-700">Vos honoraires par période et par devise : encaissements moins remboursements, moins la commission de la plateforme.</p>

        <ul class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="r in reversements" :key="r.id" class="px-4 py-3">
                <div class="flex justify-between gap-2"><strong>{{ r.periode }}</strong><span :class="r.paye ? 'text-vert-700' : 'text-amber-800'">{{ r.statut }}</span></div>
                <p>Brut {{ r.brut }} − commission {{ r.commission }} = <strong>{{ r.net }}</strong></p>
                <p v-if="r.paye" class="text-slate-600">Virement {{ r.referenceVirement }}, le {{ r.payeLe }}</p>
            </li>
            <li v-if="reversements.length === 0" class="px-4 py-3 text-slate-500">Aucun reversement pour le moment.</li>
        </ul>
    </GabaritPersonnel>
</template>
