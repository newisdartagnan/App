<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';

const props = defineProps({ profil: Object, telephone: String, premiereFois: Boolean });

const form = useForm({
    nom: props.profil.nom ?? '',
    postnom: props.profil.postnom ?? '',
    prenom: props.profil.prenom ?? '',
    sexe: props.profil.sexe ?? '',
    date_naissance: props.profil.date_naissance ?? '',
    commune: props.profil.commune ?? '',
    langue: props.profil.langue ?? 'fr',
    contact_urgence_nom: props.profil.contact_urgence_nom ?? '',
    contact_urgence_telephone: props.profil.contact_urgence_telephone ?? '',
});
const enregistrer = () => form.put('/profil');
</script>

<template>
    <Head title="Mon profil" />
    <Gabarit>
        <h1 class="text-2xl font-bold">{{ premiereFois ? 'Faisons connaissance' : 'Mon profil' }}</h1>
        <p class="mt-2 text-slate-700">Le médecin a besoin de ces informations pour vous soigner. Compte : {{ telephone }}.</p>

        <form class="mt-6 space-y-4" @submit.prevent="enregistrer">
            <div>
                <label for="nom" class="etiquette">Nom *</label>
                <input id="nom" v-model="form.nom" class="champ" autocomplete="family-name" required>
                <p v-if="form.errors.nom" class="erreur">{{ form.errors.nom }}</p>
            </div>
            <div>
                <label for="postnom" class="etiquette">Postnom</label>
                <input id="postnom" v-model="form.postnom" class="champ">
            </div>
            <div>
                <label for="prenom" class="etiquette">Prénom *</label>
                <input id="prenom" v-model="form.prenom" class="champ" autocomplete="given-name" required>
                <p v-if="form.errors.prenom" class="erreur">{{ form.errors.prenom }}</p>
            </div>
            <fieldset>
                <legend class="etiquette">Sexe *</legend>
                <div class="mt-2 flex gap-3">
                    <label class="flex flex-1 items-center gap-2 rounded-lg border border-slate-300 bg-white p-3">
                        <input v-model="form.sexe" type="radio" value="F" name="sexe"> Femme
                    </label>
                    <label class="flex flex-1 items-center gap-2 rounded-lg border border-slate-300 bg-white p-3">
                        <input v-model="form.sexe" type="radio" value="M" name="sexe"> Homme
                    </label>
                </div>
                <p v-if="form.errors.sexe" class="erreur">{{ form.errors.sexe }}</p>
            </fieldset>
            <div>
                <label for="date_naissance" class="etiquette">Date de naissance *</label>
                <input id="date_naissance" v-model="form.date_naissance" type="date" class="champ" required>
                <p v-if="form.errors.date_naissance" class="erreur">{{ form.errors.date_naissance }}</p>
            </div>
            <div>
                <label for="commune" class="etiquette">Commune</label>
                <input id="commune" v-model="form.commune" class="champ" placeholder="Gombe, Lemba, Ngaliema…">
            </div>
            <div>
                <label for="langue" class="etiquette">Langue préférée *</label>
                <select id="langue" v-model="form.langue" class="champ">
                    <option value="fr">Français</option>
                    <option value="ln">Lingala</option>
                </select>
                <p v-if="form.errors.langue" class="erreur">{{ form.errors.langue }}</p>
            </div>
            <div>
                <label for="cu_nom" class="etiquette">Personne à prévenir en cas d'urgence</label>
                <input id="cu_nom" v-model="form.contact_urgence_nom" class="champ">
            </div>
            <div>
                <label for="cu_tel" class="etiquette">Son numéro</label>
                <input id="cu_tel" v-model="form.contact_urgence_telephone" type="tel" inputmode="tel" class="champ" placeholder="081 234 56 78">
                <p v-if="form.errors.contact_urgence_telephone" class="erreur">{{ form.errors.contact_urgence_telephone }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Enregistrer</button>
        </form>
    </Gabarit>
</template>
