<script setup>
// DOS-01 : ce qui vaut aujourd'hui dans le dossier, et sa saisie. Commun au
// patient (« Mon dossier ») et au médecin traitant. Aucune valeur par défaut :
// rien n'est présélectionné, « je ne sais pas » reste vide. Le serveur revérifie tout.
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    base: { type: String, required: true },
    // true : le patient lui-même (il ne retire pas ce qu'un médecin a saisi).
    estPatient: { type: Boolean, default: false },
    // Lire n'est pas modifier : un médecin qui n'a pas encore vu le patient lit seulement.
    modifiable: { type: Boolean, default: true },
    groupeSanguin: { type: String, default: null },
    groupeSanguinParMedecin: { type: Boolean, default: false },
    elements: { type: Array, required: true },
    constantes: { type: Array, required: true },
    categories: { type: Array, required: true },
    mesures: { type: Array, required: true },
    groupes: { type: Array, required: true },
});

const parCategorie = computed(() => Object.fromEntries(props.elements.map((c) => [c.categorie, c])));
const allergies = computed(() => parCategorie.value.allergie?.elements ?? []);
const autres = computed(() => props.elements.filter((c) => c.categorie !== 'allergie'));

const element = useForm({ categorie: '', libelle: '', precision: '' });
const ajouterElement = () => element.post(`${props.base}/antecedents`, {
    preserveScroll: true,
    onSuccess: () => element.reset(),
});

const retrait = useForm({});
const enRetrait = ref(null);
const retirable = (e) => props.modifiable && (!props.estPatient || e.source === 'patient');
const retirer = (e) => {
    if (!window.confirm(`Retirer « ${e.libelle} » du dossier ? Il restera visible dans l'historique.`)) {
        return;
    }
    enRetrait.value = e.id;
    retrait.post(`${props.base}/antecedents/${e.id}/retrait`, {
        preserveScroll: true,
        onFinish: () => { enRetrait.value = null; },
    });
};

const mesure = useForm({ type: '', valeur: '', valeur2: '', mesure_le: '' });
const mesureChoisie = computed(() => props.mesures.find((m) => m.valeur === mesure.type) ?? null);
const ajouterMesure = () => mesure
    .transform((d) => ({ ...d, valeur2: d.type === 'tension' ? d.valeur2 : '', mesure_le: d.mesure_le || null }))
    .post(`${props.base}/constantes`, { preserveScroll: true, onSuccess: () => mesure.reset() });

// Choix explicite : aucun bouton coché au départ, même si un groupe est connu.
const groupe = useForm({ groupe_sanguin: '' });
const enregistrerGroupe = () => groupe.post(`${props.base}/groupe-sanguin`, {
    preserveScroll: true,
    onSuccess: () => groupe.reset(),
});
const groupeModifiable = computed(() => props.modifiable && (!props.estPatient || !props.groupeSanguinParMedecin));
const erreurRetrait = computed(() => retrait.errors.retrait);
</script>

