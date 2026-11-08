<script setup>
// DOS-02, RG-11 : note de consultation du médecin. Brouillon enregistré
// automatiquement pendant la saisie ; signée, elle est figée et se complète
// par des avenants. Le serveur décide de ce qui est modifiable.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { messageErreur, nouvelleCle, requete } from './requete';

const props = defineProps({
    rendezVousId: { type: String, required: true },
    prefixe: { type: String, default: '/personnel/medecin/consultation' },
    delaiEnregistrementMs: { type: Number, default: 2000 },
});

const base = `${props.prefixe}/${props.rendezVousId}/note`;
const CHAMPS = [
    { cle: 'motif', libelle: 'Motif', lignes: 2 },
    { cle: 'examen', libelle: 'Examen', lignes: 3 },
    { cle: 'hypotheses', libelle: 'Hypothèses', lignes: 2 },
    { cle: 'conduite', libelle: 'Conduite à tenir', lignes: 3 },
];

const note = ref(null);
const saisie = reactive({ motif: '', examen: '', hypotheses: '', diagnostic_code: '', diagnostic_libelle: '', conduite: '' });
const erreur = ref(null);
const etatEnregistrement = ref('');
const envoi = ref(false);
const avenant = reactive({ contenu: '', cle: nouvelleCle(), envoi: false, erreur: null });
let minuterie = null;
let modifie = false;
let enCours = null;

const modifiable = computed(() => note.value?.modifiable === true);

function appliquer(donnees) {
    note.value = donnees;
    for (const cle of Object.keys(saisie)) {
        saisie[cle] = donnees.champs[cle] ?? '';
    }
}

async function charger() {
    const { ok, donnees } = await requete(base);
    if (ok) {
        appliquer(donnees);
    } else {
        erreur.value = messageErreur(donnees);
    }
}

async function enregistrer() {
    window.clearTimeout(minuterie);
    if (!modifie || !modifiable.value) {
        return;
    }
    if (enCours) {
        await enCours;
    }
    modifie = false;
    etatEnregistrement.value = 'Enregistrement…';
    enCours = requete(base, { methode: 'PUT', corps: { ...saisie } });
    const { ok, donnees } = await enCours;
    enCours = null;
    if (ok) {
        note.value = donnees;
        erreur.value = null;
        etatEnregistrement.value = `Brouillon enregistré à ${donnees.enregistreeLe}.`;
    } else {
        // On réessaiera à la prochaine frappe ; rien n'est perdu à l'écran.
        modifie = true;
        etatEnregistrement.value = '';
        erreur.value = messageErreur(donnees);
    }
}

function saisir() {
    modifie = true;
    etatEnregistrement.value = 'Modifications non enregistrées.';
    window.clearTimeout(minuterie);
    minuterie = window.setTimeout(enregistrer, props.delaiEnregistrementMs);
}

async function signer() {
    if (!window.confirm('Signer la note ? Elle ne pourra plus être modifiée ; une correction se fera par un avenant.')) {
        return;
    }
    window.clearTimeout(minuterie);
    if (enCours) {
        await enCours;
    }
    envoi.value = true;
    erreur.value = null;
    const { ok, donnees } = await requete(`${base}/signature`, { methode: 'POST', corps: { champs: { ...saisie } } });
    envoi.value = false;
    if (ok) {
        modifie = false;
        appliquer(donnees);
        etatEnregistrement.value = '';
    } else {
        erreur.value = messageErreur(donnees);
    }
}

async function ajouterAvenant() {
    avenant.envoi = true;
    avenant.erreur = null;
    const { ok, donnees } = await requete(`${base}/avenants`, { methode: 'POST', corps: { contenu: avenant.contenu, cle: avenant.cle } });
    avenant.envoi = false;
    if (ok) {
        note.value = donnees;
        avenant.contenu = '';
        avenant.cle = nouvelleCle();
    } else {
        avenant.erreur = messageErreur(donnees);
    }
}

// Quitter la page : dernier enregistrement du brouillon.
const avantDeQuitter = () => { if (modifie) { enregistrer(); } };

onMounted(() => {
    charger();
    window.addEventListener('pagehide', avantDeQuitter);
});
onBeforeUnmount(() => {
    window.removeEventListener('pagehide', avantDeQuitter);
    avantDeQuitter();
});
</script>

