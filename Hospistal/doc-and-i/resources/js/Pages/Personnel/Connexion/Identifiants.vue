<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import Pictogramme from '../../../Composants/Ui/Pictogramme.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

const form = useForm({ email: '', password: '' });
const envoyer = () => form.post('/personnel/connexion', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Connexion du personnel" />
    <GabaritPersonnel>
        <div class="carte mx-auto max-w-md p-6 sm:mt-6 sm:p-8">
        <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-marque-700 text-white shadow-sm">
            <Pictogramme nom="bouclier" :taille="28" />
        </span>
        <EnTeteDePage titre="Connexion du personnel" sous-titre="Courriel et mot de passe, puis le code de votre application d'authentification." />

        <form class="mt-6 space-y-4" @submit.prevent="envoyer">
            <div>
                <label for="email" class="etiquette">Courriel professionnel</label>
                <input id="email" v-model="form.email" type="email" autocomplete="username" class="champ" required>
                <p v-if="form.errors.email" class="erreur">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="password" class="etiquette">Mot de passe</label>
                <input id="password" v-model="form.password" type="password" autocomplete="current-password" class="champ" required>
                <p v-if="form.errors.password" class="erreur">{{ form.errors.password }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Continuer</button>
        </form>

        <p class="mt-6 text-sm text-slate-600">
            Patient ? <a href="/connexion" class="underline">Connexion par numéro de téléphone</a>.
        </p>
        </div>
    </GabaritPersonnel>
</template>
