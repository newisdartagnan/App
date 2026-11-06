<script setup>
import { Head } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
</script>

<template>
    <Head title="Administration" />
    <GabaritPersonnel navigation espace="admin">
        <h1 class="text-2xl font-bold">Administration</h1>
        <p class="mt-2 text-slate-700">Comptes du personnel, médecins, tarifs et partenaires de MediHome.</p>
    </GabaritPersonnel>
</template>
