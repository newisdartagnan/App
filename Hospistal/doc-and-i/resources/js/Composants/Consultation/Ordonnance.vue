<script setup>
// PRE-01 à PRE-03 : ordonnances du rendez-vous, côté médecin. « Signer et
// émettre » numérote et fige l'ordonnance ; une erreur se corrige par une
// nouvelle. PRE-02 : les alertes (allergie, interaction) viennent du serveur
// et bloquent tant que chacune n'est pas maintenue explicitement.
import { onMounted, reactive, ref } from 'vue';
import { messageErreur, nouvelleCle, requete } from './requete';

const props = defineProps({
    rendezVousId: { type: String, required: true },
    prefixe: { type: String, default: '/personnel/medecin/consultation' },
});

const base = `${props.prefixe}/${props.rendezVousId}/ordonnances`;
const VOIES = ['orale', 'sublinguale', 'cutanée', 'ophtalmique', 'auriculaire', 'nasale', 'inhalée', 'rectale', 'vaginale', 'intramusculaire', 'intraveineuse', 'sous-cutanée'];

const ordonnances = ref([]);
const redigeable = ref(false);
const charge = ref(false);
const erreur = ref(null);
const succes = ref(null);
const envoi = ref(false);
const ouvert = ref(false);
const alertes = ref([]);

const ligneVide = () => ({ medicament: '', dosage: '', voie: 'orale', posologie: '', duree: '', consignes: '' });
const formulaire = reactive({ cle: nouvelleCle(), consignes: '', lignes: [ligneVide()], maintiens: [] });

function appliquer(donnees) {
    ordonnances.value = donnees.ordonnances;
    redigeable.value = donnees.redigeable;
}

async function charger() {
    const { ok, donnees } = await requete(base);
    charge.value = true;
    if (ok) {
        appliquer(donnees);
    } else {
        erreur.value = messageErreur(donnees);
    }
}

// Toute modification des lignes rend caduques les alertes déjà maintenues.
function lignesModifiees() {
    alertes.value = [];
    formulaire.maintiens = [];
}

function ajouterLigne() {
    formulaire.lignes.push(ligneVide());
    lignesModifiees();
}

function retirerLigne(index) {
    formulaire.lignes.splice(index, 1);
    lignesModifiees();
}

async function emettre() {
    if (!window.confirm('Signer et émettre cette ordonnance ? Elle ne pourra plus être modifiée ; une erreur se corrigera par une nouvelle ordonnance.')) {
        return;
    }
    envoi.value = true;
    erreur.value = null;
    succes.value = null;
    const { ok, statut, donnees } = await requete(base, { methode: 'POST', corps: formulaire });
    envoi.value = false;
    if (ok) {
        appliquer(donnees);
        succes.value = donnees.message;
        Object.assign(formulaire, { cle: nouvelleCle(), consignes: '', lignes: [ligneVide()], maintiens: [] });
        alertes.value = [];
        ouvert.value = false;
    } else if (statut === 422 && donnees.alertes) {
        alertes.value = donnees.alertes;
        formulaire.maintiens = donnees.alertes.filter((a) => a.maintenue).map((a) => a.cle);
        erreur.value = donnees.message;
    } else {
        erreur.value = messageErreur(donnees);
    }
}

onMounted(charger);
</script>

