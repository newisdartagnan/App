<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ email: String, longueurMin: Number });

const form = useForm({ password: '', password_confirmation: '' });
const envoyer = () => form.post('/personnel/activation/mot-de-passe', {
    onError: () => form.reset('password', 'password_confirmation'),
});
</script>

<template>
    <Head title="Activation du compte" />
    <GabaritPersonnel>
        <p class="text-sm font-medium text-teal-800">Étape 1 sur 2</p>
        <h1 class="text-2xl font-bold">Choisissez votre mot de passe</h1>
        <p class="mt-2 text-slate-700">Compte : <strong>{{ email }}</strong></p>
        <p class="mt-2 text-slate-700">Au moins {{ longueurMin }} caractères. Une phrase de plusieurs mots est plus sûre et plus facile à retenir.</p>

        <form class="mt-6 space-y-4" @submit.prevent="envoyer">
            <input type="email" :value="email" autocomplete="username" class="hidden" readonly aria-hidden="true" tabindex="-1">
            <div>
                <label for="password" class="etiquette">Mot de passe</label>
                <input id="password" v-model="form.password" type="password" autocomplete="new-password"
                       :minlength="longueurMin" class="champ" required>
                <p v-if="form.errors.password" class="erreur">{{ form.errors.password }}</p>
            </div>
            <div>
                <label for="password_confirmation" class="etiquette">Le même mot de passe, une seconde fois</label>
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password"
                       :minlength="longueurMin" class="champ" required>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Continuer</button>
        </form>
    </GabaritPersonnel>
</template>
