<script setup>
// PRE-05, PRE-06 : un bon, côté laboratoire. Réception du patient (code de
// retrait), puis dépôt des résultats : valeurs et/ou compte rendu PDF.
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import GabaritPersonnel from '../../Composants/GabaritPersonnel.vue';

const props = defineProps({ laboratoire: String, bon: Object, cle: String, pdfMoMax: Number });
const page = usePage();
const erreurs = computed(() => page.props.errors ?? {});

const reception = useForm({ code: '' });
const recevoir = () => reception.post(`/personnel/laboratoire/bons/${props.bon.id}/reception`, { preserveScroll: true, onSuccess: () => reception.reset() });

const ligneVide = () => ({ analyte: '', valeur: '', unite: '', normes: '', anormal: false });
const depot = useForm({ valeurs: [ligneVide()], pdf: null, critique: false, cle: props.cle, ligne_id: '' });
const deposer = () => {
    if (depot.critique && !window.confirm('Signaler un résultat CRITIQUE ? Le médecin est alerté immédiatement.')) {
        return;
    }
    depot.post(`/personnel/laboratoire/bons/${props.bon.id}/resultats`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => { depot.reset(); depot.valeurs = [ligneVide()]; },
    });
};
const peutDeposer = computed(() => ['en_cours', 'resultats_deposes'].includes(props.bon.statut));
</script>

<template>
    <Head :title="`Bon ${bon.numero}`" />
    <GabaritPersonnel>
        <Link href="/personnel/laboratoire" class="text-sm text-marque-700 underline">← Tous les bons</Link>
        <h1 class="mt-2 text-2xl font-bold">Bon {{ bon.numero }}</h1>
        <p class="text-sm text-slate-600">{{ bon.statutLibelle }} · payé {{ bon.payeLe }}</p>

        <dl class="mt-4 space-y-1 rounded-lg border border-slate-200 bg-white p-4 text-sm">
            <div><dt class="inline text-slate-600">Patient : </dt><dd class="inline">{{ bon.patient }}<template v-if="bon.age !== null">, {{ bon.age }} ans</template><template v-if="bon.sexe">, {{ bon.sexe }}</template></dd></div>
            <div><dt class="inline text-slate-600">Prescripteur : </dt><dd class="inline">{{ bon.prescripteur }}</dd></div>
            <div v-if="bon.dateSouhaitee"><dt class="inline text-slate-600">Date souhaitée : </dt><dd class="inline">{{ bon.dateSouhaitee }}</dd></div>
            <div><dt class="text-slate-600">Examens :</dt>
                <dd><ul class="list-inside list-disc"><li v-for="e in bon.examens" :key="e.id">{{ e.libelle }}</li></ul></dd></div>
            <div v-if="bon.renseignements"><dt class="text-slate-600">Renseignements cliniques :</dt><dd class="whitespace-pre-wrap">{{ bon.renseignements }}</dd></div>
        </dl>

        <form v-if="bon.statut === 'paye'" class="mt-4 space-y-2 rounded-lg bg-marque-50 p-4" @submit.prevent="recevoir">
            <label for="code" class="etiquette">Code de retrait présenté par le patient</label>
            <input id="code" v-model="reception.code" class="champ text-center text-xl tracking-widest" inputmode="numeric" autocomplete="off" maxlength="20" required>
            <p v-if="erreurs.code" class="erreur" role="alert">{{ erreurs.code }}</p>
            <button type="submit" class="bouton" :disabled="reception.processing">Patient reçu</button>
        </form>

        <form v-if="peutDeposer" class="mt-4 space-y-3 rounded-lg border border-slate-200 bg-white p-4" @submit.prevent="deposer">
            <h2 class="font-semibold">Déposer des résultats</h2>
            <p v-if="bon.depots" class="text-sm text-slate-600">{{ bon.depots }} dépôt(s) déjà fait(s) pour ce bon.</p>
            <div>
                <label for="ligne" class="etiquette">Examen concerné (facultatif)</label>
                <select id="ligne" v-model="depot.ligne_id" class="champ">
                    <option value="">Tout le bon</option>
                    <option v-for="e in bon.examens" :key="e.id" :value="e.id">{{ e.libelle }}</option>
                </select>
            </div>
            <fieldset v-for="(v, i) in depot.valeurs" :key="i" class="grid grid-cols-2 gap-2 rounded border border-slate-200 p-2">
                <legend class="px-1 text-sm font-medium">Valeur {{ i + 1 }}</legend>
                <input v-model="v.analyte" class="champ col-span-2" maxlength="100" placeholder="Analyte (ex. Glycémie)" :aria-label="`Analyte ${i + 1}`">
                <input v-model="v.valeur" class="champ" maxlength="100" placeholder="Valeur" :aria-label="`Valeur ${i + 1}`">
                <input v-model="v.unite" class="champ" maxlength="30" placeholder="Unité" :aria-label="`Unité ${i + 1}`">
                <input v-model="v.normes" class="champ" maxlength="100" placeholder="Normes" :aria-label="`Normes ${i + 1}`">
                <label class="flex items-center gap-2 text-sm"><input v-model="v.anormal" type="checkbox"> Anormal</label>
            </fieldset>
            <button type="button" class="bouton-secondaire w-auto" @click="depot.valeurs.push(ligneVide())">Ajouter une valeur</button>
            <div>
                <label for="pdf" class="etiquette">Compte rendu PDF (facultatif, {{ pdfMoMax }} Mo au plus)</label>
                <input id="pdf" type="file" accept="application/pdf" class="champ" @input="depot.pdf = $event.target.files[0]">
            </div>
            <label class="flex items-center gap-2 font-medium text-red-800"><input v-model="depot.critique" type="checkbox"> Résultat critique : alerter le médecin sans attendre</label>
            <p v-for="(m, c) in erreurs" :key="c" class="erreur" role="alert">{{ m }}</p>
            <button type="submit" class="bouton" :disabled="depot.processing">Déposer</button>
        </form>
    </GabaritPersonnel>
</template>
