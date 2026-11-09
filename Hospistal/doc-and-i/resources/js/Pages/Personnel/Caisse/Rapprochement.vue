<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

defineProps({ ouverts: Array, traites: Array });

const traitement = useForm({ commentaire: '' });
const enTraitement = ref(null);
const ouvrir = (id) => { enTraitement.value = id; traitement.reset(); traitement.clearErrors(); };
const traiter = (e) => traitement.post(`/personnel/caisse/rapprochement/${e.id}/traitement`, {
    preserveScroll: true,
    onSuccess: () => { enTraitement.value = null; },
});
</script>

<template>
    <Head title="Rapprochement" />
    <GabaritPersonnel navigation espace="caisse">
        <EnTeteDePage titre="Rapprochement" sous-titre="Chaque nuit, le relevé de l'opérateur de la veille est comparé à nos paiements et remboursements." pictogramme="balance" />

        <ul v-if="ouverts.length" class="mt-4 divide-y divide-slate-200 carte text-sm">
            <li v-for="e in ouverts" :key="e.id" class="px-4 py-3">
                <p><strong>{{ e.jour }}</strong> · {{ e.operation }} · {{ e.nature }}</p>
                <p class="text-slate-600">
                    <span v-if="e.reference">Réf. {{ e.reference }} · </span>
                    <span v-if="e.attendu">chez nous {{ e.attendu }}</span>
                    <span v-if="e.releve"> · au relevé {{ e.releve }}</span>
                </p>
                <button v-if="enTraitement !== e.id" type="button" class="mt-2 text-marque-700 underline" @click="ouvrir(e.id)">Traiter</button>
                <form v-else class="mt-2 space-y-2" @submit.prevent="traiter(e)">
                    <label :for="`c-${e.id}`" class="etiquette">Ce qui a été fait (obligatoire)</label>
                    <textarea :id="`c-${e.id}`" v-model="traitement.commentaire" maxlength="500" rows="3" class="champ" required></textarea>
                    <p v-if="traitement.errors.commentaire" class="erreur">{{ traitement.errors.commentaire }}</p>
                    <button type="submit" class="bouton" :disabled="traitement.processing">Marquer comme traité</button>
                </form>
            </li>
        </ul>
        <p v-else class="mt-4 rounded-xl border border-vert-200 bg-vert-50 px-4 py-3 text-sm text-vert-900">Aucun écart ouvert.</p>

        <h2 class="mt-8 text-lg font-semibold">Derniers traités</h2>
        <ul class="mt-2 divide-y divide-slate-200 carte text-sm">
            <li v-for="e in traites" :key="e.id" class="px-4 py-2">
                <p>{{ e.jour }} · {{ e.nature }} — traité {{ e.traiteLe }}</p>
                <p class="text-slate-600">{{ e.commentaire }}</p>
            </li>
            <li v-if="traites.length === 0" class="px-4 py-2 text-slate-500">Aucun.</li>
        </ul>
    </GabaritPersonnel>
</template>
