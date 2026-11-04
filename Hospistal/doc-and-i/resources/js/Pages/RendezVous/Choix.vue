<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';

const props = defineProps({ demande: Object, specialites: Array, medecins: Array, erreur: String });

const mode = ref('specialite');
const specialiteId = ref(null);
const medecinId = ref('premier');
const typeId = ref(null);
const envoi = ref(false);

const specialite = computed(() => props.specialites.find((s) => s.id === specialiteId.value) ?? null);
const medecinsDeLaSpecialite = computed(() => props.medecins.filter((m) => m.specialite_id === specialiteId.value));
const type = computed(() => specialite.value?.types.find((t) => t.id === typeId.value) ?? null);
const nomLangue = { fr: 'français', ln: 'lingala', sw: 'swahili', kg: 'kikongo', lu: 'tshiluba' };

const choisirSpecialite = (id) => {
    specialiteId.value = id;
    medecinId.value = 'premier';
    typeId.value = null;
};
const choisirMedecin = (medecin) => {
    specialiteId.value = medecin.specialite_id;
    medecinId.value = medecin.id;
    typeId.value = null;
};

const voirCreneaux = () => {
    if (!type.value?.reservable) return;
    router.get(`/consultation/demandes/${props.demande.id}/creneaux`, { type: typeId.value, medecin: medecinId.value }, {
        onStart: () => { envoi.value = true; },
        onFinish: () => { envoi.value = false; },
    });
};
</script>

<template>
    <Head title="Choisir un médecin" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Avec quel médecin ?</h1>
        <p v-if="erreur" class="erreur mt-3 rounded-lg bg-red-50 p-3" role="alert">{{ erreur }}</p>

        <div class="mt-4 flex gap-2" role="tablist">
            <button type="button" role="tab" :aria-selected="mode === 'specialite'"
                    :class="mode === 'specialite' ? 'bouton' : 'bouton-secondaire'" @click="mode = 'specialite'">
                Par spécialité
            </button>
            <button type="button" role="tab" :aria-selected="mode === 'medecin'"
                    :class="mode === 'medecin' ? 'bouton' : 'bouton-secondaire'" @click="mode = 'medecin'">
                Par médecin
            </button>
        </div>

        <section v-if="mode === 'specialite'" class="mt-4 space-y-2">
            <button v-for="s in specialites" :key="s.id" type="button"
                    class="w-full rounded-lg border p-3 text-left"
                    :class="s.id === specialiteId ? 'border-marque-700 bg-vert-50' : 'border-slate-300 bg-white'"
                    :disabled="s.nombre_medecins === 0" @click="choisirSpecialite(s.id)">
                <span class="font-medium">{{ s.nom }}</span>
                <span v-if="s.nombre_medecins === 0" class="block text-sm text-slate-500">Aucun médecin disponible pour l'instant</span>
            </button>
        </section>

        <section v-else class="mt-4 space-y-2">
            <p v-if="medecins.length === 0" class="text-slate-600">Aucun médecin disponible pour l'instant.</p>
            <button v-for="m in medecins" :key="m.id" type="button"
                    class="w-full rounded-lg border p-3 text-left"
                    :class="m.id === medecinId ? 'border-marque-700 bg-vert-50' : 'border-slate-300 bg-white'"
                    @click="choisirMedecin(m)">
                <span class="font-medium">{{ m.nom }}</span>
                <span class="block text-sm text-slate-600">
                    {{ specialites.find((s) => s.id === m.specialite_id)?.nom }}
                    <template v-if="m.langues.length"> · parle {{ m.langues.map((l) => nomLangue[l] ?? l).join(', ') }}</template>
                </span>
            </button>
        </section>

        <section v-if="specialite" class="mt-6 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">{{ specialite.nom }}</h2>

            <div v-if="mode === 'specialite'" class="mt-3">
                <label for="medecin" class="etiquette">Médecin</label>
                <select id="medecin" v-model="medecinId" class="champ">
                    <option value="premier">Le premier médecin disponible</option>
                    <option v-for="m in medecinsDeLaSpecialite" :key="m.id" :value="m.id">{{ m.nom }}</option>
                </select>
            </div>

            <fieldset class="mt-4">
                <legend class="etiquette">Type de consultation</legend>
                <p v-if="specialite.types.length === 0" class="mt-2 text-slate-600">Aucune consultation proposée pour l'instant.</p>
                <label v-for="t in specialite.types" :key="t.id"
                       class="mt-2 flex items-start gap-3 rounded-lg border border-slate-300 p-3"
                       :class="{ 'opacity-60': !t.reservable }">
                    <input v-model="typeId" type="radio" name="type" :value="t.id" :disabled="!t.reservable" class="mt-1">
                    <span>
                        <span class="font-medium">{{ t.nom }}</span>
                        <span class="block text-sm text-slate-600">{{ t.duree_minutes }} minutes</span>
                        <span v-if="t.reservable" class="block font-semibold">{{ t.prix }}</span>
                        <span v-else class="block text-sm text-slate-600">Prix pas encore fixé : ne peut pas encore être réservée.</span>
                    </span>
                </label>
            </fieldset>

            <button type="button" class="bouton mt-4" :disabled="!type?.reservable || envoi" @click="voirCreneaux">
                Voir les créneaux libres
            </button>
        </section>

        <Link href="/espace" class="bouton-secondaire mt-6">Retour à mon espace</Link>
    </Gabarit>
</template>
