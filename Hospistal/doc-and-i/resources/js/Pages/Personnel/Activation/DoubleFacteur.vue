<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

// Le QR code est une image SVG produite par le serveur (data:) : aucun script, rien d'un autre domaine.
defineProps({ qrCode: String, secret: String, email: String });

const form = useForm({ code: '' });
const confirmer = () => form.post('/personnel/activation/double-facteur', { onFinish: () => form.reset('code') });
</script>

<template>
    <Head title="Double authentification" />
    <GabaritPersonnel>
        <p class="text-sm font-medium text-marque-700">Étape 2 sur 2</p>
        <EnTeteDePage titre="Reliez votre application d'authentification" />
        <ol class="mt-3 list-decimal space-y-1 pl-5 text-slate-700">
            <li>Installez une application d'authentification (Google Authenticator, FreeOTP, Aegis…).</li>
            <li>Ajoutez un compte en scannant ce code.</li>
            <li>Saisissez le code à 6 chiffres qu'elle affiche.</li>
        </ol>

        <div class="mt-6 flex justify-center">
            <img :src="qrCode" width="220" height="220" alt="QR code à scanner avec l'application d'authentification"
                 class="carte p-2">
        </div>

        <details class="mt-4 text-sm text-slate-700">
            <summary class="cursor-pointer underline">Impossible de scanner ? Saisir la clé à la main</summary>
            <p class="mt-2">Compte : {{ email }} — type : basé sur le temps.</p>
            <p class="mt-1 break-all rounded bg-slate-100 px-3 py-2 font-mono text-base tracking-wider">{{ secret }}</p>
        </details>

        <form class="mt-6 space-y-4" @submit.prevent="confirmer">
            <div>
                <label for="code" class="etiquette">Code à 6 chiffres</label>
                <input id="code" v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" pattern="[0-9]*" class="champ text-center text-2xl tracking-widest" required>
                <p v-if="form.errors.code" class="erreur">{{ form.errors.code }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Activer mon compte</button>
        </form>
    </GabaritPersonnel>
</template>
