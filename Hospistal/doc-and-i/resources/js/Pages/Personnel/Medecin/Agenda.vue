<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import Badge from '../../../Composants/Ui/Badge.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';
import EtatVide from '../../../Composants/Ui/EtatVide.vue';
import Pictogramme from '../../../Composants/Ui/Pictogramme.vue';

const props = defineProps({ rendezVous: Array, recentes: { type: Array, default: () => [] } });

// DOS-02 : état de la note des consultations terminées.
const NOTES = {
    a_rediger: { libelle: 'Note à rédiger', classe: 'bg-amber-50 text-amber-900' },
    brouillon: { libelle: 'Note à signer', classe: 'bg-amber-50 text-amber-900' },
    signee: { libelle: 'Note signée', classe: 'bg-vert-50 text-vert-900' },
};

// Regroupés par jour, dans l'ordre déjà donné par le serveur.
const parJour = computed(() => {
    const groupes = [];
    for (const rdv of props.rendezVous) {
        const dernier = groupes[groupes.length - 1];
        if (dernier && dernier.date === rdv.date) {
            dernier.rendezVous.push(rdv);
        } else {
            groupes.push({ date: rdv.date, rendezVous: [rdv] });
        }
    }
    return groupes;
});

// Un seul formulaire d'annulation ouvert à la fois.
const enAnnulation = ref(null);
const annulation = useForm({ motif: '' });
const ouvrir = (id) => { enAnnulation.value = id; annulation.reset(); annulation.clearErrors(); };
const annuler = (rdv) => {
    if (!window.confirm(`Annuler le rendez-vous de ${rdv.heure} avec ${rdv.patient} ? Le patient sera remboursé et prévenu par SMS.`)) {
        return;
    }
    annulation.post(`/personnel/medecin/rendez-vous/${rdv.id}/annulation`, {
        preserveScroll: true,
        onSuccess: () => { enAnnulation.value = null; },
    });
};

// TLC-01 : « Rejoindre la consultation » quand le serveur dit la salle ouverte
// (rendez-vous confirmé, dans la fenêtre, salle pas close) ; il revérifie à l'entrée.
const rejoignable = (rdv) => rdv.rejoignable === true;

const ton = (code) => ({
    confirme: 'succes',
    en_attente_paiement: 'attention',
    termine: 'info',
}[code] ?? 'neutre');
</script>

<template>
    <Head title="Agenda" />
    <GabaritPersonnel navigation>
        <EnTeteDePage titre="Agenda" sous-titre="Vos rendez-vous à venir, heure de Kinshasa." pictogramme="calendrier" />

        <p v-if="$page.props.errors.consultation" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ $page.props.errors.consultation }}</p>
        <EtatVide v-if="rendezVous.length === 0" class="mt-6" titre="Aucun rendez-vous à venir." pictogramme="calendrier"
                  texte="Vos disponibilités décident des créneaux proposés aux patients." />

        <section v-for="jour in parJour" :key="jour.date" class="mt-6">
            <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold tracking-wide text-slate-600 uppercase">
                <Pictogramme nom="calendrier" :taille="16" /> <span class="first-letter:uppercase">{{ jour.date }}</span>
            </h2>
            <ul class="carte divide-y divide-slate-200">
                <li v-for="rdv in jour.rendezVous" :key="rdv.id" class="border-l-4 px-4 py-3"
                    :class="rdv.statutCode === 'confirme' ? 'border-l-vert-600' : 'border-l-slate-200'">
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2 text-lg font-bold tabular-nums">
                            <Pictogramme nom="horloge" :taille="18" class="text-slate-500" /> {{ rdv.heure }} – {{ rdv.heureFin }}
                        </span>
                        <Badge :ton="ton(rdv.statutCode)">{{ rdv.statut }}</Badge>
                    </div>
                    <p v-if="rdv.consultationLibelle" class="mt-1 text-xs font-medium"
                       :class="rdv.consultation === 'en_cours' ? 'text-vert-700' : 'text-slate-600'">
                        Consultation : {{ rdv.consultationLibelle }}
                    </p>
                    <p v-if="rdv.patient" class="mt-1 font-semibold text-slate-900">{{ rdv.patient }}</p>
                    <p v-else class="mt-1 text-sm text-slate-500">Patient et motif affichés seulement pour un rendez-vous confirmé ou une consultation terminée.</p>
                    <p v-if="rdv.motif" class="text-sm text-slate-600">{{ rdv.motif }}</p>

                    <Link v-if="rejoignable(rdv)" :href="`/personnel/medecin/consultation/${rdv.id}`" class="bouton-vert mt-3 sm:w-auto">
                        <Pictogramme nom="video" /> Rejoindre la consultation
                    </Link>

                    <!-- DOS-03 : dossier du patient, pour un rendez-vous qui ouvre la relation de soin (le serveur revérifie). -->
                    <Link v-if="['confirme', 'termine'].includes(rdv.statutCode)" :href="`/personnel/medecin/rendez-vous/${rdv.id}/dossier`"
                          class="mt-2 mr-4 inline-flex min-h-11 items-center gap-1 text-sm font-medium text-marque-700 hover:underline">
                        <Pictogramme nom="dossier" :taille="16" /> Dossier du patient
                    </Link>

                    <button v-if="rdv.annulable && enAnnulation !== rdv.id" type="button"
                            class="mt-2 text-sm text-red-700 underline" @click="ouvrir(rdv.id)">
                        Annuler ce rendez-vous
                    </button>
                    <form v-if="enAnnulation === rdv.id" class="mt-3 space-y-2" @submit.prevent="annuler(rdv)">
                        <label :for="`motif-${rdv.id}`" class="etiquette">Motif de l'annulation</label>
                        <input :id="`motif-${rdv.id}`" v-model="annulation.motif" class="champ" maxlength="255" required>
                        <p v-if="annulation.errors.motif" class="erreur">{{ annulation.errors.motif }}</p>
                        <div class="flex gap-2">
                            <button type="submit" class="bouton bg-red-700 hover:bg-red-800" :disabled="annulation.processing">Confirmer l'annulation</button>
                            <button type="button" class="bouton-secondaire" @click="enAnnulation = null">Garder</button>
                        </div>
                    </form>
                </li>
            </ul>
        </section>

        <section v-if="recentes.length" class="mt-8">
            <h2 class="text-lg font-semibold">Consultations terminées (30 derniers jours)</h2>
            <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                <li v-for="rdv in recentes" :key="rdv.id" class="flex items-center justify-between gap-2 px-4 py-3">
                    <span>
                        <span class="block font-medium">{{ rdv.patient }}</span>
                        <span class="block text-sm text-slate-600"><span class="first-letter:uppercase">{{ rdv.date }}</span>, {{ rdv.heure }}</span>
                        <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="NOTES[rdv.note].classe">{{ NOTES[rdv.note].libelle }}</span>
                    </span>
                    <Link :href="`/personnel/medecin/rendez-vous/${rdv.id}/note`" class="bouton-secondaire w-auto">Note</Link>
                </li>
            </ul>
        </section>
    </GabaritPersonnel>
</template>
