<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';
import ListeCreneaux from '../../Composants/ListeCreneaux.vue';

const props = defineProps({
    demande: Object,
    type: Object,
    medecin: Object,
    jours: Array,
    delaiPaiementMinutes: Number,
    attenteEnCours: String,
});

const choisi = ref(null);
const form = useForm({ medecin_id: null, type_consultation_id: props.type.id, debut: null });
const premierDisponible = computed(() => props.medecin === null);

const choisir = (creneau) => {
    choisi.value = creneau;
    form.medecin_id = creneau.medecin_id;
    form.debut = creneau.debut;
};
const reserver = () => form.post(`/consultation/demandes/${props.demande.id}/reservation`, { preserveScroll: true });
</script>

<template>
    <Head title="Choisir un créneau" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Choisissez l'heure</h1>
        <p class="mt-2 text-slate-700">
            {{ type.nom }} ({{ type.duree_minutes }} min) · {{ type.prix }}<br>
            {{ medecin ? medecin.nom : 'Premier médecin disponible' }}
        </p>
        <p class="mt-1 text-sm text-slate-500">Heures de Kinshasa.</p>

        <p v-if="attenteEnCours" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
            Vous avez déjà un créneau en attente de paiement.
            <Link :href="`/mes-rendez-vous/${attenteEnCours}`" class="underline">Le voir</Link>
        </p>
        <p v-if="form.errors.creneau" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ form.errors.creneau }}</p>

        <ListeCreneaux :jours="jours" :choisi="choisi" :afficher-medecin="premierDisponible" @choisir="choisir" />

        <div v-if="choisi" class="sticky bottom-0 mt-6 border-t border-slate-200 bg-slate-50 py-3">
            <p class="text-sm text-slate-700">
                {{ choisi.heure }}<template v-if="choisi.medecin"> avec {{ choisi.medecin }}</template>.
                Le créneau vous est gardé {{ delaiPaiementMinutes }} minutes, le temps de payer.
            </p>
            <button type="button" class="bouton mt-2" :disabled="form.processing" @click="reserver">Réserver ce créneau</button>
        </div>

        <Link :href="`/consultation/demandes/${demande.id}/choix`" class="bouton-secondaire mt-6">Changer de médecin</Link>
    </Gabarit>
</template>
