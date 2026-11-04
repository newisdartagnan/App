<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const form = useForm({ code: '' });
const recuperation = ref(false);
const verifier = () => form.post('/personnel/connexion/code', { onFinish: () => form.reset('code') });
</script>

<template>
    <Head title="Code de vérification" />
    <GabaritPersonnel>
        <h1 class="text-2xl font-bold">Code de vérification</h1>
        <p v-if="!recuperation" class="mt-2 text-slate-700">Ouvrez votre application d'authentification et saisissez le code à 6 chiffres affiché pour Doc&amp;I.</p>
        <p v-else class="mt-2 text-slate-700">Saisissez l'un de vos codes de récupération (format XXXXX-XXXXX). Chaque code ne sert qu'une fois.</p>

        <form class="mt-6 space-y-4" @submit.prevent="verifier">
            <div>
                <label for="code" class="etiquette">{{ recuperation ? 'Code de récupération' : 'Code à 6 chiffres' }}</label>
                <input v-if="!recuperation" id="code" v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" pattern="[0-9]*" class="champ text-center text-2xl tracking-widest" required>
                <input v-else id="code" v-model="form.code" type="text" autocomplete="off" autocapitalize="characters"
                       maxlength="11" class="champ text-center text-xl tracking-widest" required>
                <p v-if="form.errors.code" class="erreur">{{ form.errors.code }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Se connecter</button>
        </form>

        <div class="mt-6 space-y-3 text-center text-sm">
            <button type="button" class="text-slate-600 underline" @click="recuperation = !recuperation; form.reset('code')">
                {{ recuperation ? 'Utiliser l\'application d\'authentification' : 'Téléphone perdu ? Utiliser un code de récupération' }}
            </button>
            <Link href="/personnel/connexion" class="block text-slate-600 underline">Recommencer</Link>
        </div>
    </GabaritPersonnel>
</template>
