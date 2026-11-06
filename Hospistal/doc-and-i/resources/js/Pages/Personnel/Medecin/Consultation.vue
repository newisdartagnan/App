<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppelVideo from '../../../Composants/AppelVideo.vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ rendezVous: Object, appel: Object });
</script>

<template>
    <Head title="Consultation" />
    <GabaritPersonnel navigation>
        <h1 class="text-xl font-bold">Consultation : {{ rendezVous.patient }}</h1>
        <p class="text-sm text-slate-600">{{ rendezVous.heure }}, heure de Kinshasa.</p>

        <AppelVideo class="mt-4" :role="appel.role" :signaux="appel.signaux" :ice-servers="appel.iceServers"
                    :dernier-signal="appel.dernierSignal" :intervalle-ms="appel.intervalleMs"
                    :seuil-video-kbps="appel.seuilVideoKbps" :correspondant="rendezVous.patient">
            <!-- Lot suivant (développeur B) : dossier du patient, pièces jointes,
                 messagerie (TLC-03), fin de consultation et absences (RG-06). -->
        </AppelVideo>

        <Link href="/personnel/medecin/agenda" class="bouton-secondaire mt-6">Retour à l'agenda</Link>
    </GabaritPersonnel>
</template>
