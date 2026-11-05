<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ numero: String, pieces: Array });

const recherche = ref(props.numero ?? '');
const enCours = ref(false);
const chercher = () => router.get('/personnel/caisse/factures', { numero: recherche.value }, {
    preserveState: true,
    onStart: () => { enCours.value = true; },
    onFinish: () => { enCours.value = false; },
});
</script>

<template>
    <Head title="Factures et avoirs" />
    <GabaritPersonnel navigation espace="caisse">
        <h1 class="text-2xl font-bold">Factures et avoirs</h1>

        <form class="mt-4 flex gap-2" @submit.prevent="chercher">
            <label for="numero" class="sr-only">Numéro</label>
            <input id="numero" v-model="recherche" class="champ mt-0" placeholder="FA-2026-000123 ou AV-2026-000004" autocapitalize="characters" required>
            <button type="submit" class="bouton w-auto" :disabled="enCours">Chercher</button>
        </form>

        <p v-if="numero && pieces.length === 0" class="mt-4 text-slate-600">Aucune pièce ne porte ce numéro.</p>
        <ul v-else class="mt-4 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="p in pieces" :key="p.id" class="px-4 py-3">
                <div class="flex justify-between gap-2"><strong>{{ p.type }} {{ p.numero }}</strong><span>{{ p.montant }}</span></div>
                <p>{{ p.patient }} · {{ p.moyen }} · {{ p.emiseLe }}</p>
                <p class="text-slate-600">{{ p.libelle }}</p>
                <a :href="`/personnel/caisse/factures/${p.id}/pdf`" class="mt-1 inline-block text-marque-700 underline">Télécharger le PDF</a>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
