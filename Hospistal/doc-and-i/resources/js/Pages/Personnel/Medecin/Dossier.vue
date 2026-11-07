<script setup>
// Dossier d'un patient suivi (DOS-01, DOS-03, DOS-04), côté médecin traitant.
// Le serveur a vérifié la relation de soin et noté cette lecture au journal.
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EtatDossier from '../../../Composants/Dossier/EtatDossier.vue';
import HistoriqueDossier from '../../../Composants/Dossier/HistoriqueDossier.vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({
    patient: Object,
    groupeSanguin: { type: String, default: null },
    groupeSanguinParMedecin: Boolean,
    elements: Array,
    constantes: Array,
    historique: Array,
    rectifications: Array,
    notes: Array,
    categories: Array,
    mesures: Array,
    groupes: Array,
    consulteLe: String,
});

const base = computed(() => `/personnel/medecin/patients/${props.patient.id}/dossier`);

// Une seule réponse de rectification ouverte à la fois ; rien n'est présélectionné.
const enReponse = ref(null);
const reponse = useForm({ decision: '', motif: '', note_id: '' });
const ouvrir = (id) => { enReponse.value = id; reponse.reset(); reponse.clearErrors(); };
const repondre = (r) => reponse
    .transform((d) => ({ ...d, note_id: d.decision === 'acceptee' && d.note_id ? d.note_id : null }))
    .post(`${base.value}/rectifications/${r.id}/reponse`, {
        preserveScroll: true,
        onSuccess: () => { enReponse.value = null; },
    });

const couleur = (statut) => ({
    acceptee: 'bg-vert-50 text-vert-900',
    refusee: 'bg-red-50 text-red-900',
}[statut] ?? 'bg-amber-50 text-amber-900');
</script>

<template>
    <Head title="Dossier du patient" />
    <GabaritPersonnel navigation>
        <Link href="/personnel/medecin/agenda" class="text-sm text-marque-700 underline">← Agenda</Link>
        <h1 class="mt-2 text-2xl font-bold">Dossier de {{ patient.nom }}</h1>
        <p class="mt-1 text-slate-700">
            <span v-if="patient.age !== null">{{ patient.age }} ans</span>
            <span v-if="patient.sexe"> · {{ patient.sexe }}</span>
        </p>
        <p class="mt-1 text-xs text-slate-500">Consulté le {{ consulteLe }}. Le patient voit qui a consulté son dossier.</p>

        <EtatDossier class="mt-6" :base="base"
                     :groupe-sanguin="groupeSanguin" :groupe-sanguin-par-medecin="groupeSanguinParMedecin"
                     :elements="elements" :constantes="constantes"
                     :categories="categories" :mesures="mesures" :groupes="groupes" />

        <section class="mt-4 rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-lg font-semibold">Demandes de rectification</h2>
            <p class="mt-1 text-sm text-slate-600">
                Si vous acceptez : corrigez une note par avenant, ou un élément par retrait puis ajout.
            </p>
            <p v-if="$page.props.errors.rectification" class="erreur mt-2" role="alert">{{ $page.props.errors.rectification }}</p>
            <ul v-if="rectifications.length" class="mt-2 divide-y divide-slate-100">
                <li v-for="r in rectifications" :key="r.id" class="py-3">
                    <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                        <span>Demandée le {{ r.quand }}</span>
                        <span class="rounded-full px-2 py-0.5 font-medium" :class="couleur(r.statut)">{{ r.statutLibelle }}</span>
                    </div>
                    <p class="mt-1 whitespace-pre-wrap">{{ r.demande }}</p>
                    <p v-if="r.reponse" class="mt-1 text-sm text-slate-700">Réponse du {{ r.traiteeLe }} : {{ r.reponse }}</p>

                    <button v-if="r.statut === 'ouverte' && enReponse !== r.id" type="button"
                            class="mt-2 text-sm text-marque-700 underline" @click="ouvrir(r.id)">
                        Répondre
                    </button>
                    <form v-if="enReponse === r.id" class="mt-3 space-y-3" @submit.prevent="repondre(r)">
                        <fieldset>
                            <legend class="etiquette">Décision</legend>
                            <label class="mt-1 flex items-center gap-2"><input v-model="reponse.decision" type="radio" value="acceptee"> Accepter</label>
                            <label class="mt-1 flex items-center gap-2"><input v-model="reponse.decision" type="radio" value="refusee"> Refuser</label>
                        </fieldset>
                        <p v-if="reponse.errors.decision" class="erreur">{{ reponse.errors.decision }}</p>
                        <div v-if="reponse.decision === 'acceptee' && notes.length">
                            <label :for="`note-${r.id}`" class="etiquette">Note corrigée par avenant (facultatif)</label>
                            <select :id="`note-${r.id}`" v-model="reponse.note_id" class="champ">
                                <option value="">Aucune</option>
                                <option v-for="n in notes" :key="n.id" :value="n.id">{{ n.libelle }}</option>
                            </select>
                            <p v-if="reponse.errors.note_id" class="erreur">{{ reponse.errors.note_id }}</p>
                        </div>
                        <div>
                            <label :for="`motif-${r.id}`" class="etiquette">Motif, expliqué au patient</label>
                            <textarea :id="`motif-${r.id}`" v-model="reponse.motif" class="champ" rows="2" maxlength="1000" required />
                            <p v-if="reponse.errors.motif" class="erreur">{{ reponse.errors.motif }}</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="bouton" :disabled="reponse.processing || !reponse.decision">Envoyer la réponse</button>
                            <button type="button" class="bouton-secondaire" @click="enReponse = null">Fermer</button>
                        </div>
                    </form>
                </li>
            </ul>
            <p v-else class="mt-1 text-slate-600">Aucune demande.</p>
        </section>

        <HistoriqueDossier class="mt-4" :evenements="historique" />
    </GabaritPersonnel>
</template>
