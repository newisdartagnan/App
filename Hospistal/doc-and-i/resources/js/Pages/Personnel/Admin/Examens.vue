<script setup>
// PRE-04 : catalogue des examens. Un examen sans prix n'est pas proposé aux médecins.
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ categories: Array, devises: Array, examens: Array });

const libelles = { biologie: 'Biologie', imagerie: 'Imagerie' };
const vide = () => ({ code: '', libelle: '', categorie: 'biologie', prix: '', devise: props.devises[0] ?? 'USD', preparation: '' });

const ajoutOuvert = ref(false);
const ajout = useForm(vide());
const creer = () => ajout.post('/personnel/admin/examens', { preserveScroll: true, onSuccess: () => { ajout.reset(); ajoutOuvert.value = false; } });

const edition = ref(null);
const modif = useForm(vide());
const ouvrirEdition = (e) => {
    modif.defaults({ code: e.code, libelle: e.libelle, categorie: e.categorie, prix: e.prix ?? '', devise: e.devise, preparation: e.preparation ?? '' });
    modif.reset();
    modif.clearErrors();
    edition.value = e.id;
};
const enregistrer = (e) => modif.put(`/personnel/admin/examens/${e.id}`, { preserveScroll: true, onSuccess: () => { edition.value = null; } });

const etat = useForm({ actif: true });
const changerEtat = (e) => {
    etat.actif = !e.actif;
    etat.post(`/personnel/admin/examens/${e.id}/etat`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Examens" />
    <GabaritPersonnel navigation espace="admin">
        <h1 class="text-2xl font-bold">Catalogue des examens</h1>
        <p class="mt-2 text-sm text-slate-700">Un examen ne se supprime pas : désactivé, il n'est plus proposé. Sans prix, il n'est pas prescriptible. Un bon déjà prescrit garde son prix.</p>

        <button v-if="!ajoutOuvert" type="button" class="bouton mt-4" @click="ajoutOuvert = true">Ajouter un examen</button>
        <form v-else class="mt-4 space-y-3 rounded-lg bg-marque-50 p-4" @submit.prevent="creer">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="code" class="etiquette">Code</label>
                    <input id="code" v-model="ajout.code" class="champ uppercase" maxlength="30" required placeholder="GLY">
                    <p v-if="ajout.errors.code" class="erreur">{{ ajout.errors.code }}</p>
                </div>
                <div>
                    <label for="categorie" class="etiquette">Catégorie</label>
                    <select id="categorie" v-model="ajout.categorie" class="champ">
                        <option v-for="c in categories" :key="c" :value="c">{{ libelles[c] }}</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="libelle" class="etiquette">Libellé</label>
                <input id="libelle" v-model="ajout.libelle" class="champ" maxlength="200" required>
                <p v-if="ajout.errors.libelle" class="erreur">{{ ajout.errors.libelle }}</p>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="prix" class="etiquette">Prix (facultatif)</label>
                    <input id="prix" v-model="ajout.prix" class="champ" inputmode="decimal" placeholder="12.50">
                    <p v-if="ajout.errors.prix" class="erreur">{{ ajout.errors.prix }}</p>
                </div>
                <div>
                    <label for="devise" class="etiquette">Monnaie</label>
                    <select id="devise" v-model="ajout.devise" class="champ">
                        <option v-for="d in devises" :key="d" :value="d">{{ d }}</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="preparation" class="etiquette">Préparation du patient (facultatif)</label>
                <input id="preparation" v-model="ajout.preparation" class="champ" maxlength="255" placeholder="À jeun depuis 8 heures">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bouton" :disabled="ajout.processing">Ajouter</button>
                <button type="button" class="bouton-secondaire" @click="ajoutOuvert = false">Annuler</button>
            </div>
        </form>

        <p v-if="examens.length === 0" class="mt-6 text-slate-600">Aucun examen au catalogue.</p>
        <ul class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            <li v-for="e in examens" :key="e.id" class="px-4 py-3" :class="{ 'opacity-60': !e.actif }">
                <template v-if="edition !== e.id">
                    <div class="flex items-center justify-between gap-2">
                        <span><strong>{{ e.libelle }}</strong> <span class="text-sm text-slate-600">({{ e.code }}, {{ libelles[e.categorie] }})</span></span>
                        <span class="text-sm" :class="e.prixAffiche ? 'text-slate-800' : 'text-amber-800'">{{ e.prixAffiche ?? 'Sans prix' }}</span>
                    </div>
                    <p v-if="e.preparation" class="text-sm text-slate-600">{{ e.preparation }}</p>
                    <div class="mt-2 flex gap-3 text-sm">
                        <button type="button" class="text-marque-700 underline" @click="ouvrirEdition(e)">Modifier</button>
                        <button type="button" class="underline" :class="e.actif ? 'text-red-700' : 'text-vert-700'" :disabled="etat.processing" @click="changerEtat(e)">
                            {{ e.actif ? 'Désactiver' : 'Réactiver' }}
                        </button>
                    </div>
                </template>
                <form v-else class="space-y-2" @submit.prevent="enregistrer(e)">
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="modif.code" class="champ uppercase" maxlength="30" aria-label="Code" required>
                        <select v-model="modif.categorie" class="champ" aria-label="Catégorie">
                            <option v-for="c in categories" :key="c" :value="c">{{ libelles[c] }}</option>
                        </select>
                    </div>
                    <input v-model="modif.libelle" class="champ" maxlength="200" aria-label="Libellé" required>
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="modif.prix" class="champ" inputmode="decimal" aria-label="Prix" placeholder="Sans prix">
                        <select v-model="modif.devise" class="champ" aria-label="Monnaie">
                            <option v-for="d in devises" :key="d" :value="d">{{ d }}</option>
                        </select>
                    </div>
                    <input v-model="modif.preparation" class="champ" maxlength="255" aria-label="Préparation">
                    <p v-for="(m, c) in modif.errors" :key="c" class="erreur">{{ m }}</p>
                    <div class="flex gap-2">
                        <button type="submit" class="bouton" :disabled="modif.processing">Enregistrer</button>
                        <button type="button" class="bouton-secondaire" @click="edition = null">Annuler</button>
                    </div>
                </form>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
