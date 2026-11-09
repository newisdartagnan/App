<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';
import EnTeteDePage from '../../Composants/Ui/EnTeteDePage.vue';

const props = defineProps({
    rendezVous: Object,
    devises: Array,
    moyens: Array,
    telephone: String,
    secondesRestantes: Number,
    cle: String,
});

// La clé vient du serveur : un double envoi la réutilise, il n'y a qu'un débit (RG-02).
const form = useForm({
    moyen: null,
    devise: props.devises[0]?.code ?? 'USD',
    telephone: props.telephone,
    cle: props.cle,
});

const moyen = computed(() => props.moyens.find((m) => m.valeur === form.moyen) ?? null);
const devise = computed(() => props.devises.find((d) => d.code === form.devise));
const montant = computed(() => devise.value?.montant);

const restantes = ref(props.secondesRestantes);
let minuterie = null;
onMounted(() => {
    minuterie = setInterval(() => {
        restantes.value = Math.max(0, restantes.value - 1);
        if (restantes.value === 0) clearInterval(minuterie);
    }, 1000);
});
onBeforeUnmount(() => clearInterval(minuterie));
const minutesSecondes = computed(() => `${Math.floor(restantes.value / 60)} min ${String(restantes.value % 60).padStart(2, '0')} s`);

// Le montant affiché part avec la demande : si le tarif a changé entre-temps, le serveur refuse.
const payer = () => form
    .transform((donnees) => ({ ...donnees, montant: devise.value?.valeur }))
    .post(`/mes-rendez-vous/${props.rendezVous.id}/paiement`, { preserveScroll: true, onError: () => router.reload({ only: ['devises'] }) });
</script>

<template>
    <Head title="Payer la consultation" />
    <Gabarit>
        <EnTeteDePage titre="Payer la consultation" pictogramme="carte" />
        <p class="mt-2 text-slate-700 first-letter:uppercase">{{ rendezVous.quand }}</p>
        <p class="text-slate-700">{{ rendezVous.medecin }} · {{ rendezVous.type }}</p>

        <p class="mt-4 rounded-xl border border-marque-200 bg-marque-50 p-3 text-marque-900" role="status">
            <template v-if="restantes > 0">Créneau gardé pour vous encore {{ minutesSecondes }}.</template>
            <template v-else>Le délai est dépassé : le créneau peut être pris par quelqu'un d'autre.</template>
        </p>

        <p v-if="form.errors.paiement" class="erreur mt-4 rounded-xl border border-red-200 bg-red-50 p-3" role="alert">{{ form.errors.paiement }}</p>

        <form class="mt-6 space-y-5" @submit.prevent="payer">
            <fieldset>
                <legend class="etiquette">Comment payer ?</legend>
                <label v-for="m in moyens" :key="m.valeur"
                       class="mt-2 flex items-center gap-3 rounded-lg border p-3"
                       :class="form.moyen === m.valeur ? 'border-marque-700 bg-marque-50' : 'border-slate-300 bg-white'">
                    <input v-model="form.moyen" type="radio" name="moyen" :value="m.valeur"> {{ m.libelle }}
                </label>
                <p v-if="form.errors.moyen" class="erreur">{{ form.errors.moyen }}</p>
            </fieldset>

            <div v-if="moyen?.mobile_money">
                <label for="telephone" class="etiquette">Numéro {{ moyen.libelle }} à débiter</label>
                <input id="telephone" v-model="form.telephone" type="tel" inputmode="tel" autocomplete="tel" class="champ" placeholder="081 234 56 78">
                <p class="mt-1 text-sm text-slate-500">Vous confirmerez le paiement sur ce téléphone, avec votre code secret.</p>
            </div>

            <fieldset v-if="devises.length > 1">
                <legend class="etiquette">Monnaie</legend>
                <div class="mt-2 flex gap-3">
                    <label v-for="d in devises" :key="d.code" class="flex flex-1 items-center gap-2 rounded-lg border border-slate-300 bg-white p-3">
                        <input v-model="form.devise" type="radio" name="devise" :value="d.code"> {{ d.code }}
                    </label>
                </div>
                <p v-if="form.errors.devise" class="erreur">{{ form.errors.devise }}</p>
            </fieldset>

            <p class="text-lg">À payer : <span class="font-bold">{{ montant }}</span></p>

            <button type="submit" class="bouton" :disabled="form.processing || !form.moyen || restantes === 0">
                Payer {{ montant }}
            </button>
        </form>

        <Link :href="`/mes-rendez-vous/${rendezVous.id}`" class="bouton-secondaire mt-4">Retour au rendez-vous</Link>
    </Gabarit>
</template>