<template>
    <section class="space-y-3 rounded-lg border border-slate-200 bg-white p-3 text-sm" aria-label="Ordonnances">
        <h3 class="text-base font-semibold">Ordonnances</h3>
        <p v-if="!charge" class="text-slate-500">Chargement…</p>
        <p v-if="succes" class="rounded-lg border border-vert-200 bg-vert-50 px-3 py-2 text-vert-900" role="status">{{ succes }}</p>

        <ul v-if="ordonnances.length" class="space-y-2">
            <li v-for="o in ordonnances" :key="o.id" class="rounded border border-slate-200 p-2">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-semibold">{{ o.numero }}</span>
                    <a :href="`/personnel/medecin/ordonnances/${o.id}/pdf`" class="text-marque-700 underline">PDF</a>
                </div>
                <p class="text-xs text-slate-600 first-letter:uppercase">{{ o.signeeLe }}</p>
                <ul class="mt-1 list-inside list-disc text-slate-800">
                    <li v-for="l in o.lignes" :key="l.rang">{{ l.medicament }} {{ l.dosage }} — {{ l.posologie }}, {{ l.duree }}</li>
                </ul>
            </li>
        </ul>
        <p v-else-if="charge && !erreur" class="text-slate-500">Aucune ordonnance pour ce rendez-vous.</p>

        <button v-if="redigeable && !ouvert" type="button" class="bouton-secondaire w-auto" @click="ouvert = true">Rédiger une ordonnance</button>

        <form v-if="redigeable && ouvert" class="space-y-3" @submit.prevent="emettre">
            <fieldset v-for="(ligne, i) in formulaire.lignes" :key="i" class="space-y-2 rounded border border-slate-200 p-2">
                <legend class="px-1 font-semibold">Médicament {{ i + 1 }}</legend>
                <div>
                    <label :for="`l${i}-medicament`" class="etiquette">Médicament</label>
                    <input :id="`l${i}-medicament`" v-model="ligne.medicament" class="champ" maxlength="200" required @input="lignesModifiees">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label :for="`l${i}-dosage`" class="etiquette">Dosage</label>
                        <input :id="`l${i}-dosage`" v-model="ligne.dosage" class="champ" maxlength="100" required>
                    </div>
                    <div>
                        <label :for="`l${i}-voie`" class="etiquette">Voie</label>
                        <input :id="`l${i}-voie`" v-model="ligne.voie" class="champ" maxlength="50" list="voies-administration" required>
                    </div>
                </div>
                <div>
                    <label :for="`l${i}-posologie`" class="etiquette">Posologie</label>
                    <input :id="`l${i}-posologie`" v-model="ligne.posologie" class="champ" maxlength="255" required>
                </div>
                <div>
                    <label :for="`l${i}-duree`" class="etiquette">Durée</label>
                    <input :id="`l${i}-duree`" v-model="ligne.duree" class="champ" maxlength="100" required>
                </div>
                <div>
                    <label :for="`l${i}-consignes`" class="etiquette">Consignes (facultatif)</label>
                    <input :id="`l${i}-consignes`" v-model="ligne.consignes" class="champ" maxlength="255">
                </div>
                <button v-if="formulaire.lignes.length > 1" type="button" class="text-sm text-red-700 underline" @click="retirerLigne(i)">Retirer ce médicament</button>
            </fieldset>
            <datalist id="voies-administration">
                <option v-for="v in VOIES" :key="v" :value="v" />
            </datalist>

            <button v-if="formulaire.lignes.length < 20" type="button" class="bouton-secondaire w-auto" @click="ajouterLigne">Ajouter un médicament</button>

            <div>
                <label for="ordonnance-consignes" class="etiquette">Consignes générales (facultatif)</label>
                <textarea id="ordonnance-consignes" v-model="formulaire.consignes" class="champ" rows="2" maxlength="2000" />
            </div>

            <div v-if="alertes.length" class="space-y-2 rounded-lg border border-red-300 bg-red-50 p-3" role="alert">
                <p class="font-semibold text-red-800">Alertes à la prescription</p>
                <label v-for="a in alertes" :key="a.cle" class="flex items-start gap-2 text-red-900">
                    <input v-model="formulaire.maintiens" type="checkbox" :value="a.cle" class="mt-1">
                    <span>
                        {{ a.message }}
                        <strong class="block">Je maintiens cette prescription malgré {{ a.type === 'allergie' ? 'l\'allergie' : 'l\'interaction' }}.</strong>
                    </span>
                </label>
            </div>

            <p v-if="erreur" class="erreur" role="alert">{{ erreur }}</p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="bouton w-auto" :disabled="envoi">Signer et émettre</button>
                <button type="button" class="bouton-secondaire w-auto" :disabled="envoi" @click="ouvert = false">Fermer</button>
            </div>
        </form>
        <p v-else-if="erreur" class="erreur" role="alert">{{ erreur }}</p>
    </section>
</template>
