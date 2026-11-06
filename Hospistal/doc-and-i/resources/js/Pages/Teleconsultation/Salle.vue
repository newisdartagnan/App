<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppelVideo from '../../Composants/AppelVideo.vue';
import Gabarit from '../../Composants/Gabarit.vue';

defineProps({ rendezVous: Object, appel: Object });
</script>

<template>
    <Head title="Consultation" />
    <Gabarit>
        <h1 class="text-xl font-bold">Consultation avec {{ rendezVous.medecin }}</h1>
        <p class="text-sm text-slate-600 first-letter:uppercase">{{ rendezVous.quand }} · {{ rendezVous.type }}</p>

        <AppelVideo class="mt-4" :role="appel.role" :signaux="appel.signaux" :ice-servers="appel.iceServers"
                    :dernier-signal="appel.dernierSignal" :intervalle-ms="appel.intervalleMs"
                    :seuil-video-kbps="appel.seuilVideoKbps" :correspondant="rendezVous.medecin" />

        <Link :href="`/mes-rendez-vous/${rendezVous.id}`" class="bouton-secondaire mt-6">Retour au rendez-vous</Link>
    </Gabarit>
</template>
