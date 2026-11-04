<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
</script>

<template>
    <Head title="Lien d'activation" />
    <GabaritPersonnel>
        <h1 class="text-2xl font-bold">Ce lien d'activation ne fonctionne pas</h1>
        <p class="mt-2 text-slate-700">
            Il a peut-être expiré (72 heures), déjà servi, ou été remplacé par un lien plus récent.
            Demandez un nouveau lien à l'administrateur de Doc&amp;I.
        </p>
        <p class="mt-2 text-slate-700">Si votre compte est déjà activé, connectez-vous.</p>
        <Link href="/personnel/connexion" class="bouton mt-6">Connexion du personnel</Link>
    </GabaritPersonnel>
</template>
