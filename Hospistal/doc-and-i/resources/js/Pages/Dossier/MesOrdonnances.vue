<script setup>
// PRE-01 : les ordonnances du patient, en PDF (les siennes seulement).
import { Head, Link } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';

defineProps({ ordonnances: Array });
</script>

<template>
    <Head title="Mes ordonnances" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Mes ordonnances</h1>
        <p class="mt-2 text-sm text-slate-600">Montrez le PDF au pharmacien : il vérifie l'ordonnance avec son QR code.</p>

        <p v-if="ordonnances.length === 0" class="mt-4 text-slate-600">Aucune ordonnance pour l'instant.</p>
        <a v-for="o in ordonnances" :key="o.id" :href="`/mes-ordonnances/${o.id}/pdf`"
           class="mt-3 flex items-center justify-between gap-2 rounded-lg bg-white p-4 shadow-sm">
            <span>
                <span class="block font-semibold text-marque-800 underline">Ordonnance {{ o.numero }}</span>
                <span class="block text-sm text-slate-600"><span class="first-letter:uppercase">{{ o.emise }}</span> · {{ o.medecin }}</span>
            </span>
            <span class="text-sm text-slate-600">{{ o.lignes }} médicament{{ o.lignes > 1 ? 's' : '' }}</span>
        </a>

        <Link href="/espace" class="bouton-secondaire mt-8">Retour à mon espace</Link>
    </Gabarit>
</template>
