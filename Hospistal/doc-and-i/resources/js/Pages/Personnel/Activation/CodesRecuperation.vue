<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ codes: Array });
</script>

<template>
    <Head title="Codes de récupération" />
    <GabaritPersonnel>
        <h1 class="text-2xl font-bold">Vos codes de récupération</h1>
        <p class="mt-2 text-slate-700">
            Si vous perdez votre téléphone, chacun de ces codes remplace <strong>une fois</strong> le code de l'application.
            Notez-les sur papier et rangez-les en lieu sûr. <strong>Ils ne seront plus jamais affichés.</strong>
        </p>

        <ul class="mt-6 grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-white p-4 font-mono text-lg tracking-wider">
            <li v-for="code in codes" :key="code" class="text-center">{{ code }}</li>
        </ul>

        <Link href="/personnel" class="bouton mt-6">J'ai noté mes codes, continuer</Link>
    </GabaritPersonnel>
</template>
