<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppelVideo from '../../Composants/AppelVideo.vue';
import FinConsultation from '../../Composants/Consultation/FinConsultation.vue';
import Messagerie from '../../Composants/Consultation/Messagerie.vue';
import Gabarit from '../../Composants/Gabarit.vue';
import Carte from '../../Composants/Ui/Carte.vue';
import EnTeteDePage from '../../Composants/Ui/EnTeteDePage.vue';

defineProps({ rendezVous: Object, appel: Object });

const appelVideo = ref(null);
const salleClose = () => appelVideo.value?.fermer();
</script>

<template>
    <Head title="Consultation" />
    <Gabarit large>
        <EnTeteDePage :titre="`Consultation avec ${rendezVous.medecin}`" pictogramme="video">
            <template #sousTitre><span class="first-letter:uppercase">{{ rendezVous.quand }} · {{ rendezVous.type }}</span></template>
        </EnTeteDePage>

        <AppelVideo ref="appelVideo" :role="appel.role" :signaux="appel.signaux" :ice-servers="appel.iceServers"
                    :dernier-signal="appel.dernierSignal" :intervalle-ms="appel.intervalleMs"
                    :seuil-video-kbps="appel.seuilVideoKbps" :presence="appel.presence"
                    :presence-intervalle-ms="appel.presenceIntervalleMs" :ice-transport-policy="appel.iceTransportPolicy"
                    :statut="appel.statut" :secondes-ensemble="appel.secondesEnsemble"
                    :intervalle-offre-ms="appel.intervalleOffreMs" :attente-offre-ms="appel.attenteOffreMs" :correspondant="rendezVous.medecin">
            <!-- TLC-03 : messagerie avec le médecin ; RG-06 : signaler son absence. -->
            <FinConsultation @close="salleClose" :rendez-vous-id="rendezVous.id" prefixe="/consultation" />
            <Carte titre="Messagerie" pictogramme="message">
                <Messagerie :rendez-vous-id="rendezVous.id" prefixe="/consultation" />
            </Carte>
        </AppelVideo>

        <Link :href="`/mes-rendez-vous/${rendezVous.id}`" class="bouton-secondaire mt-6">Retour au rendez-vous</Link>
    </Gabarit>
</template>
