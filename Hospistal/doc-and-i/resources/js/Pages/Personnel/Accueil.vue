<script setup>
import { Head } from '@inertiajs/vue3';
import GabaritPersonnel from '../../Composants/GabaritPersonnel.vue';

defineProps({ roles: Array });
</script>

<template>
    <Head title="Espace du personnel" />
    <GabaritPersonnel>
        <h1 class="text-2xl font-bold">Espace du personnel</h1>
        <p class="mt-2 text-slate-700">Vous êtes connecté ({{ roles.join(', ') }}).</p>
        <p class="mt-2 text-slate-700">Les écrans de votre rôle arrivent dans un prochain lot.</p>
    </GabaritPersonnel>
</template>
