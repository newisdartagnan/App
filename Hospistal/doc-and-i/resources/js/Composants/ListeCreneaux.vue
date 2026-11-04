<script setup>
// Créneaux regroupés par jour (heure de Kinshasa, calculée par le serveur).
defineProps({ jours: Array, choisi: Object, afficherMedecin: Boolean });
const emit = defineEmits(['choisir']);
</script>

<template>
    <p v-if="jours.length === 0" class="mt-4 rounded-lg bg-white p-4 text-slate-700 shadow-sm">
        Aucun créneau libre dans les prochains jours. Essayez un autre médecin, ou revenez plus tard.
    </p>
    <section v-for="jour in jours" :key="jour.date" class="mt-4">
        <h2 class="font-semibold first-letter:uppercase">{{ jour.libelle }}</h2>
        <div class="mt-2 grid grid-cols-3 gap-2">
            <button v-for="c in jour.creneaux" :key="c.debut + (c.medecin_id ?? '')" type="button"
                    class="rounded-lg border p-2 text-center"
                    :class="choisi?.debut === c.debut ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-300 bg-white'"
                    :aria-pressed="choisi?.debut === c.debut"
                    @click="emit('choisir', c)">
                <span class="block font-semibold">{{ c.heure }}</span>
                <span v-if="afficherMedecin && c.medecin" class="block text-xs">{{ c.medecin }}</span>
            </button>
        </div>
    </section>
</template>
