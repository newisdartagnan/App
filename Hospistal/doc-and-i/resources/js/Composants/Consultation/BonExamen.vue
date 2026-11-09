<script setup>
// PRE-04 : bons d'examen du rendez-vous, côté médecin (salle et page « Note »).
// Examens actifs ayant un prix ; laboratoire choisi ou laissé au patient ;
// clé de formulaire contre le double envoi.
import { computed, onMounted, reactive, ref } from 'vue';
import { messageErreur, nouvelleCle, requete } from './requete';

const props = defineProps({
    rendezVousId: { type: String, required: true },
    prefixe: { type: String, default: '/personnel/medecin/consultation' },
});

const base = `${props.prefixe}/${props.rendezVousId}/examens`;
const bons = ref([]);
const catalogue = ref([]);
const laboratoires = ref([]);
const redigeable = ref(false);
const charge = ref(false);
const erreur = ref(null);
const succes = ref(null);
const envoi = ref(false);
const ouvert = ref(false);
const recherche = ref('');

const vide = () => ({ cle: nouvelleCle(), examens: [], renseignements: '', date_souhaitee: '', laboratoire_id: '' });
const formulaire = reactive(vide());

const filtres = computed(() => {
    const q = recherche.value.trim().toLowerCase();
    return q ? catalogue.value.filter((e) => `${e.libelle} ${e.code}`.toLowerCase().includes(q)) : catalogue.value;
});

function appliquer(donnees) {
    bons.value = donnees.bons;
    redigeable.value = donnees.redigeable;
    catalogue.value = donnees.catalogue;
    laboratoires.value = donnees.laboratoires;
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

async function prescrire() {
    if (!window.confirm('Prescrire ces examens ? Le bon ne se modifie plus ; le patient le paie avant d\'aller au laboratoire.')) {
        return;
    }
    envoi.value = true;
    erreur.value = null;
    succes.value = null;
    const { ok, donnees } = await requete(base, { methode: 'POST', corps: formulaire });
    envoi.value = false;
    if (ok) {
        appliquer(donnees);
        succes.value = donnees.message;
        Object.assign(formulaire, vide());
        ouvert.value = false;
    } else {
        erreur.value = messageErreur(donnees);
    }
}

onMounted(charger);
</script>

<template>
    <section class="space-y-3 rounded-lg border border-slate-200 bg-white p-3 text-sm" aria-label="Examens">
        <h3 class="text-base font-semibold">Examens</h3>
        <p v-if="!charge" class="text-slate-500">Chargement…</p>
        <p v-if="succes" class="rounded-lg border border-vert-200 bg-vert-50 px-3 py-2 text-vert-900" role="status">{{ succes }}</p>

        <ul v-if="bons.length" class="space-y-2">
            <li v-for="b in bons" :key="b.id" class="rounded border border-slate-200 p-2">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-semibold">{{ b.numero }}</span>
                    <span class="text-xs text-slate-600">{{ b.statutLibelle }}</span>
                </div>
                <p class="text-slate-800">{{ b.lignes.map((l) => l.libelle).join(', ') }}</p>
                <p class="text-xs text-slate-600">{{ b.montant }} · {{ b.laboratoire ?? 'Laboratoire au choix du patient' }}</p>
            </li>
        </ul>
        <p v-else-if="charge && !erreur" class="text-slate-500">Aucun examen prescrit pour ce rendez-vous.</p>

        <button v-if="redigeable && !ouvert" type="button" class="bouton-secondaire w-auto" @click="ouvert = true">Prescrire des examens</button>

        <form v-if="redigeable && ouvert" class="space-y-3" @submit.prevent="prescrire">
            <div>
                <label for="examens-recherche" class="etiquette">Examens</label>
                <input id="examens-recherche" v-model="recherche" class="champ" placeholder="Rechercher (glycémie, NFS…)" autocomplete="off">
                <p v-if="!catalogue.length" class="mt-1 text-amber-800">Aucun examen prescriptible : le catalogue (avec prix) est géré par l'administration.</p>
                <div class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded border border-slate-200 p-2">
                    <label v-for="e in filtres" :key="e.id" class="flex items-start gap-2">
                        <input v-model="formulaire.examens" type="checkbox" :value="e.id" class="mt-1">
                        <span>{{ e.libelle }} <span class="text-slate-500">({{ e.prix }})</span>
                            <span v-if="e.preparation" class="block text-xs text-slate-600">{{ e.preparation }}</span>
                        </span>
                    </label>
                </div>
            </div>
            <div>
                <label for="examens-renseignements" class="etiquette">Renseignements cliniques (pour le laboratoire)</label>
                <textarea id="examens-renseignements" v-model="formulaire.renseignements" class="champ" rows="2" maxlength="2000" />
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="examens-date" class="etiquette">Date souhaitée</label>
                    <input id="examens-date" v-model="formulaire.date_souhaitee" type="date" class="champ">
                </div>
                <div>
                    <label for="examens-labo" class="etiquette">Laboratoire</label>
                    <select id="examens-labo" v-model="formulaire.laboratoire_id" class="champ">
                        <option value="">Au choix du patient</option>
                        <option v-for="l in laboratoires" :key="l.id" :value="l.id">{{ l.nom }}<template v-if="l.commune"> ({{ l.commune }})</template></option>
                    </select>
                </div>
            </div>
            <p v-if="erreur" class="erreur" role="alert">{{ erreur }}</p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="bouton w-auto" :disabled="envoi || !formulaire.examens.length">Prescrire</button>
                <button type="button" class="bouton-secondaire w-auto" :disabled="envoi" @click="ouvert = false">Fermer</button>
            </div>
        </form>
        <p v-else-if="erreur" class="erreur" role="alert">{{ erreur }}</p>
    </section>
</template>
