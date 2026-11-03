<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';

defineProps({ prenom: String });
</script>

<template>
    <Head title="Mon espace" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Bonjour {{ prenom }}</h1>

        <div class="mt-6 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Demander une consultation</h2>
            <p class="mt-1 text-sm text-slate-600">Bientôt disponible : choix du médecin, du créneau et paiement Mobile Money.</p>
            <button type="button" class="bouton mt-4" disabled>Demander une consultation</button>
        </div>

        <nav class="mt-6 space-y-3">
            <Link href="/profil" class="bouton-secondaire">Mon profil</Link>
            <Link href="/consentement" class="bouton-secondaire">Mes accords</Link>
        </nav>
    </Gabarit>
</template>
