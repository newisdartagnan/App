<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ tauxDuJour: String, aFaire: Object });
</script>

<template>
    <Head title="Caisse" />
    <GabaritPersonnel navigation espace="caisse">
        <h1 class="text-2xl font-bold">Caisse</h1>

        <p v-if="!tauxDuJour" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            Pas de taux du jour : les patients ne peuvent payer qu'en dollars.
            <Link href="/personnel/caisse/taux" class="font-semibold underline">Saisir le taux</Link>
        </p>
        <p v-else class="mt-4 rounded-lg border border-vert-200 bg-vert-50 px-4 py-3 text-sm text-vert-900" role="status">
            Taux du jour : 1 USD = {{ tauxDuJour }} CDF.
        </p>

        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <Link href="/personnel/caisse/remboursements" class="rounded-lg border border-slate-200 bg-white p-4">
                <span class="block text-3xl font-bold text-marque-700">{{ aFaire.remboursements }}</span>
                <span class="text-sm text-slate-700">remboursements à valider</span>
            </Link>
            <Link href="/personnel/caisse/rapprochement" class="rounded-lg border border-slate-200 bg-white p-4">
                <span class="block text-3xl font-bold text-marque-700">{{ aFaire.ecarts }}</span>
                <span class="text-sm text-slate-700">écarts de rapprochement</span>
            </Link>
            <Link href="/personnel/caisse/reversements" class="rounded-lg border border-slate-200 bg-white p-4">
                <span class="block text-3xl font-bold text-marque-700">{{ aFaire.reversements }}</span>
                <span class="text-sm text-slate-700">reversements à payer</span>
            </Link>
        </div>
    </GabaritPersonnel>
</template>
