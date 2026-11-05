<script setup>
import { Head, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ filtres: Object, moyens: Array, medecins: Array, journal: Object });

const choix = reactive({ ...props.filtres });
const enCours = ref(false);
const filtrer = () => router.get('/personnel/caisse/journal',
    Object.fromEntries(Object.entries(choix).filter(([, v]) => v)),
    { preserveScroll: true, onStart: () => { enCours.value = true; }, onFinish: () => { enCours.value = false; } });
</script>

<template>
    <Head title="Journal de caisse" />
    <GabaritPersonnel navigation espace="caisse">
        <h1 class="text-2xl font-bold">Journal de caisse</h1>
        <p class="mt-2 text-slate-700">Totaux par devise : les dollars et les francs congolais ne s'additionnent jamais.</p>

        <form class="mt-4 grid grid-cols-2 gap-3" @submit.prevent="filtrer">
            <div>
                <label for="du" class="etiquette">Du</label>
                <input id="du" v-model="choix.du" type="date" class="champ">
            </div>
            <div>
                <label for="au" class="etiquette">Au</label>
                <input id="au" v-model="choix.au" type="date" class="champ">
            </div>
            <div>
                <label for="moyen" class="etiquette">Moyen</label>
                <select id="moyen" v-model="choix.moyen" class="champ">
                    <option :value="null">Tous</option>
                    <option v-for="m in moyens" :key="m.valeur" :value="m.valeur">{{ m.libelle }}</option>
                </select>
            </div>
            <div>
                <label for="medecin" class="etiquette">Médecin</label>
                <select id="medecin" v-model="choix.medecin" class="champ">
                    <option :value="null">Tous</option>
                    <option v-for="m in medecins" :key="m.id" :value="m.id">{{ m.nom }}</option>
                </select>
            </div>
            <button type="submit" class="bouton col-span-2" :disabled="enCours">Afficher</button>
        </form>

        <h2 class="mt-6 text-lg font-semibold">Totaux</h2>
        <table class="mt-2 w-full rounded-lg border border-slate-200 bg-white text-sm">
            <thead><tr class="text-left text-slate-600"><th class="px-3 py-2">Devise</th><th class="px-3 py-2">Encaissé</th><th class="px-3 py-2">Remboursé</th><th class="px-3 py-2">Net</th></tr></thead>
            <tbody>
                <tr v-for="t in journal.totaux" :key="t.devise" class="border-t border-slate-200">
                    <td class="px-3 py-2 font-semibold">{{ t.devise }}</td><td class="px-3 py-2">{{ t.encaisse }}</td><td class="px-3 py-2">{{ t.rembourse }}</td><td class="px-3 py-2 font-semibold">{{ t.net }}</td>
                </tr>
                <tr v-if="journal.totaux.length === 0"><td colspan="4" class="px-3 py-2 text-slate-500">Aucun mouvement.</td></tr>
            </tbody>
        </table>

        <h2 class="mt-6 text-lg font-semibold">Par moyen</h2>
        <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="t in journal.parMoyen" :key="t.moyen + t.devise" class="flex justify-between px-3 py-2"><span>{{ t.libelle }}</span><span>net {{ t.net }}</span></li>
        </ul>

        <h2 class="mt-6 text-lg font-semibold">Par médecin</h2>
        <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="t in journal.parMedecin" :key="(t.medecin_id ?? '-') + t.devise" class="flex justify-between px-3 py-2"><span>{{ t.libelle }}</span><span>net {{ t.net }}</span></li>
        </ul>

        <h2 class="mt-6 text-lg font-semibold">Mouvements</h2>
        <p v-if="journal.tronque" class="text-sm text-amber-800">Liste tronquée : réduisez la période.</p>
        <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="l in journal.lignes" :key="l.id" class="px-3 py-2">
                <div class="flex justify-between"><span>{{ l.jour }} · {{ l.type }}</span><strong :class="l.type === 'Remboursement' ? 'text-red-700' : 'text-vert-700'">{{ l.montant }}</strong></div>
                <div class="text-slate-600">{{ l.moyen }}<span v-if="l.medecin"> · {{ l.medecin }}</span><span v-if="l.facture"> · {{ l.facture }}</span></div>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
