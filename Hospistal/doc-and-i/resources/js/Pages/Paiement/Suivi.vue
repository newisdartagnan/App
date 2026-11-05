<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import Gabarit from '../../Composants/Gabarit.vue';

const props = defineProps({ paiement: Object, rendezVous: Object, peutReessayer: Boolean, peutRelancer: Boolean, simulation: Boolean });

const relance = useForm({});
const relancer = () => relance.post(`/paiements/${props.paiement.id}/relance`, { preserveScroll: true });

// La page relit le statut auprès du serveur : le navigateur ne confirme jamais rien (PAY-04).
let minuterie = null;
onMounted(() => {
    minuterie = setInterval(() => {
        if (props.paiement.en_cours) router.reload({ only: ['paiement', 'rendezVous', 'peutReessayer', 'peutRelancer', 'simulation'] });
        else clearInterval(minuterie);
    }, 3000);
});
onBeforeUnmount(() => clearInterval(minuterie));

const simulation = useForm({ resultat: null });
const simuler = (resultat) => {
    simulation.resultat = resultat;
    simulation.post(`/paiements/${props.paiement.id}/simulation`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Paiement" />
    <Gabarit>
        <h1 class="text-2xl font-bold">Paiement de {{ paiement.montant }}</h1>
        <p class="mt-1 text-slate-700">{{ paiement.moyen }}<template v-if="paiement.telephone"> · {{ paiement.telephone }}</template></p>

        <div v-if="paiement.statut === 'initie'" class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900" role="status" aria-live="polite">
            <p class="text-lg font-semibold">Nous n'avons pas encore la réponse de l'opérateur</p>
            <p class="mt-1">
                Si un message de paiement s'affiche sur votre téléphone, vous pouvez l'accepter : vous ne serez débité qu'une fois.
                Ne refaites pas un autre paiement : cette page se met à jour toute seule.
            </p>
            <button v-if="peutRelancer" type="button" class="bouton mt-3" :disabled="relance.processing" @click="relancer">
                Relancer la demande
            </button>
        </div>

        <div v-else-if="paiement.en_cours" class="mt-6 rounded-lg border border-marque-200 bg-marque-50 p-4 text-marque-900" role="status" aria-live="polite">
            <p class="text-lg font-semibold">
                {{ paiement.mobile_money ? 'Confirmez sur votre téléphone' : 'Terminez le paiement sur la page de votre banque' }}
            </p>
            <p class="mt-1">
                <template v-if="paiement.mobile_money">Un message de votre opérateur va s'afficher : tapez votre code secret pour accepter.</template>
                Cette page se met à jour toute seule.
            </p>
        </div>

        <div v-else-if="paiement.statut === 'reussi'" class="mt-6 rounded-lg border border-vert-200 bg-vert-50 p-4 text-vert-900" role="status">
            <p class="text-lg font-semibold">Paiement reçu</p>
            <p v-if="rendezVous?.statut === 'confirme'" class="mt-1 first-letter:uppercase">
                Votre rendez-vous est confirmé : {{ rendezVous.quand }}.
            </p>
            <a v-if="paiement.facture" :href="`/factures/${paiement.facture.id}`" class="mt-3 inline-block font-medium text-vert-900 underline">
                Télécharger le reçu {{ paiement.facture.numero }}
            </a>
        </div>

        <div v-else class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-red-900" role="alert">
            <p class="text-lg font-semibold">Le paiement n'a pas abouti</p>
            <p v-if="paiement.motif_echec" class="mt-1">{{ paiement.motif_echec }}</p>
            <p class="mt-1">Aucun montant n'a été encaissé.</p>
        </div>

        <section v-if="paiement.remboursements.length" class="mt-4 rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Remboursement</h2>
            <p v-for="(r, i) in paiement.remboursements" :key="i" class="mt-1 text-slate-700">
                {{ r.montant }} — {{ r.motif }} : {{ r.statut }}
            </p>
        </section>

        <p v-if="$page.props.errors.paiement" class="erreur mt-4 rounded-lg bg-red-50 p-3" role="alert">{{ $page.props.errors.paiement }}</p>

        <div v-if="simulation" class="mt-6 rounded-lg border border-dashed border-slate-400 p-4">
            <p class="text-sm font-semibold text-slate-700">Simulation (développement seulement)</p>
            <button type="button" class="bouton mt-2" :disabled="simulation.processing" @click="simuler('reussi')">Simuler : paiement réussi</button>
            <button type="button" class="bouton-secondaire mt-2" :disabled="simulation.processing" @click="simuler('echoue')">Simuler : paiement échoué</button>
        </div>

        <div class="mt-6 space-y-3">
            <Link v-if="peutReessayer" :href="`/mes-rendez-vous/${rendezVous.id}/paiement`" class="bouton">Réessayer</Link>
            <Link v-if="rendezVous" :href="`/mes-rendez-vous/${rendezVous.id}`" class="bouton-secondaire">Voir le rendez-vous</Link>
            <Link href="/mes-paiements" class="bouton-secondaire">Mes paiements</Link>
        </div>
    </Gabarit>
</template>
