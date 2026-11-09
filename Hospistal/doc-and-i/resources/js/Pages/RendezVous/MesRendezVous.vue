<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';
import EnTeteDePage from '../../Composants/Ui/EnTeteDePage.vue';

defineProps({ aVenir: Array, passes: Array, demandesOuvertes: Array });
</script>

<template>
    <Head title="Mes rendez-vous" />
    <Gabarit>
        <EnTeteDePage titre="Mes rendez-vous" pictogramme="calendrier" />

        <h2 class="mt-6 font-semibold">À venir</h2>
        <p v-if="aVenir.length === 0" class="mt-2 text-slate-600">Aucun rendez-vous à venir.</p>
        <Link v-for="r in aVenir" :key="r.id" :href="`/mes-rendez-vous/${r.id}`"
              class="mt-2 block carte p-4">
            <span class="block font-semibold first-letter:uppercase">{{ r.quand }}</span>
            <span class="block text-sm text-slate-700">{{ r.medecin }} · {{ r.type }}</span>
            <span class="block text-sm" :class="r.en_attente ? 'text-amber-800' : 'text-marque-700'">{{ r.statut_libelle }}</span>
        </Link>

        <template v-if="demandesOuvertes.length">
            <h2 class="mt-6 font-semibold">Demandes sans rendez-vous</h2>
            <div v-for="d in demandesOuvertes" :key="d.id" class="mt-2 carte p-4">
                <p class="font-medium">{{ d.motif }}</p>
                <p class="text-sm text-slate-600 first-letter:uppercase">{{ d.creee }}</p>
                <Link :href="`/consultation/demandes/${d.id}/choix`" class="bouton-secondaire mt-3">Choisir un créneau</Link>
            </div>
        </template>

        <template v-if="passes.length">
            <h2 class="mt-6 font-semibold">Passés et annulés</h2>
            <Link v-for="r in passes" :key="r.id" :href="`/mes-rendez-vous/${r.id}`"
                  class="mt-2 block carte p-4">
                <span class="block first-letter:uppercase">{{ r.quand }}</span>
                <span class="block text-sm text-slate-600">{{ r.medecin }} · {{ r.statut_libelle }}</span>
            </Link>
        </template>

        <nav class="mt-6 space-y-3">
            <Link href="/consultation/demande" class="bouton">Demander une consultation</Link>
            <Link href="/espace" class="bouton-secondaire">Retour à mon espace</Link>
        </nav>
    </Gabarit>
</template>
