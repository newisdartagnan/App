<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ rendezVous: Array });

// Regroupés par jour, dans l'ordre déjà donné par le serveur.
const parJour = computed(() => {
    const groupes = [];
    for (const rdv of props.rendezVous) {
        const dernier = groupes[groupes.length - 1];
        if (dernier && dernier.date === rdv.date) {
            dernier.rendezVous.push(rdv);
        } else {
            groupes.push({ date: rdv.date, rendezVous: [rdv] });
        }
    }
    return groupes;
});

// Un seul formulaire d'annulation ouvert à la fois.
const enAnnulation = ref(null);
const annulation = useForm({ motif: '' });
const ouvrir = (id) => { enAnnulation.value = id; annulation.reset(); annulation.clearErrors(); };
const annuler = (rdv) => {
    if (!window.confirm(`Annuler le rendez-vous de ${rdv.heure} avec ${rdv.patient} ? Le patient sera remboursé et prévenu par SMS.`)) {
        return;
    }
    annulation.post(`/personnel/medecin/rendez-vous/${rdv.id}/annulation`, {
        preserveScroll: true,
        onSuccess: () => { enAnnulation.value = null; },
    });
};

const couleur = (code) => ({
    confirme: 'bg-teal-50 text-teal-900',
    en_attente_paiement: 'bg-amber-50 text-amber-900',
}[code] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head title="Agenda" />
    <GabaritPersonnel navigation>
        <h1 class="text-2xl font-bold">Agenda</h1>
        <p class="mt-2 text-slate-700">Vos rendez-vous à venir, heure de Kinshasa.</p>

        <p v-if="rendezVous.length === 0" class="mt-6 text-slate-600">Aucun rendez-vous à venir.</p>

        <section v-for="jour in parJour" :key="jour.date" class="mt-6">
            <h2 class="text-base font-semibold first-letter:uppercase">{{ jour.date }}</h2>
            <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
                <li v-for="rdv in jour.rendezVous" :key="rdv.id" class="px-4 py-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ rdv.heure }} – {{ rdv.heureFin }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="couleur(rdv.statutCode)">{{ rdv.statut }}</span>
                    </div>
                    <p class="mt-1">{{ rdv.patient }}</p>
                    <p v-if="rdv.motif" class="text-sm text-slate-600">{{ rdv.motif }}</p>

                    <button v-if="rdv.annulable && enAnnulation !== rdv.id" type="button"
                            class="mt-2 text-sm text-red-700 underline" @click="ouvrir(rdv.id)">
                        Annuler ce rendez-vous
                    </button>
                    <form v-if="enAnnulation === rdv.id" class="mt-3 space-y-2" @submit.prevent="annuler(rdv)">
                        <label :for="`motif-${rdv.id}`" class="etiquette">Motif de l'annulation</label>
                        <input :id="`motif-${rdv.id}`" v-model="annulation.motif" class="champ" maxlength="255" required>
                        <p v-if="annulation.errors.motif" class="erreur">{{ annulation.errors.motif }}</p>
                        <div class="flex gap-2">
                            <button type="submit" class="bouton bg-red-700 hover:bg-red-800" :disabled="annulation.processing">Confirmer l'annulation</button>
                            <button type="button" class="bouton-secondaire" @click="enAnnulation = null">Garder</button>
                        </div>
                    </form>
                </li>
            </ul>
        </section>
    </GabaritPersonnel>
</template>
