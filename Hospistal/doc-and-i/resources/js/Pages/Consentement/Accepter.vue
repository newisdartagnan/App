<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';

defineProps({ version: String, dejaAccepte: Boolean });

const form = useForm({ teleconsultation: false, donnees: false });
const retrait = useForm({});
const accepter = () => form.post('/consentement');
const retirer = () => {
    if (window.confirm('Retirer votre consentement ? Vous ne pourrez plus consulter tant que vous ne l\'aurez pas redonné.')) {
        retrait.post('/consentement/retrait');
    }
};
</script>

<template>
    <Head title="Vos accords" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Avant votre première consultation</h1>
        <p class="mt-2 text-sm text-slate-500">Conditions version {{ version }}</p>

        <div class="mt-4 space-y-3 rounded-lg bg-white p-4 text-sm text-slate-700 shadow-sm">
            <p><strong>La téléconsultation a des limites.</strong> Le médecin ne peut pas vous examiner physiquement ; s'il le juge nécessaire, il vous orientera vers une consultation en présentiel. Elle ne convient pas aux urgences vitales.</p>
            <p><strong>Vos données de santé sont protégées.</strong> Seuls les soignants qui vous prennent en charge y ont accès. Chaque accès à votre dossier est enregistré.</p>
            <p><strong>Vous gardez la main.</strong> Vous pouvez consulter et faire corriger vos données, et retirer cet accord à tout moment depuis cette page.</p>
        </div>

        <form v-if="!dejaAccepte" class="mt-6 space-y-4" @submit.prevent="accepter">
            <label class="flex items-start gap-3 rounded-lg border border-slate-300 bg-white p-4">
                <input v-model="form.teleconsultation" type="checkbox" class="mt-1">
                <span>J'accepte d'être consulté à distance, en connaissant ses limites.</span>
            </label>
            <p v-if="form.errors.teleconsultation" class="erreur">{{ form.errors.teleconsultation }}</p>
            <label class="flex items-start gap-3 rounded-lg border border-slate-300 bg-white p-4">
                <input v-model="form.donnees" type="checkbox" class="mt-1">
                <span>J'accepte que mes données de santé soient traitées pour ma prise en charge.</span>
            </label>
            <p v-if="form.errors.donnees" class="erreur">{{ form.errors.donnees }}</p>
            <button type="submit" class="bouton" :disabled="form.processing">J'accepte et je continue</button>
        </form>

        <div v-else class="mt-6 space-y-3">
            <p class="text-sm text-teal-900">Vous avez donné votre accord pour cette version des conditions.</p>
            <button type="button" class="bouton-secondaire" :disabled="retrait.processing" @click="retirer">Retirer mon consentement</button>
        </div>
    </Gabarit>
</template>
