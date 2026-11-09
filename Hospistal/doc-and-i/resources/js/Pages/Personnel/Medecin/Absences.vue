<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

defineProps({ absences: Array });

const form = useForm({ debut: '', fin: '', motif: '' });
const suppression = useForm({});
const enSuppression = ref(null);

const ajouter = () => form.post('/personnel/medecin/absences', { preserveScroll: true, onSuccess: () => form.reset() });
const supprimer = (absence) => {
    enSuppression.value = absence.id;
    suppression.delete(`/personnel/medecin/absences/${absence.id}`, {
        preserveScroll: true,
        onFinish: () => { enSuppression.value = null; },
    });
};
</script>

<template>
    <Head title="Absences" />
    <GabaritPersonnel navigation>
        <EnTeteDePage titre="Absences" sous-titre="Congés, formations, empêchements : aucun créneau n'est proposé sur ces périodes (heure de Kinshasa)." pictogramme="absence" />

        <p v-if="suppression.errors.absence" class="erreur">{{ suppression.errors.absence }}</p>
        <ul v-if="absences.length" class="mt-6 divide-y divide-slate-200 carte">
            <li v-for="absence in absences" :key="absence.id" class="flex items-start justify-between gap-3 px-4 py-3">
                <div class="text-sm">
                    <p>Du <strong>{{ absence.debut }}</strong></p>
                    <p>au <strong>{{ absence.fin }}</strong></p>
                    <p v-if="absence.motif" class="text-slate-600">{{ absence.motif }}</p>
                </div>
                <button v-if="absence.supprimable" type="button" class="text-sm text-red-700 underline"
                        :disabled="suppression.processing" @click="supprimer(absence)">
                    {{ enSuppression === absence.id ? '…' : 'Supprimer' }}
                </button>
                <span v-else class="text-sm text-slate-500">En cours</span>
            </li>
        </ul>
        <p v-else class="mt-6 text-slate-600">Aucune absence prévue.</p>

        <h2 class="mt-8 text-lg font-semibold">Déclarer une absence</h2>
        <form class="mt-3 space-y-4" @submit.prevent="ajouter">
            <div>
                <label for="debut" class="etiquette">Début</label>
                <input id="debut" v-model="form.debut" type="datetime-local" class="champ" required>
                <p v-if="form.errors.debut" class="erreur">{{ form.errors.debut }}</p>
            </div>
            <div>
                <label for="fin" class="etiquette">Fin</label>
                <input id="fin" v-model="form.fin" type="datetime-local" class="champ" required>
                <p v-if="form.errors.fin" class="erreur">{{ form.errors.fin }}</p>
            </div>
            <div>
                <label for="motif" class="etiquette">Motif (facultatif, visible de vous seul)</label>
                <input id="motif" v-model="form.motif" type="text" maxlength="255" class="champ">
                <p v-if="form.errors.motif" class="erreur">{{ form.errors.motif }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="form.processing">Enregistrer l'absence</button>
        </form>
    </GabaritPersonnel>
</template>