<template>
    <div class="space-y-4">
        <!-- Allergies d'abord, bien visibles. -->
        <section class="rounded-lg border-2 p-4" role="note" aria-labelledby="titre-allergies"
                 :class="allergies.length ? 'border-red-600 bg-red-50 text-red-900' : 'border-slate-200 bg-white text-slate-700'">
            <h2 id="titre-allergies" class="text-lg font-bold">Allergies</h2>
            <ul v-if="allergies.length" class="mt-1 space-y-1">
                <li v-for="e in allergies" :key="e.id" class="flex items-start justify-between gap-2">
                    <span class="font-semibold">{{ e.libelle }}<span v-if="e.precision" class="font-normal"> ({{ e.precision }})</span></span>
                    <button v-if="retirable(e)" type="button" class="shrink-0 text-sm underline" :disabled="retrait.processing" @click="retirer(e)">
                        {{ enRetrait === e.id ? '…' : 'Retirer' }}
                    </button>
                </li>
            </ul>
            <p v-else class="mt-1">Aucune allergie déclarée.</p>
        </section>

        <p v-if="erreurRetrait" class="erreur rounded-lg bg-red-50 p-3" role="alert">{{ erreurRetrait }}</p>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-lg font-semibold">Groupe sanguin</h2>
            <p class="mt-1">
                <strong v-if="groupeSanguin">{{ groupeSanguin }}</strong>
                <span v-else class="text-slate-600">Inconnu</span>
                <span v-if="groupeSanguin && groupeSanguinParMedecin" class="text-sm text-slate-600"> · saisi par un médecin</span>
            </p>
            <p v-if="estPatient && !groupeModifiable" class="mt-2 text-sm text-slate-600">
                S'il est faux, demandez une rectification plus bas.
            </p>
            <form v-else class="mt-3" @submit.prevent="enregistrerGroupe">
                <fieldset>
                    <legend class="etiquette">{{ groupeSanguin ? 'Changer le groupe sanguin' : 'Indiquer le groupe sanguin' }}</legend>
                    <div class="mt-1 grid grid-cols-4 gap-2">
                        <label v-for="g in groupes" :key="g" class="flex items-center gap-1 rounded border border-slate-200 px-2 py-2">
                            <input v-model="groupe.groupe_sanguin" type="radio" name="groupe_sanguin" :value="g"> {{ g }}
                        </label>
                    </div>
                    <label class="mt-2 flex items-center gap-2">
                        <input v-model="groupe.groupe_sanguin" type="radio" name="groupe_sanguin" value="inconnu"> Je ne sais pas
                    </label>
                </fieldset>
                <p v-if="groupe.errors.groupe_sanguin" class="erreur">{{ groupe.errors.groupe_sanguin }}</p>
                <button type="submit" class="bouton mt-3" :disabled="groupe.processing || !groupe.groupe_sanguin">Enregistrer</button>
            </form>
        </section>

        <section v-for="c in autres" :key="c.categorie" class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-lg font-semibold">{{ c.libelle }}</h2>
            <ul v-if="c.elements.length" class="mt-1 divide-y divide-slate-100">
                <li v-for="e in c.elements" :key="e.id" class="flex items-start justify-between gap-2 py-2">
                    <span>
                        {{ e.libelle }}<span v-if="e.precision" class="text-slate-600"> — {{ e.precision }}</span>
                        <span class="block text-xs text-slate-500">
                            Depuis le {{ e.ajouteLe }}<template v-if="e.source === 'medecin'"> · saisi par un médecin</template>
                        </span>
                    </span>
                    <button v-if="retirable(e)" type="button" class="shrink-0 text-sm text-red-700 underline" :disabled="retrait.processing" @click="retirer(e)">
                        {{ enRetrait === e.id ? '…' : 'Retirer' }}
                    </button>
                </li>
            </ul>
            <p v-else class="mt-1 text-slate-600">Rien de déclaré.</p>
        </section>

        <section v-if="modifiable" class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-lg font-semibold">Ajouter au dossier</h2>
            <form class="mt-3 space-y-3" @submit.prevent="ajouterElement">
                <div>
                    <label for="categorie" class="etiquette">Type</label>
                    <select id="categorie" v-model="element.categorie" class="champ" required>
                        <option value="" disabled>Choisir…</option>
                        <option v-for="c in categories" :key="c.valeur" :value="c.valeur">{{ c.libelle }}</option>
                    </select>
                    <p v-if="element.errors.categorie" class="erreur">{{ element.errors.categorie }}</p>
                </div>
                <div>
                    <label for="libelle" class="etiquette">Quoi</label>
                    <input id="libelle" v-model="element.libelle" class="champ" maxlength="200" required
                           placeholder="Par exemple : pénicilline, diabète, appendicectomie">
                    <p v-if="element.errors.libelle" class="erreur">{{ element.errors.libelle }}</p>
                </div>
                <div>
                    <label for="precision" class="etiquette">Précision (facultatif)</label>
                    <input id="precision" v-model="element.precision" class="champ" maxlength="500"
                           placeholder="Depuis quand, dose, réaction…">
                    <p v-if="element.errors.precision" class="erreur">{{ element.errors.precision }}</p>
                </div>
                <p v-if="element.errors.antecedent" class="erreur" role="alert">{{ element.errors.antecedent }}</p>
                <button type="submit" class="bouton" :disabled="element.processing">Ajouter</button>
            </form>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="text-lg font-semibold">Dernières mesures</h2>
            <ul v-if="constantes.length" class="mt-1 divide-y divide-slate-100">
                <li v-for="c in constantes" :key="c.type" class="flex justify-between gap-2 py-2">
                    <span>{{ c.libelle }}</span>
                    <span class="text-right"><strong>{{ c.valeur }}</strong><span class="block text-xs text-slate-500">{{ c.quand }}</span></span>
                </li>
            </ul>
            <p v-else class="mt-1 text-slate-600">Aucune mesure.</p>

            <template v-if="modifiable">
            <h3 class="mt-4 font-semibold">Noter une mesure</h3>
            <form class="mt-2 space-y-3" @submit.prevent="ajouterMesure">
                <div>
                    <label for="type-mesure" class="etiquette">Mesure</label>
                    <select id="type-mesure" v-model="mesure.type" class="champ" required>
                        <option value="" disabled>Choisir…</option>
                        <option v-for="m in mesures" :key="m.valeur" :value="m.valeur">{{ m.libelle }} ({{ m.unite }})</option>
                    </select>
                    <p v-if="mesure.errors.type" class="erreur">{{ mesure.errors.type }}</p>
                </div>
                <div v-if="mesure.type" class="grid grid-cols-2 gap-3">
                    <div :class="mesure.type === 'tension' ? '' : 'col-span-2'">
                        <label for="valeur" class="etiquette">{{ mesure.type === 'tension' ? 'Systolique (le grand chiffre)' : `Valeur en ${mesureChoisie?.unite}` }}</label>
                        <input id="valeur" v-model="mesure.valeur" class="champ" inputmode="decimal" maxlength="12" required>
                    </div>
                    <div v-if="mesure.type === 'tension'">
                        <label for="valeur2" class="etiquette">Diastolique (le petit chiffre)</label>
                        <input id="valeur2" v-model="mesure.valeur2" class="champ" inputmode="decimal" maxlength="12" required>
                    </div>
                </div>
                <p v-if="mesure.errors.valeur" class="erreur">{{ mesure.errors.valeur }}</p>
                <p v-if="mesure.errors.valeur2" class="erreur">{{ mesure.errors.valeur2 }}</p>
                <div v-if="mesure.type">
                    <label for="mesure-le" class="etiquette">Mesurée le (laisser vide si c'est maintenant)</label>
                    <input id="mesure-le" v-model="mesure.mesure_le" type="datetime-local" class="champ">
                    <p v-if="mesure.errors.mesure_le" class="erreur">{{ mesure.errors.mesure_le }}</p>
                </div>
                <p v-if="mesure.errors.constante" class="erreur" role="alert">{{ mesure.errors.constante }}</p>
                <button type="submit" class="bouton" :disabled="mesure.processing">Enregistrer la mesure</button>
            </form>
            </template>
        </section>
    </div>
</template>
