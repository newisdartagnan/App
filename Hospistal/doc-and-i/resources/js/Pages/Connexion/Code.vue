<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';
import Pictogramme from '../../Composants/Ui/Pictogramme.vue';

defineProps({ telephone: String, validiteMinutes: Number });

const form = useForm({ code: '' });
const renvoi = useForm({});
const verifier = () => form.post('/connexion/code');
const renvoyer = () => renvoi.post('/connexion/code/renvoi', { preserveScroll: true });
</script>

<template>
    <Head title="Code reçu par SMS" />
    <Gabarit>
        <div class="carte mx-auto max-w-md p-6 sm:mt-6 sm:p-8">
        <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-marque-700 text-white shadow-sm">
            <Pictogramme nom="cle" :taille="28" />
        </span>
        <h1 class="text-2xl font-bold">Entrez le code reçu</h1>
        <p class="mt-2 text-slate-700">Envoyé au {{ telephone }}. Il est valable {{ validiteMinutes }} minutes.</p>

        <form class="mt-6 space-y-4" @submit.prevent="verifier">
            <div>
                <label for="code" class="etiquette">Code à 6 chiffres</label>
                <input id="code" v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" pattern="[0-9]*" class="champ text-center text-2xl tracking-widest" required>
                <p v-if="form.errors.code" class="erreur">{{ form.errors.code }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Se connecter</button>
        </form>

        <div class="mt-6 space-y-3">
            <button type="button" class="bouton-secondaire" :disabled="renvoi.processing" @click="renvoyer">Renvoyer un code</button>
            <p v-if="renvoi.errors.telephone" class="erreur">{{ renvoi.errors.telephone }}</p>
            <Link href="/connexion" class="block text-center text-sm text-slate-600 underline">Changer de numéro</Link>
        </div>
        </div>
    </Gabarit>
</template>
