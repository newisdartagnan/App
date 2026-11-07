<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppelVideo from '../../Composants/AppelVideo.vue';
import FinConsultation from '../../Composants/Consultation/FinConsultation.vue';
import Messagerie from '../../Composants/Consultation/Messagerie.vue';
import Gabarit from '../../Composants/Gabarit.vue';

defineProps({ rendezVous: Object, appel: Object });

const appelVideo = ref(null);
const salleClose = () => appelVideo.value?.fermer();
</script>

<template>
    <Head title="Consultation" />
    <Gabarit>
        <h1 class="text-xl font-bold">Consultation avec {{ rendezVous.medecin }}</h1>
        <p class="text-sm text-slate-600 first-letter:uppercase">{{ rendezVous.quand }} · {{ rendezVous.type }}</p>

        <AppelVideo ref="appelVideo" class="mt-4" :role="appel.role" :signaux="appel.signaux" :ice-servers="appel.iceServers"
                    :dernier-signal="appel.dernierSignal" :intervalle-ms="appel.intervalleMs"
                    :seuil-video-kbps="appel.seuilVideoKbps" :presence="appel.presence"
                    :presence-intervalle-ms="appel.presenceIntervalleMs" :ice-transport-policy="appel.iceTransportPolicy"
                    :statut="appel.statut" :secondes-ensemble="appel.secondesEnsemble"
                    :intervalle-offre-ms="appel.intervalleOffreMs" :attente-offre-ms="appel.attenteOffreMs" :correspondant="rendezVous.medecin">
            <!-- TLC-03 : messagerie avec le médecin ; RG-06 : signaler son absence. -->
            <FinConsultation class="mt-4" @close="salleClose" :rendez-vous-id="rendezVous.id" prefixe="/consultation" />
            <Messagerie class="mt-4" :rendez-vous-id="rendezVous.id" prefixe="/consultation" />
        </AppelVideo>

        <Link :href="`/mes-rendez-vous/${rendezVous.id}`" class="bouton-secondaire mt-6">Retour au rendez-vous</Link>
    </Gabarit>
</template>
