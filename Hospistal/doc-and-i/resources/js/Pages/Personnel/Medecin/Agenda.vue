<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
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
                </li>
            </ul>
        </section>
    </GabaritPersonnel>
</template>
