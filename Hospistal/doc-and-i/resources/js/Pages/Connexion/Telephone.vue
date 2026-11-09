<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';
import Pictogramme from '../../Composants/Ui/Pictogramme.vue';

const form = useForm({ telephone: '' });
const envoyer = () => form.post('/connexion');
</script>

<template>
    <Head title="Connexion" />
    <Gabarit>
        <div class="carte mx-auto max-w-md p-6 sm:mt-6 sm:p-8">
        <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-marque-700 text-white shadow-sm">
            <Pictogramme nom="telephone" :taille="28" />
        </span>
        <h1 class="text-2xl font-bold">Votre numéro de téléphone</h1>
        <p class="mt-2 text-slate-700">Nous vous envoyons un code par SMS. Pas de mot de passe à retenir.</p>

        <form class="mt-6 space-y-4" @submit.prevent="envoyer">
            <div>
                <label for="telephone" class="etiquette">Numéro mobile</label>
                <input id="telephone" v-model="form.telephone" type="tel" inputmode="tel" autocomplete="tel"
                       placeholder="081 234 56 78" class="champ" required>
                <p v-if="form.errors.telephone" class="erreur">{{ form.errors.telephone }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Recevoir un code</button>
        </form>
        </div>
    </Gabarit>
</template>
