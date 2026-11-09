<script setup>
// PRE-04, PRE-05, PRE-07, RG-01 : mes bons d'examen. Payer, obtenir le code
// de retrait (montré une seule fois), puis voir les résultats VALIDÉS.
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';

const props = defineProps({ bons: Array, laboratoires: Array, moyens: Array, telephone: String, codeRetrait: Object });
const page = usePage();
const erreurs = computed(() => page.props.errors ?? {});

const choix = reactive({});
const choixForm = useForm({ laboratoire_id: '' });
const choisir = (b) => {
    choixForm.laboratoire_id = choix[b.id] ?? '';
    choixForm.post(`/mes-examens/${b.id}/laboratoire`, { preserveScroll: true });
};

// Un formulaire de paiement par bon, créé à la première lecture (objet simple :
// chaque formulaire est déjà réactif ; la clé d'idempotence vient du serveur).
const paiements = {};
const formulaire = (b) => (paiements[b.id] ??= useForm({ moyen: props.moyens[0]?.valeur ?? '', telephone: props.telephone, cle: b.cle }));
const estMobile = (valeur) => props.moyens.find((m) => m.valeur === valeur)?.mobile_money;
const payer = (b) => formulaire(b).post(`/mes-examens/${b.id}/paiement`, { preserveScroll: true });

const codeForm = useForm({ nouveau: false });
const demanderCode = (b, nouveau = false) => {
    if (nouveau && !window.confirm('Demander un nouveau code ? L\'ancien ne marchera plus.')) {
        return;
    }
    codeForm.nouveau = nouveau;
    codeForm.post(`/mes-examens/${b.id}/code`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Mes examens" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Mes examens</h1>
        <p class="mt-2 text-sm text-slate-600">Payez le bon, présentez votre code de retrait au laboratoire. Vos résultats apparaissent ici une fois vérifiés par votre médecin.</p>

        <p v-if="erreurs.examens || erreurs.paiement" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ erreurs.examens || erreurs.paiement }}</p>
        <p v-if="bons.length === 0" class="mt-4 text-slate-600">Aucun bon d'examen pour l'instant.</p>

        <article v-for="b in bons" :key="b.id" class="mt-4 rounded-lg bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-semibold">Bon {{ b.numero }}</h2>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="b.statut === 'valide' ? 'bg-vert-50 text-vert-900' : 'bg-slate-100 text-slate-700'">{{ b.statutLibelle }}</span>
            </div>
            <p class="text-sm text-slate-600"><span class="first-letter:uppercase">{{ b.prescritLe }}</span> · {{ b.medecin }}</p>
            <ul class="mt-2 list-inside list-disc text-sm">
                <li v-for="(e, i) in b.examens" :key="i">{{ e }}</li>
            </ul>
            <p class="mt-1 text-sm">Montant : <strong>{{ b.montant }}</strong></p>
            <p v-if="b.dateSouhaitee" class="text-sm text-slate-600">À faire de préférence : <span class="first-letter:lowercase">{{ b.dateSouhaitee }}</span></p>
            <p v-if="b.laboratoire" class="text-sm text-slate-700">
                Laboratoire : {{ b.laboratoire.nom }}<template v-if="b.laboratoire.adresse">, {{ b.laboratoire.adresse }}</template><template v-if="b.laboratoire.commune"> ({{ b.laboratoire.commune }})</template>
            </p>

            <form v-if="b.aChoisirLaboratoire" class="mt-3 space-y-2" @submit.prevent="choisir(b)">
                <label :for="`labo-${b.id}`" class="etiquette">Choisissez votre laboratoire</label>
                <select :id="`labo-${b.id}`" v-model="choix[b.id]" class="champ" required>
                    <option v-for="l in laboratoires" :key="l.id" :value="l.id">{{ l.nom }}<template v-if="l.commune"> ({{ l.commune }})</template></option>
                </select>
                <button type="submit" class="bouton" :disabled="choixForm.processing">Choisir ce laboratoire</button>
            </form>

            <form v-if="b.payable" class="mt-3 space-y-2" @submit.prevent="payer(b)">
                <label :for="`moyen-${b.id}`" class="etiquette">Payer avec</label>
                <select :id="`moyen-${b.id}`" v-model="formulaire(b).moyen" class="champ">
                    <option v-for="m in moyens" :key="m.valeur" :value="m.valeur">{{ m.libelle }}</option>
                </select>
                <template v-if="estMobile(formulaire(b).moyen)">
                    <label :for="`tel-${b.id}`" class="etiquette">Numéro Mobile Money</label>
                    <input :id="`tel-${b.id}`" v-model="formulaire(b).telephone" class="champ" inputmode="tel" autocomplete="tel">
                </template>
                <button type="submit" class="bouton" :disabled="formulaire(b).processing">Payer {{ b.montant }}</button>
            </form>
            <Link v-if="b.paiementEnCours" :href="`/paiements/${b.paiementEnCours}`" class="bouton-secondaire mt-3">Suivre le paiement en cours</Link>

            <div v-if="b.codeDisponible" class="mt-3 rounded-lg border border-marque-200 bg-marque-50 p-3">
                <template v-if="codeRetrait && codeRetrait.bon === b.id">
                    <p class="text-sm">Votre code de retrait, à présenter au laboratoire. Notez-le : il ne sera plus affiché.</p>
                    <p class="mt-1 text-center text-3xl font-bold tracking-widest text-marque-800">{{ codeRetrait.code }}</p>
                </template>
                <template v-else-if="!b.codeDejaRemis">
                    <p class="text-sm">Bon payé. Obtenez votre code de retrait pour le laboratoire.</p>
                    <button type="button" class="bouton mt-2" :disabled="codeForm.processing" @click="demanderCode(b)">Obtenir mon code</button>
                </template>
                <template v-else>
                    <p class="text-sm">Votre code de retrait vous a déjà été donné.</p>
                    <button type="button" class="mt-2 text-sm text-marque-700 underline" :disabled="codeForm.processing" @click="demanderCode(b, true)">Code perdu : en obtenir un nouveau</button>
                </template>
            </div>

            <section v-if="b.resultats.length" class="mt-3 space-y-2">
                <h3 class="font-semibold">Résultats</h3>
                <div v-for="r in b.resultats" :key="r.id" class="rounded border border-slate-200 p-2 text-sm">
                    <table v-if="r.valeurs.length" class="w-full">
                        <tr v-for="(v, i) in r.valeurs" :key="i" :class="{ 'font-semibold text-red-800': v.anormal }">
                            <td class="py-0.5 pr-2">{{ v.analyte }}</td>
                            <td class="py-0.5 pr-2">{{ v.valeur }} {{ v.unite }}</td>
                            <td class="py-0.5 text-slate-600">{{ v.normes }}</td>
                        </tr>
                    </table>
                    <a v-if="r.pdf" :href="`/mes-examens/resultats/${r.id}/pdf`" class="mt-1 inline-block text-marque-700 underline">Compte rendu du laboratoire (PDF)</a>
                    <p v-if="r.commentaire" class="mt-1 text-slate-700">Votre médecin : {{ r.commentaire }}</p>
                    <p class="mt-1 text-xs text-slate-500">Vérifié <span class="first-letter:lowercase">{{ r.valideLe }}</span></p>
                </div>
            </section>
        </article>

        <Link href="/espace" class="bouton-secondaire mt-8">Retour à mon espace</Link>
    </Gabarit>
</template>
