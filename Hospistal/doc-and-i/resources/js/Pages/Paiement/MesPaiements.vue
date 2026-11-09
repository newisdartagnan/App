<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';
import EnTeteDePage from '../../Composants/Ui/EnTeteDePage.vue';

defineProps({ paiements: Array, documents: Array });
</script>

<template>
    <Head title="Mes paiements" />
    <Gabarit>
        <EnTeteDePage titre="Mes paiements" pictogramme="carte" />

        <p v-if="paiements.length === 0" class="mt-4 text-slate-600">Aucun paiement pour l'instant.</p>
        <Link v-for="p in paiements" :key="p.id" :href="`/paiements/${p.id}`" class="mt-3 block carte p-4">
            <span class="flex justify-between gap-2">
                <span class="font-semibold">{{ p.montant }}</span>
                <span :class="p.statut === 'reussi' ? 'text-vert-700' : 'text-slate-600'">{{ p.statut_libelle }}</span>
            </span>
            <span class="block text-sm text-slate-600">{{ p.moyen }} · <span class="first-letter:uppercase">{{ p.quand }}</span></span>
            <span v-if="p.rendez_vous" class="block text-sm text-slate-600">Rendez-vous : {{ p.rendez_vous }}</span>
        </Link>

        <template v-if="documents.length">
            <h2 class="mt-8 font-semibold">Factures et avoirs</h2>
            <a v-for="d in documents" :key="d.id" :href="`/factures/${d.id}`" class="mt-2 flex justify-between gap-2 carte p-3">
                <span>
                    <span class="block font-medium text-marque-800 underline">{{ d.type }} {{ d.numero }}</span>
                    <span class="block text-sm text-slate-600 first-letter:uppercase">{{ d.emise }}</span>
                </span>
                <span class="font-semibold">{{ d.montant }}</span>
            </a>
        </template>

        <Link href="/espace" class="bouton-secondaire mt-8">Retour à mon espace</Link>
    </Gabarit>
</template>