<template>
    <section class="space-y-3 rounded-lg border border-slate-200 bg-white p-3 text-sm" aria-label="Note de consultation">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-base font-semibold">Note de consultation</h3>
            <span v-if="note?.signee" class="rounded-full bg-vert-50 px-2 py-0.5 text-xs font-medium text-vert-900">Signée</span>
            <span v-else-if="note" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">Brouillon</span>
        </div>

        <p v-if="!note && !erreur" class="text-slate-500">Chargement de la note…</p>

        <template v-if="note">
            <form v-if="modifiable" class="space-y-3" @submit.prevent="signer">
                <div v-for="champ in CHAMPS.slice(0, 3)" :key="champ.cle">
                    <label :for="`note-${champ.cle}`" class="etiquette">{{ champ.libelle }}</label>
                    <!-- Indication seulement : le motif déclaré par le patient n'est jamais recopié dans la note. -->
                    <p v-if="champ.cle === 'motif' && note.motifPatient" id="note-motif-patient" class="mb-1 text-xs text-slate-600">
                        Déclaré par le patient : « {{ note.motifPatient }} »
                    </p>
                    <textarea :id="`note-${champ.cle}`" v-model="saisie[champ.cle]" class="champ" :rows="champ.lignes" maxlength="5000"
                              :aria-describedby="champ.cle === 'motif' && note.motifPatient ? 'note-motif-patient' : undefined"
                              @input="saisir" @blur="enregistrer" />
                </div>

                <fieldset class="space-y-2">
                    <legend class="etiquette">Diagnostic (CIM-11)</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label for="note-diagnostic-code" class="text-xs text-slate-600">Code</label>
                            <input id="note-diagnostic-code" v-model="saisie.diagnostic_code" class="champ uppercase" maxlength="20"
                                   autocomplete="off" spellcheck="false" placeholder="BA00" @input="saisir" @blur="enregistrer">
                        </div>
                        <div class="col-span-2">
                            <label for="note-diagnostic-libelle" class="text-xs text-slate-600">Libellé</label>
                            <input id="note-diagnostic-libelle" v-model="saisie.diagnostic_libelle" class="champ" maxlength="255"
                                   @input="saisir" @blur="enregistrer">
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Saisie libre en attendant le référentiel CIM-11 : la forme du code est vérifiée.</p>
                </fieldset>

                <div>
                    <label for="note-conduite" class="etiquette">Conduite à tenir</label>
                    <textarea id="note-conduite" v-model="saisie.conduite" class="champ" rows="3" maxlength="5000" @input="saisir" @blur="enregistrer" />
                </div>

                <p class="text-xs text-slate-600" role="status" aria-live="polite">{{ etatEnregistrement }}</p>
                <button type="submit" class="bouton" :disabled="envoi">Signer la note</button>
            </form>

            <dl v-else class="space-y-2">
                <template v-for="champ in CHAMPS" :key="champ.cle">
                    <dt class="font-semibold">{{ champ.libelle }}</dt>
                    <dd class="whitespace-pre-wrap text-slate-800">{{ note.champs[champ.cle] || '—' }}</dd>
                </template>
                <dt class="font-semibold">Diagnostic (CIM-11)</dt>
                <dd class="text-slate-800">
                    <template v-if="note.champs.diagnostic_code">{{ note.champs.diagnostic_code }} · {{ note.champs.diagnostic_libelle }}</template>
                    <template v-else>—</template>
                </dd>
            </dl>

            <p v-if="note.signee" class="text-xs text-slate-600">Signée <span class="first-letter:lowercase">{{ note.signeeLe }}</span>.</p>
            <p v-else-if="!modifiable" class="text-slate-600">
                La note se rédige pendant la consultation, ou après quand elle est terminée.
            </p>

            <div v-if="note.signee" class="space-y-2 border-t border-slate-200 pt-3">
                <h4 class="font-semibold">Avenants</h4>
                <ul v-if="note.avenants.length" class="space-y-2">
                    <li v-for="a in note.avenants" :key="a.id" class="rounded border border-slate-200 p-2">
                        <p class="whitespace-pre-wrap">{{ a.contenu }}</p>
                        <p class="mt-1 text-xs text-slate-600"><span class="first-letter:uppercase">{{ a.signeLe }}</span><span v-if="a.auteur"> · {{ a.auteur }}</span></p>
                    </li>
                </ul>
                <p v-else class="text-slate-500">Aucun.</p>

                <form class="space-y-2" @submit.prevent="ajouterAvenant">
                    <label for="note-avenant" class="etiquette">Ajouter un avenant (daté et signé)</label>
                    <textarea id="note-avenant" v-model="avenant.contenu" class="champ" rows="2" maxlength="5000" required />
                    <p v-if="avenant.erreur" class="erreur" role="alert">{{ avenant.erreur }}</p>
                    <button type="submit" class="bouton-secondaire w-auto" :disabled="avenant.envoi || !avenant.contenu.trim()">Signer l'avenant</button>
                </form>
            </div>
        </template>

        <p v-if="erreur" class="erreur" role="alert">{{ erreur }}</p>
    </section>
</template>
