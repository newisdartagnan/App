<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';
import EnTeteDePage from '../../Composants/Ui/EnTeteDePage.vue';

const props = defineProps({
    questions: Array,
    durees: Array,
    piecesMax: Number,
    tailleMaxMo: Number,
    extensions: Array,
});

// Aucune réponse pré-remplie : chaque question du tri attend un « oui » ou un « non » explicite.
const tri = {};
props.questions.forEach((q) => { tri[q.cle] = null; });

const form = useForm({
    motif: '',
    symptomes: '',
    depuis: '',
    pieces: [],
    tri,
});

const etape = ref(1);
const erreurFichiers = ref('');
const accept = computed(() => props.extensions.map((e) => `.${e}`).join(','));
const sansReponse = computed(() => props.questions.filter((q) => form.tri[q.cle] === null).length);

const choisirFichiers = (evenement) => {
    erreurFichiers.value = '';
    const fichiers = Array.from(evenement.target.files ?? []);
    const tropGros = fichiers.find((f) => f.size > props.tailleMaxMo * 1024 * 1024);
    if (fichiers.length > props.piecesMax) {
        erreurFichiers.value = `Vous pouvez joindre ${props.piecesMax} fichiers au plus.`;
    } else if (tropGros) {
        erreurFichiers.value = `« ${tropGros.name} » est trop gros : ${props.tailleMaxMo} Mo au plus.`;
    }
    form.pieces = erreurFichiers.value ? [] : fichiers;
    if (erreurFichiers.value) evenement.target.value = '';
};

const continuer = () => {
    if (form.motif.trim() === '') {
        form.setError('motif', 'Dites en quelques mots pourquoi vous consultez.');
        return;
    }
    form.clearErrors('motif');
    etape.value = 2;
    window.scrollTo(0, 0);
};

const erreurDescription = () => ['motif', 'symptomes', 'depuis', 'pieces'].some(
    (champ) => Object.keys(form.errors).some((cle) => cle === champ || cle.startsWith(`${champ}.`)),
);

const envoyer = () => form.post('/consultation/demande', {
    onError: () => { if (erreurDescription()) etape.value = 1; },
});
</script>

<template>
    <Head title="Demander une consultation" />
    <Gabarit>
        <p class="text-sm text-slate-500">Étape {{ etape }} sur 2</p>

        <section v-if="etape === 1">
            <EnTeteDePage titre="Votre besoin" sous-titre="Le médecin lira ce que vous écrivez avant la consultation." pictogramme="message" />

            <form class="mt-6 space-y-4" @submit.prevent="continuer">
                <div>
                    <label for="motif" class="etiquette">Pourquoi voulez-vous consulter ? *</label>
                    <input id="motif" v-model="form.motif" class="champ" maxlength="255" required placeholder="Par exemple : toux et fièvre">
                    <p v-if="form.errors.motif" class="erreur">{{ form.errors.motif }}</p>
                </div>
                <div>
                    <label for="symptomes" class="etiquette">Ce que vous ressentez</label>
                    <textarea id="symptomes" v-model="form.symptomes" class="champ" rows="4" maxlength="2000" />
                    <p v-if="form.errors.symptomes" class="erreur">{{ form.errors.symptomes }}</p>
                </div>
                <div>
                    <label for="depuis" class="etiquette">Depuis quand ?</label>
                    <select id="depuis" v-model="form.depuis" class="champ">
                        <option value="">Je ne sais pas</option>
                        <option v-for="d in durees" :key="d.valeur" :value="d.valeur">{{ d.libelle }}</option>
                    </select>
                    <p v-if="form.errors.depuis" class="erreur">{{ form.errors.depuis }}</p>
                </div>
                <div>
                    <label for="pieces" class="etiquette">Photos ou résultats d'examens (facultatif)</label>
                    <p class="text-sm text-slate-500">Photos ou PDF, {{ piecesMax }} fichiers au plus, {{ tailleMaxMo }} Mo chacun.</p>
                    <input id="pieces" type="file" multiple :accept="accept" class="champ" @change="choisirFichiers">
                    <p v-if="erreurFichiers" class="erreur">{{ erreurFichiers }}</p>
                    <p v-if="form.errors.pieces" class="erreur">{{ form.errors.pieces }}</p>
                    <template v-for="(erreur, cle) in form.errors" :key="cle">
                        <p v-if="cle.startsWith('pieces.')" class="erreur">
                            {{ form.pieces[Number(cle.split('.')[1])]?.name ?? 'Fichier' }} : {{ erreur }}
                        </p>
                    </template>
                </div>
                <button type="submit" class="bouton">Continuer</button>
                <Link href="/espace" class="bouton-secondaire">Retour</Link>
            </form>
        </section>

        <section v-else>
            <h1 class="text-2xl font-bold">Quelques questions importantes</h1>
            <p class="mt-2 text-slate-700">Répondez à chaque question par oui ou par non. Elles servent à repérer une urgence.</p>

            <form class="mt-6 space-y-4" @submit.prevent="envoyer">
                <fieldset v-for="q in questions" :key="q.cle" class="carte p-4">
                    <legend class="sr-only">{{ q.question }}</legend>
                    <p class="font-medium" aria-hidden="true">{{ q.question }}</p>
                    <div class="mt-3 flex gap-3">
                        <label class="flex flex-1 items-center gap-2 rounded-lg border border-slate-300 p-3">
                            <input v-model="form.tri[q.cle]" type="radio" value="oui" :name="`tri-${q.cle}`"> Oui
                        </label>
                        <label class="flex flex-1 items-center gap-2 rounded-lg border border-slate-300 p-3">
                            <input v-model="form.tri[q.cle]" type="radio" value="non" :name="`tri-${q.cle}`"> Non
                        </label>
                    </div>
                    <p v-if="form.errors[`tri.${q.cle}`]" class="erreur">{{ form.errors[`tri.${q.cle}`] }}</p>
                </fieldset>
                <p v-if="form.errors.tri" class="erreur">{{ form.errors.tri }}</p>
                <p v-if="sansReponse > 0" class="text-sm text-slate-600">
                    Encore {{ sansReponse }} question{{ sansReponse > 1 ? 's' : '' }} sans réponse.
                </p>
                <button type="submit" class="bouton" :disabled="form.processing || sansReponse > 0">Envoyer ma demande</button>
                <button type="button" class="bouton-secondaire" :disabled="form.processing" @click="etape = 1">Retour</button>
            </form>
        </section>
    </Gabarit>
</template>
