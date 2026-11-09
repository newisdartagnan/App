<script setup>
// DOS-02, PRE-01 : note et ordonnances d'un rendez-vous, hors de la salle
// (consultation terminée : la note non signée se signe après coup).
import { Head, Link } from '@inertiajs/vue3';
import NoteConsultation from '../../../Composants/Consultation/NoteConsultation.vue';
import Ordonnance from '../../../Composants/Consultation/Ordonnance.vue';
import BonExamen from '../../../Composants/Consultation/BonExamen.vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ rendezVous: Object });
</script>

<template>
    <Head title="Note de consultation" />
    <GabaritPersonnel navigation>
        <h1 class="text-xl font-bold">Note : {{ rendezVous.patient }}</h1>
        <p class="text-sm text-slate-600"><span class="first-letter:uppercase">{{ rendezVous.quand }}</span>, heure de Kinshasa · {{ rendezVous.statut }}</p>

        <NoteConsultation class="mt-4" :rendez-vous-id="rendezVous.id" />
        <Ordonnance class="mt-4" :rendez-vous-id="rendezVous.id" />
        <BonExamen class="mt-4" :rendez-vous-id="rendezVous.id" />

        <Link href="/personnel/medecin/agenda" class="bouton-secondaire mt-6">Retour à l'agenda</Link>
    </GabaritPersonnel>
</template>
