<script setup>
// RG-06, RG-07 : fin de consultation et absences. Les boutons proposés
// viennent du serveur (rôle et état de la salle, délai de 10 minutes).
// prefixe : « /consultation » (patient) ou « /personnel/medecin/consultation » (médecin).
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { messageErreur, requete } from './requete';

const props = defineProps({
    rendezVousId: { type: String, required: true },
    prefixe: { type: String, required: true },
    intervalleMs: { type: Number, default: 15000 },
});
const emit = defineEmits(['close']);

const base = `${props.prefixe}/${props.rendezVousId}`;
const etat = ref(null);
const erreur = ref(null);
const succes = ref(null);
const envoi = ref(false);
let minuterie = null;

const ACTIONS = {
    terminer: { libelle: 'Terminer la consultation', chemin: 'terminer', confirmation: 'Terminer la consultation ? Elle ne pourra plus reprendre.', classe: 'bouton' },
    patient_absent: { libelle: 'Constater l\'absence du patient', chemin: 'patient-absent', confirmation: 'Le patient n\'est pas venu : constater son absence ? Il ne sera pas remboursé.', classe: 'bouton-secondaire' },
    medecin_absent: { libelle: 'Le médecin n\'est pas venu', chemin: 'medecin-absent', confirmation: 'Signaler que le médecin n\'est pas venu ? Vous serez remboursé intégralement.', classe: 'bouton-secondaire' },
};

const FINAUX = ['terminee', 'patient_absent', 'medecin_absent'];

function mettreAJour(nouvel) {
    etat.value = nouvel;
    if (FINAUX.includes(nouvel.statut)) {
        window.clearInterval(minuterie);
        emit('close', nouvel);
    }
}

async function charger() {
    const { ok, donnees } = await requete(`${base}/fin`);
    if (ok) {
        mettreAJour(donnees);
    } else {
        erreur.value = messageErreur(donnees);
    }
}

async function agir(cle) {
    const action = ACTIONS[cle];
    if (!window.confirm(action.confirmation)) {
        return;
    }
    envoi.value = true;
    erreur.value = null;
    const { ok, donnees } = await requete(`${base}/${action.chemin}`, { methode: 'POST' });
    envoi.value = false;
    if (ok) {
        succes.value = donnees.message;
        mettreAJour(donnees);
    } else {
        erreur.value = messageErreur(donnees);
        await charger();
    }
}

onMounted(() => {
    charger();
    minuterie = window.setInterval(charger, props.intervalleMs);
});
onBeforeUnmount(() => window.clearInterval(minuterie));
</script>

<template>
    <section v-if="etat" class="space-y-2" aria-label="Fin de la consultation">
        <p class="text-sm text-slate-700">Consultation : <strong>{{ etat.libelle }}</strong></p>
        <p v-if="succes" class="rounded-lg border border-vert-200 bg-vert-50 px-3 py-2 text-sm text-vert-900" role="status">{{ succes }}</p>
        <div v-if="etat.actions.length" class="flex flex-wrap gap-2">
            <button v-for="cle in etat.actions" :key="cle" type="button" :class="[ACTIONS[cle].classe, 'w-auto']" :disabled="envoi" @click="agir(cle)">
                {{ ACTIONS[cle].libelle }}
            </button>
        </div>
        <p v-else-if="etat.absenceDisponibleA" class="text-sm text-slate-600">
            Si l'autre participant n'arrive pas, son absence pourra être constatée à partir de {{ etat.absenceDisponibleA }}.
        </p>
        <p v-if="erreur" class="erreur" role="alert">{{ erreur }}</p>
    </section>
</template>
