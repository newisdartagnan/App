<script setup>
import { Head } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

defineProps({ reversements: Array });
</script>

<template>
    <Head title="Mes reversements" />
    <GabaritPersonnel navigation>
        <EnTeteDePage titre="Mes reversements" sous-titre="Vos honoraires par période et par devise : encaissements moins remboursements, moins la commission de la plateforme." pictogramme="portefeuille" />

        <ul class="mt-6 divide-y divide-slate-200 carte text-sm">
            <li v-for="r in reversements" :key="r.id" class="px-4 py-3">
                <div class="flex justify-between gap-2"><strong>{{ r.periode }}</strong><span :class="r.paye ? 'text-vert-700' : 'text-amber-800'">{{ r.statut }}</span></div>
                <p>Solde de la période {{ r.solde }}<span v-if="r.detteReprise"> − remboursements déjà reversés {{ r.detteReprise }}</span></p>
                <p>Brut {{ r.brut }} − commission {{ r.commission }} = <strong>{{ r.net }}</strong></p>
                <p v-if="r.detteReportee" class="text-red-700">Reste à déduire du prochain reversement : {{ r.detteReportee }}</p>
                <p v-if="r.paye" class="text-slate-600">Virement {{ r.referenceVirement }}, le {{ r.payeLe }}</p>
            </li>
            <li v-if="reversements.length === 0" class="px-4 py-3 text-slate-500">Aucun reversement pour le moment.</li>
        </ul>
    </GabaritPersonnel>
</template>
