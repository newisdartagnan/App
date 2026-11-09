<script setup>
// PRE-07, RG-10 : résultats d'un bon, côté médecin prescripteur, ou
// coordinateur pour un bon escaladé (validation à la place du médecin, tracée).
import { Head, Link, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import GabaritPersonnel from '../../Composants/GabaritPersonnel.vue';

const props = defineProps({ bon: Object, resultats: Array, peutValider: Boolean, enRemplacement: Boolean, espace: String });
const base = props.espace === 'coordination' ? '/personnel/coordination' : '/personnel/medecin';
const retour = props.espace === 'coordination' ? '/personnel/coordination' : '/personnel/medecin/resultats';

const commentaires = reactive({});
const validation = useForm({ commentaire: '' });
const valider = (r) => {
    const message = props.enRemplacement
        ? 'Valider ce résultat À LA PLACE du médecin prescripteur ? C\'est tracé à votre nom.'
        : 'Valider ce résultat ? Le patient sera prévenu et le verra dans son espace.';
    if (!window.confirm(message)) {
        return;
    }
    validation.commentaire = commentaires[r.id] ?? '';
    validation.post(`${base}/resultats/${r.id}/validation`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Résultats ${bon.numero}`" />
    <GabaritPersonnel :navigation="espace !== 'coordination'">
        <Link :href="retour" class="text-sm text-marque-700 underline">← Retour</Link>
        <h1 class="mt-2 text-xl font-bold">Résultats : {{ bon.patient }}<template v-if="bon.age !== null">, {{ bon.age }} ans</template></h1>
        <p class="text-sm text-slate-600">Bon {{ bon.numero }} · {{ bon.laboratoire }} · {{ bon.statutLibelle }}</p>
        <p class="text-sm text-slate-600">Prescrit par {{ bon.prescripteur }} : {{ bon.examens.join(', ') }}</p>
        <p v-if="enRemplacement" class="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
            Validation en retard (plus de 48 h) : vous pouvez valider à la place du médecin prescripteur (RG-10). C'est tracé.
        </p>

        <article v-for="r in resultats" :key="r.id" class="mt-4 rounded-lg border bg-white p-4 text-sm" :class="r.critique ? 'border-red-300' : 'border-slate-200'">
            <div class="flex items-center justify-between gap-2">
                <span class="text-slate-600">Déposé {{ r.deposeLe }}</span>
                <span v-if="r.critique" class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-800">Critique</span>
            </div>
            <table v-if="r.valeurs.length" class="mt-2 w-full">
                <tr v-for="(v, i) in r.valeurs" :key="i" :class="{ 'font-semibold text-red-800': v.anormal }">
                    <td class="py-0.5 pr-2">{{ v.analyte }}</td>
                    <td class="py-0.5 pr-2">{{ v.valeur }} {{ v.unite }}</td>
                    <td class="py-0.5 text-slate-600">{{ v.normes }}</td>
                </tr>
            </table>
            <a v-if="r.pdf" :href="`${base}/resultats/${r.id}/pdf`" class="mt-2 inline-block text-marque-700 underline">Compte rendu du laboratoire (PDF)</a>

            <p v-if="r.valide" class="mt-2 text-vert-800">
                Validé {{ r.valideLe }}<template v-if="r.enRemplacement"> par le coordinateur</template>.
                <span v-if="r.commentaire" class="block text-slate-700">Commentaire : {{ r.commentaire }}</span>
            </p>
            <form v-else-if="peutValider" class="mt-3 space-y-2" @submit.prevent="valider(r)">
                <label :for="`com-${r.id}`" class="etiquette">Commentaire pour le patient (facultatif)</label>
                <textarea :id="`com-${r.id}`" v-model="commentaires[r.id]" class="champ" rows="2" maxlength="2000" />
                <p v-if="validation.errors.commentaire" class="erreur">{{ validation.errors.commentaire }}</p>
                <button type="submit" class="bouton w-auto" :disabled="validation.processing">Valider</button>
            </form>
        </article>
    </GabaritPersonnel>
</template>
