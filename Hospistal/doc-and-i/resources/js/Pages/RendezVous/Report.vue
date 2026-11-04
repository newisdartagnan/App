<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';
import ListeCreneaux from '../../Composants/ListeCreneaux.vue';

const props = defineProps({ rendezVous: Object, jours: Array, tardif: Boolean });

const choisi = ref(null);
const form = useForm({ debut: null });
const choisir = (creneau) => {
    choisi.value = creneau;
    form.debut = creneau.debut;
};
const reporter = () => form.post(`/mes-rendez-vous/${props.rendezVous.id}/report`, { preserveScroll: true });
</script>

<template>
    <Head title="Changer l'heure" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Changer l'heure</h1>
        <p class="mt-2 text-slate-700">
            Aujourd'hui prévu : <span class="first-letter:uppercase">{{ rendezVous.quand }}</span>, avec {{ rendezVous.medecin }}.
            Même médecin, même prix.
        </p>
        <p v-if="tardif" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
            Votre rendez-vous est dans moins de 2 heures : vous ne pourrez le déplacer qu'une fois.
        </p>
        <p v-if="form.errors.creneau" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ form.errors.creneau }}</p>

        <ListeCreneaux :jours="jours" :choisi="choisi" @choisir="choisir" />

        <div v-if="choisi" class="sticky bottom-0 mt-6 border-t border-slate-200 bg-slate-50 py-3">
            <button type="button" class="bouton" :disabled="form.processing" @click="reporter">
                Déplacer à {{ choisi.heure }}
            </button>
        </div>

        <Link :href="`/mes-rendez-vous/${rendezVous.id}`" class="bouton-secondaire mt-6">Garder l'heure actuelle</Link>
    </Gabarit>
</template>
