<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ aujourdhui: Object, historique: Array });

const form = useForm({ taux: props.aujourdhui?.taux ?? '' });
const enregistrer = () => form.post('/personnel/caisse/taux', { preserveScroll: true });
</script>

<template>
    <Head title="Taux du jour" />
    <GabaritPersonnel navigation espace="caisse">
        <h1 class="text-2xl font-bold">Taux du jour</h1>
        <p class="mt-2 text-slate-700">Francs congolais pour un dollar, aujourd'hui (heure de Kinshasa). Un taux qui a servi à un paiement ne se modifie plus.</p>

        <form v-if="!aujourdhui || aujourdhui.modifiable" class="mt-6 space-y-4" @submit.prevent="enregistrer">
            <div>
                <label for="taux" class="etiquette">1 USD = … CDF</label>
                <input id="taux" v-model="form.taux" type="text" inputmode="decimal" placeholder="2850" class="champ" required>
                <p v-if="form.errors.taux" class="erreur">{{ form.errors.taux }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">
                {{ aujourdhui ? 'Corriger le taux du jour' : 'Enregistrer le taux du jour' }}
            </button>
        </form>
        <p v-else class="mt-6 rounded-lg border border-slate-200 bg-white px-4 py-3">
            Aujourd'hui : <strong>{{ aujourdhui.taux }} CDF</strong>, saisi par {{ aujourdhui.saisiPar }}. Déjà utilisé par un paiement : figé.
        </p>

        <h2 class="mt-8 text-lg font-semibold">Derniers taux</h2>
        <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="t in historique" :key="t.id" class="flex justify-between px-4 py-2">
                <span>{{ t.jour }}</span><span>{{ t.taux }} CDF · {{ t.saisiPar }}</span>
            </li>
            <li v-if="historique.length === 0" class="px-4 py-2 text-slate-500">Aucun taux saisi.</li>
        </ul>
    </GabaritPersonnel>
</template>
