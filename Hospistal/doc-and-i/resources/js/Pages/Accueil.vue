<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import Gabarit from '../Composants/Gabarit.vue';

const connecte = !!usePage().props.utilisateur;
</script>

<template>
    <Head title="La médecine vient à vous" />
    <Gabarit>
        <img src="/images/medihome-logo.webp" width="560" height="473"
             alt="MEDIHOME — La médecine vient à vous." class="mx-auto w-64 max-w-full">
        <h1 class="mt-6 text-2xl font-bold leading-tight">Consultez un médecin sans vous déplacer</h1>
        <p class="mt-2 text-slate-700">Réservez un créneau, payez par Mobile Money, et parlez à un médecin depuis votre téléphone.</p>

        <ol class="mt-6 space-y-3">
            <li class="rounded-lg bg-white p-4 shadow-sm"><span class="font-semibold">1. Décrivez votre besoin</span> et choisissez un créneau.</li>
            <li class="rounded-lg bg-white p-4 shadow-sm"><span class="font-semibold">2. Payez d'avance</span> par M-Pesa, Orange Money, Airtel Money ou carte.</li>
            <li class="rounded-lg bg-white p-4 shadow-sm"><span class="font-semibold">3. Consultez</span> en vidéo ou en audio, et recevez votre ordonnance et vos résultats.</li>
        </ol>

        <Link :href="connecte ? '/espace' : '/connexion'" class="bouton mt-6">
            {{ connecte ? 'Mon espace' : 'Commencer' }}
        </Link>

        <p class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            <strong>Urgence vitale ?</strong> Difficulté à respirer, douleur forte dans la poitrine, perte de connaissance, saignement important :
            n'attendez pas une consultation en ligne, rendez-vous immédiatement à l'hôpital le plus proche.
        </p>
    </Gabarit>
</template>
