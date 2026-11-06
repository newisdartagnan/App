<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';

const props = defineProps({ rendezVous: Object, demande: Object, actions: Object, paiements: Array });
const paiementEnCours = computed(() => props.paiements.find((p) => p.en_cours) ?? null);

// Compte à rebours depuis la durée donnée par le serveur : l'horloge du téléphone peut être fausse.
const restantes = ref(props.rendezVous.secondes_restantes);
let minuterie = null;
onMounted(() => {
    if (restantes.value === null) return;
    minuterie = setInterval(() => {
        restantes.value = Math.max(0, restantes.value - 1);
        if (restantes.value === 0) {
            clearInterval(minuterie);
            router.reload();
        }
    }, 1000);
});
onBeforeUnmount(() => clearInterval(minuterie));
const minutesSecondes = computed(() => {
    const s = restantes.value ?? 0;
    return `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, '0')} s`;
});

const confirmation = ref(false);
const form = useForm({ motif: '' });
const annuler = () => form.post(`/mes-rendez-vous/${props.rendezVous.id}/annulation`, {
    preserveScroll: true,
    onSuccess: () => { confirmation.value = false; },
});
const texteRemboursement = computed(() => {
    const taux = props.actions.taux_si_annulation;
    if (taux === null || taux === undefined) return 'Rien n\'a encore été payé.';
    if (taux === 100) return 'Si vous annulez maintenant, vous serez remboursé en entier.';
    if (taux === 0) return 'Si vous annulez maintenant (moins de 2 heures avant), vous ne serez pas remboursé.';
    return `Si vous annulez maintenant, ${taux} % du prix vous sera remboursé.`;
});
</script>

<template>
    <Head title="Mon rendez-vous" />
    <Gabarit>
        <h1 class="text-2xl font-bold first-letter:uppercase">{{ rendezVous.quand }}</h1>
        <p class="mt-1 text-slate-700">{{ rendezVous.medecin }} · {{ rendezVous.type }} ({{ rendezVous.duree_minutes }} min)</p>
        <p class="mt-1 text-slate-700">Prix : {{ rendezVous.prix }}</p>
        <p class="mt-1 text-sm text-slate-500">Heure de Kinshasa.</p>

        <div v-if="rendezVous.en_attente" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900" role="status">
            <p class="font-semibold">En attente de paiement</p>
            <p v-if="restantes > 0" class="mt-1">Créneau gardé encore {{ minutesSecondes }}. Payez pour confirmer le rendez-vous.</p>
            <p v-else class="mt-1">Le délai est dépassé : le créneau va être libéré.</p>
            <Link v-if="paiementEnCours" :href="`/paiements/${paiementEnCours.id}`" class="bouton mt-3">Suivre mon paiement</Link>
            <Link v-else-if="actions.payer && restantes > 0" :href="`/mes-rendez-vous/${rendezVous.id}/paiement`" class="bouton mt-3">
                Payer {{ rendezVous.prix }}
            </Link>
        </div>
        <p v-else class="mt-4 rounded-lg bg-white p-3 font-medium shadow-sm">{{ rendezVous.statut_libelle }}</p>
        <p v-if="$page.props.errors.paiement" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ $page.props.errors.paiement }}</p>

        <div v-if="actions.rejoindre" class="mt-4 rounded-lg border border-vert-200 bg-vert-50 p-4">
            <p class="font-semibold text-vert-900">La salle de consultation est ouverte.</p>
            <Link :href="`/consultation/${rendezVous.id}`" class="bouton mt-3">Rejoindre la consultation</Link>
        </div>
        <p v-if="$page.props.errors.consultation" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ $page.props.errors.consultation }}</p>

        <section v-if="paiements.some((p) => p.statut === 'reussi')" class="mt-4 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Paiement</h2>
            <template v-for="p in paiements" :key="p.id">
                <div v-if="p.statut === 'reussi'" class="mt-2">
                    <p class="text-vert-700">{{ p.montant }} — {{ p.statut_libelle }}</p>
                    <a v-if="p.facture" :href="`/factures/${p.facture.id}`" class="text-sm text-marque-800 underline">Reçu {{ p.facture.numero }}</a>
                    <p v-for="(r, i) in p.remboursements" :key="i" class="mt-1 text-sm text-slate-700">
                        Remboursement de {{ r.montant }} ({{ r.motif }}) : {{ r.statut }}
                    </p>
                </div>
            </template>
        </section>

        <p v-if="rendezVous.taux_remboursement !== null" class="mt-3 text-slate-700">
            Remboursement prévu : {{ rendezVous.taux_remboursement }} % du prix.
        </p>
        <p v-if="rendezVous.motif_annulation" class="mt-1 text-slate-700">Motif : {{ rendezVous.motif_annulation }}</p>

        <p v-if="form.errors.annulation" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ form.errors.annulation }}</p>
        <p v-if="$page.props.errors.creneau" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ $page.props.errors.creneau }}</p>

        <div class="mt-6 space-y-3">
            <Link v-if="actions.reporter" :href="`/mes-rendez-vous/${rendezVous.id}/report`" class="bouton-secondaire">
                Changer l'heure
            </Link>
            <p v-else-if="actions.reports_epuises" class="text-sm text-slate-600">
                Ce rendez-vous a déjà été déplacé plusieurs fois : il ne peut plus l'être.
            </p>
            <p v-else-if="actions.report_tardif" class="text-sm text-slate-600">
                À moins de 2 heures du rendez-vous, vous l'avez déjà déplacé une fois : ce n'est plus possible.
            </p>

            <template v-if="actions.annuler">
                <button v-if="!confirmation" type="button" class="bouton-secondaire" @click="confirmation = true">
                    Annuler ce rendez-vous
                </button>
                <form v-else class="rounded-lg bg-white p-4 shadow-sm" @submit.prevent="annuler">
                    <p class="font-medium">{{ texteRemboursement }}</p>
                    <label for="motif" class="etiquette mt-3">Pourquoi annulez-vous ? (facultatif)</label>
                    <input id="motif" v-model="form.motif" class="champ" maxlength="255">
                    <p v-if="form.errors.motif" class="erreur">{{ form.errors.motif }}</p>
                    <button type="submit" class="bouton mt-3 bg-red-700 hover:bg-red-800" :disabled="form.processing">
                        Oui, annuler
                    </button>
                    <button type="button" class="bouton-secondaire mt-2" :disabled="form.processing" @click="confirmation = false">
                        Non, garder
                    </button>
                </form>
            </template>

            <Link v-if="demande.peut_reserver" :href="`/consultation/demandes/${demande.id}/choix`" class="bouton">
                Choisir un autre créneau
            </Link>
        </div>

        <section class="mt-8 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Votre demande</h2>
            <p class="mt-2">{{ demande.motif }}</p>
            <p v-if="demande.depuis" class="mt-1 text-sm text-slate-600">{{ demande.depuis }}</p>
            <p v-if="demande.symptomes" class="mt-2 whitespace-pre-line text-slate-700">{{ demande.symptomes }}</p>
            <ul v-if="demande.pieces.length" class="mt-3 space-y-1">
                <li v-for="p in demande.pieces" :key="p.id">
                    <a :href="`/consultation/pieces-jointes/${p.id}`" class="text-marque-700 underline">{{ p.nom }}</a>
                </li>
            </ul>
        </section>

        <Link href="/mes-rendez-vous" class="bouton-secondaire mt-6">Mes rendez-vous</Link>
    </Gabarit>
</template>
