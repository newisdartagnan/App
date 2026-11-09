<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';

defineProps({ etablissement: String, telephone: String, consigne: String });
</script>

<template>
    <Head title="Urgence" />
    <Gabarit>
        <div class="rounded-xl border-2 border-red-700 bg-red-50 p-5" role="alert">
            <h1 class="text-2xl font-bold text-red-800">Allez aux urgences maintenant</h1>
            <p class="mt-3 text-lg text-red-900">
                Vos réponses montrent un signe de danger. Une consultation à distance ne suffit pas.
            </p>
            <p class="mt-3 text-lg font-semibold text-red-900">{{ consigne }}</p>
            <p v-if="etablissement" class="mt-2 text-red-900">{{ etablissement }}</p>
            <a v-if="telephone" :href="`tel:${telephone}`" class="bouton mt-4 bg-red-700 hover:bg-red-800">
                Appeler le {{ telephone }}
            </a>
            <p class="mt-4 text-red-900">Ne restez pas seul : demandez à un proche de vous accompagner.</p>
        </div>

        <p class="mt-6 text-sm text-slate-600">Aucun rendez-vous en ligne ne peut être pris pour cette demande.</p>
        <Link href="/espace" class="bouton-secondaire mt-4">Retour à mon espace</Link>
    </Gabarit>
</template>
