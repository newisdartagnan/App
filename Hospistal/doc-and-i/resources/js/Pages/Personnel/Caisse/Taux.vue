<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

const props = defineProps({ aujourdhui: Object, enAttente: Object, bornes: Object, historique: Array });

const form = useForm({ taux: props.aujourdhui?.taux ?? '' });
const confirmation = useForm({});
const enregistrer = () => form.post('/personnel/caisse/taux', { preserveScroll: true });
const confirmer = () => confirmation.post(`/personnel/caisse/taux/${props.enAttente.id}/confirmation`, { preserveScroll: true });
</script>

<template>
    <Head title="Taux du jour" />
    <GabaritPersonnel navigation espace="caisse">
        <EnTeteDePage titre="Taux du jour" pictogramme="taux" />
        <p class="mt-2 text-slate-700">
            Francs congolais pour un dollar, aujourd'hui (heure de Kinshasa), entre {{ bornes.minimum }} et {{ bornes.maximum }}.
            Un écart de plus de {{ bornes.ecart }} % avec le dernier taux doit être confirmé par un autre agent.
        </p>

        <p v-if="aujourdhui" class="mt-4 rounded-xl border border-vert-200 bg-vert-50 px-4 py-3 text-sm text-vert-900">
            Actif : <strong>1 USD = {{ aujourdhui.taux }} CDF</strong>, saisi par {{ aujourdhui.saisiPar }}.
            <span v-if="aujourdhui.utilise">Déjà utilisé : une nouvelle saisie le remplace pour les paiements suivants, sans changer les paiements faits.</span>
        </p>

        <div v-if="enAttente" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p>En attente de confirmation : <strong>{{ enAttente.taux }} CDF</strong>, saisi par {{ enAttente.saisiPar }}.</p>
            <button v-if="enAttente.peutConfirmer" type="button" class="bouton mt-2 w-auto" :disabled="confirmation.processing" @click="confirmer">
                Confirmer ce taux
            </button>
            <p v-else class="mt-1">Vous l'avez saisi : un autre agent doit le confirmer.</p>
            <p v-if="confirmation.errors.confirmation" class="erreur">{{ confirmation.errors.confirmation }}</p>
        </div>

        <form class="mt-6 space-y-4" @submit.prevent="enregistrer">
            <div>
                <label for="taux" class="etiquette">1 USD = … CDF</label>
                <input id="taux" v-model="form.taux" type="text" inputmode="decimal" placeholder="2850" class="champ" required>
                <p v-if="form.errors.taux" class="erreur">{{ form.errors.taux }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">
                {{ aujourdhui ? 'Corriger le taux du jour' : 'Enregistrer le taux du jour' }}
            </button>
        </form>

        <h2 class="mt-8 text-lg font-semibold">Derniers taux</h2>
        <ul class="mt-2 divide-y divide-slate-200 carte text-sm">
            <li v-for="t in historique" :key="t.id" class="flex justify-between gap-2 px-4 py-2">
                <span>{{ t.jour }} · {{ t.statut }}</span><span>{{ t.taux }} CDF · {{ t.saisiPar }}</span>
            </li>
            <li v-if="historique.length === 0" class="px-4 py-2 text-slate-500">Aucun taux saisi.</li>
        </ul>
    </GabaritPersonnel>
</template>
