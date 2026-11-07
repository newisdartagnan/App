<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppelVideo from '../../../Composants/AppelVideo.vue';
import DossierPatient from '../../../Composants/Consultation/DossierPatient.vue';
import FinConsultation from '../../../Composants/Consultation/FinConsultation.vue';
import Messagerie from '../../../Composants/Consultation/Messagerie.vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ rendezVous: Object, appel: Object });

const appelVideo = ref(null);
const salleClose = () => appelVideo.value?.fermer();
</script>

<template>
    <Head title="Consultation" />
    <GabaritPersonnel navigation>
        <h1 class="text-xl font-bold">Consultation : {{ rendezVous.patient }}</h1>
        <p class="text-sm text-slate-600">{{ rendezVous.heure }}, heure de Kinshasa.</p>

        <AppelVideo ref="appelVideo" class="mt-4" :role="appel.role" :signaux="appel.signaux" :ice-servers="appel.iceServers"
                    :dernier-signal="appel.dernierSignal" :intervalle-ms="appel.intervalleMs"
                    :seuil-video-kbps="appel.seuilVideoKbps" :presence="appel.presence"
                    :presence-intervalle-ms="appel.presenceIntervalleMs" :ice-transport-policy="appel.iceTransportPolicy"
                    :statut="appel.statut" :secondes-ensemble="appel.secondesEnsemble" :correspondant="rendezVous.patient">
            <!-- TLC-05 : dossier du patient ; TLC-03 : messagerie ; RG-06, RG-07 : fin et absences. -->
            <FinConsultation class="mt-4" @close="salleClose" :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
            <DossierPatient class="mt-4" :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
            <Messagerie class="mt-4" :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
        </AppelVideo>

        <Link href="/personnel/medecin/agenda" class="bouton-secondaire mt-6">Retour à l'agenda</Link>
    </GabaritPersonnel>
</template>
