<script setup>
// « Mon dossier » (DOS-01, DOS-03, DOS-04) : le patient connecté seulement.
import { Head, Link, useForm } from '@inertiajs/vue3';
import EtatDossier from '../../Composants/Dossier/EtatDossier.vue';
import HistoriqueDossier from '../../Composants/Dossier/HistoriqueDossier.vue';
import Gabarit from '../../Composants/Gabarit.vue';

defineProps({
    groupeSanguin: { type: String, default: null },
    groupeSanguinParMedecin: Boolean,
    elements: Array,
    constantes: Array,
    historique: Array,
    rectifications: Array,
    notes: { type: Array, default: () => [] },
    modifiable: { type: Boolean, default: true },
    acces: Array,
    categories: Array,
    mesures: Array,
    groupes: Array,
});

const rectification = useForm({ demande: '', note_id: '' });
const demander = () => rectification.transform((d) => ({ ...d, note_id: d.note_id || null })).post('/mon-dossier/rectifications', {
    preserveScroll: true,
    onSuccess: () => rectification.reset(),
});

const couleur = (statut) => ({
    acceptee: 'bg-vert-50 text-vert-900',
    refusee: 'bg-red-50 text-red-900',
}[statut] ?? 'bg-amber-50 text-amber-900');
</script>

<template>
    <Head title="Mon dossier" />
    <Gabarit>
        <Link href="/espace" class="text-sm text-marque-700 underline">← Mon espace</Link>
        <h1 class="mt-2 text-2xl font-bold">Mon dossier médical</h1>
        <p class="mt-1 text-sm text-slate-600">
            Ce que vous indiquez ici aide le médecin pendant la consultation. Laissez vide ce que vous ne savez pas.
        </p>
        <a href="/mon-dossier/pdf" class="bouton-secondaire mt-4">Télécharger mon dossier (PDF)</a>

        <EtatDossier class="mt-6" base="/mon-dossier" est-patient
                     :groupe-sanguin="groupeSanguin" :groupe-sanguin-par-medecin="groupeSanguinParMedecin"
                     :elements="elements" :constantes="constantes"
                     :categories="categories" :mesures="mesures" :groupes="groupes" />

        <section class="mt-4 carte p-4">
            <h2 class="text-lg font-semibold">Demander une correction</h2>
            <p class="mt-1 text-sm text-slate-600">
                Une information saisie par un médecin est fausse ? Expliquez-le ici. Un médecin qui vous suit vous répondra.
            </p>
            <form class="mt-3 space-y-3" @submit.prevent="demander">
                <label for="demande" class="etiquette">Ce qu'il faut corriger</label>
                <textarea id="demande" v-model="rectification.demande" class="champ" rows="3" maxlength="2000" required />
                <div v-if="notes.length">
                    <label for="note-visee" class="etiquette">Note concernée (facultatif)</label>
                    <select id="note-visee" v-model="rectification.note_id" class="champ">
                        <option value="">Aucune en particulier</option>
                        <option v-for="n in notes" :key="n.id" :value="n.id">{{ n.libelle }}</option>
                    </select>
                </div>
                <p v-if="rectification.errors.demande" class="erreur" role="alert">{{ rectification.errors.demande }}</p>
                <button type="submit" class="bouton" :disabled="rectification.processing">Envoyer la demande</button>
            </form>

            <ul v-if="rectifications.length" class="mt-4 divide-y divide-slate-100">
                <li v-for="r in rectifications" :key="r.id" class="py-3">
                    <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                        <span>Demandée le {{ r.quand }}</span>
                        <span class="rounded-full px-2 py-0.5 font-medium" :class="couleur(r.statut)">{{ r.statutLibelle }}</span>
                    </div>
                    <p class="mt-1 whitespace-pre-wrap">{{ r.demande }}</p>
                    <p v-if="r.reponse" class="mt-1 text-sm text-slate-700">
                        Réponse du {{ r.traiteeLe }} : {{ r.reponse }}
                        <a v-if="r.noteId" :href="`#note-${r.noteId}`" class="text-marque-700 underline">Voir la note corrigée</a>
                    </p>
                </li>
            </ul>
        </section>

        <HistoriqueDossier class="mt-4" :evenements="historique" />

        <section class="mt-4 carte p-4">
            <h2 class="text-lg font-semibold">Qui a consulté mon dossier</h2>
            <ul v-if="acces.length" class="mt-1 divide-y divide-slate-100 text-sm">
                <li v-for="(a, i) in acces" :key="i" class="py-2">
                    <span class="font-medium">{{ a.qui }}</span> · {{ a.quoi }}
                    <span class="block text-xs text-slate-500 first-letter:uppercase">{{ a.quand }}</span>
                </li>
            </ul>
            <p v-else class="mt-1 text-slate-600">Personne pour l'instant.</p>
        </section>
    </Gabarit>
</template>
