<script setup>
// PRE-05, PRE-06 : bons payés transmis à ce laboratoire.
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../Composants/GabaritPersonnel.vue';

defineProps({ laboratoire: String, bons: Array });

const couleur = (statut) => ({
    paye: 'bg-amber-50 text-amber-900',
    en_cours: 'bg-marque-50 text-marque-900',
    resultats_deposes: 'bg-slate-100 text-slate-700',
    valide: 'bg-vert-50 text-vert-900',
}[statut] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head title="Laboratoire" />
    <GabaritPersonnel>
        <h1 class="text-2xl font-bold">{{ laboratoire }}</h1>
        <p class="mt-2 text-sm text-slate-700">Bons payés qui vous sont transmis. Recevez le patient avec son code de retrait, puis déposez les résultats : le médecin les valide avant que le patient ne les voie.</p>

        <p v-if="bons.length === 0" class="mt-6 text-slate-600">Aucun bon pour l'instant.</p>
        <ul class="mt-4 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            <li v-for="b in bons" :key="b.id">
                <Link :href="`/personnel/laboratoire/bons/${b.id}`" class="block px-4 py-3">
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ b.numero }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="couleur(b.statut)">{{ b.statutLibelle }}</span>
                    </span>
                    <span class="block text-sm">{{ b.patient }}<template v-if="b.age !== null">, {{ b.age }} ans</template></span>
                    <span class="block text-sm text-slate-600">{{ b.examens.map((e) => e.libelle).join(', ') }}</span>
                </Link>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
