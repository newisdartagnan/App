<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ plages: Array, jours: Object });

const form = useForm({ jour_semaine: 1, heure_debut: '09:00', heure_fin: '12:00' });
const suppression = useForm({});
const enSuppression = ref(null);

const parJour = computed(() => Object.entries(props.jours).map(([numero, libelle]) => ({
    numero: Number(numero),
    libelle,
    plages: props.plages.filter((p) => p.jourSemaine === Number(numero)),
})));

const ajouter = () => form.post('/personnel/medecin/disponibilites', { preserveScroll: true });
const supprimer = (plage) => {
    enSuppression.value = plage.id;
    suppression.delete(`/personnel/medecin/disponibilites/${plage.id}`, {
        preserveScroll: true,
        onFinish: () => { enSuppression.value = null; },
    });
};
</script>

<template>
    <Head title="Disponibilités" />
    <GabaritPersonnel navigation>
        <h1 class="text-2xl font-bold">Disponibilités de chaque semaine</h1>
        <p class="mt-2 text-slate-700">Heures de Kinshasa. Les patients ne voient des créneaux que dans ces plages, hors absences.</p>

        <ul class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            <li v-for="jour in parJour" :key="jour.numero" class="flex flex-wrap items-center gap-2 px-4 py-3">
                <span class="w-24 font-medium">{{ jour.libelle }}</span>
                <span v-if="jour.plages.length === 0" class="text-sm text-slate-500">Aucune plage</span>
                <span v-for="plage in jour.plages" :key="plage.id"
                      class="inline-flex items-center gap-2 rounded-full bg-teal-50 px-3 py-1 text-sm text-teal-900">
                    {{ plage.heureDebut }} – {{ plage.heureFin }}
                    <button type="button" class="text-red-700 underline" :disabled="suppression.processing"
                            :aria-label="`Supprimer la plage du ${jour.libelle} de ${plage.heureDebut} à ${plage.heureFin}`"
                            @click="supprimer(plage)">
                        {{ enSuppression === plage.id ? '…' : 'Retirer' }}
                    </button>
                </span>
            </li>
        </ul>

        <h2 class="mt-8 text-lg font-semibold">Ajouter une plage</h2>
        <form class="mt-3 space-y-4" @submit.prevent="ajouter">
            <div>
                <label for="jour" class="etiquette">Jour</label>
                <select id="jour" v-model="form.jour_semaine" class="champ">
                    <option v-for="(libelle, numero) in jours" :key="numero" :value="Number(numero)">{{ libelle }}</option>
                </select>
                <p v-if="form.errors.jour_semaine" class="erreur">{{ form.errors.jour_semaine }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="heure_debut" class="etiquette">De</label>
                    <input id="heure_debut" v-model="form.heure_debut" type="time" step="300" class="champ" required>
                </div>
                <div>
                    <label for="heure_fin" class="etiquette">À</label>
                    <input id="heure_fin" v-model="form.heure_fin" type="time" step="300" class="champ" required>
                </div>
            </div>
            <p v-if="form.errors.heure_debut" class="erreur">{{ form.errors.heure_debut }}</p>
            <p v-if="form.errors.heure_fin" class="erreur">{{ form.errors.heure_fin }}</p>
            <button type="submit" class="bouton" :disabled="form.processing">Ajouter</button>
        </form>
    </GabaritPersonnel>
</template>
