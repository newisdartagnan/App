<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppelVideo from '../../../Composants/AppelVideo.vue';
import DossierPatient from '../../../Composants/Consultation/DossierPatient.vue';
import FinConsultation from '../../../Composants/Consultation/FinConsultation.vue';
import Messagerie from '../../../Composants/Consultation/Messagerie.vue';
import NoteConsultation from '../../../Composants/Consultation/NoteConsultation.vue';
import Ordonnance from '../../../Composants/Consultation/Ordonnance.vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import Carte from '../../../Composants/Ui/Carte.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

defineProps({ rendezVous: Object, appel: Object });

const appelVideo = ref(null);
const salleClose = () => appelVideo.value?.fermer();
</script>

<template>
    <Head title="Consultation" />
    <GabaritPersonnel navigation large>
        <EnTeteDePage :titre="`Consultation : ${rendezVous.patient}`" :sous-titre="`${rendezVous.heure}, heure de Kinshasa.`"
                      pictogramme="video" retour="/personnel/medecin/agenda" retour-libelle="Agenda" />

        <AppelVideo ref="appelVideo" :role="appel.role" :signaux="appel.signaux" :ice-servers="appel.iceServers"
                    :dernier-signal="appel.dernierSignal" :intervalle-ms="appel.intervalleMs"
                    :seuil-video-kbps="appel.seuilVideoKbps" :presence="appel.presence"
                    :presence-intervalle-ms="appel.presenceIntervalleMs" :ice-transport-policy="appel.iceTransportPolicy"
                    :statut="appel.statut" :secondes-ensemble="appel.secondesEnsemble"
                    :intervalle-offre-ms="appel.intervalleOffreMs" :attente-offre-ms="appel.attenteOffreMs" :correspondant="rendezVous.patient">
            <!-- TLC-05 : dossier du patient ; TLC-03 : messagerie ; RG-06, RG-07 : fin et absences ;
                 DOS-02 : note de consultation ; PRE-01 : ordonnances. -->
            <FinConsultation @close="salleClose" :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
            <Carte titre="Dossier du patient" pictogramme="dossier">
                <DossierPatient :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
            </Carte>
            <Carte>
                <NoteConsultation :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
            </Carte>
            <Carte>
                <Ordonnance :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
            </Carte>
            <Carte titre="Messagerie" pictogramme="message">
                <Messagerie :rendez-vous-id="rendezVous.id" prefixe="/personnel/medecin/consultation" />
            </Carte>
        </AppelVideo>

        <Link href="/personnel/medecin/agenda" class="bouton-secondaire mt-6">Retour à l'agenda</Link>
    </GabaritPersonnel>
</template>
