<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

const props = defineProps({ aValider: Array, aSuivre: Array, recents: Array, numero: String, paiementTrouve: Object });

const validation = useForm({});
const refus = useForm({ motif: '' });
const enRefus = ref(null);
const enCours = ref(null);

const valider = (r) => {
    if (!window.confirm(`Valider le remboursement de ${r.montant} ? Il sera envoyé à l'opérateur.`)) {
        return;
    }
    enCours.value = r.id;
    validation.post(`/personnel/caisse/remboursements/${r.id}/validation`, {
        preserveScroll: true,
        onFinish: () => { enCours.value = null; },
    });
};
const ouvrirRefus = (id) => { enRefus.value = id; refus.reset(); refus.clearErrors(); };
const refuser = (r) => refus.post(`/personnel/caisse/remboursements/${r.id}/refus`, {
    preserveScroll: true,
    onSuccess: () => { enRefus.value = null; },
});

const simulation = useForm({ resultat: null });
const simuler = (r, resultat) => {
    simulation.resultat = resultat;
    simulation.post(`/personnel/caisse/remboursements/${r.id}/simulation`, { preserveScroll: true });
};

const recherche = ref(props.numero ?? '');
const chercher = () => router.get('/personnel/caisse/remboursements', { numero: recherche.value }, { preserveScroll: true, preserveState: true });

const geste = useForm({ numero: props.numero ?? '', montant: '', justification: '' });
const demanderGeste = () => geste.post('/personnel/caisse/remboursements/geste', { preserveScroll: true, onSuccess: () => geste.reset('montant', 'justification') });
</script>

<template>
    <Head title="Remboursements" />
    <GabaritPersonnel navigation espace="caisse">
        <EnTeteDePage titre="Remboursements à valider" sous-titre="Toujours sur le moyen de paiement d'origine. Vous ne validez jamais une demande que vous avez faite." pictogramme="remboursement" />

        <p v-if="validation.errors.remboursement" class="erreur">{{ validation.errors.remboursement }}</p>
        <p v-if="simulation.errors.remboursement" class="erreur">{{ simulation.errors.remboursement }}</p>
        <ul v-if="aValider.length" class="mt-4 divide-y divide-slate-200 carte">
            <li v-for="r in aValider" :key="r.id" class="px-4 py-3 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <strong class="text-base">{{ r.montant }}</strong>
                    <span class="text-slate-600">{{ r.moyen }}</span>
                </div>
                <p>{{ r.motif }}<span v-if="r.justification"> : « {{ r.justification }} »</span></p>
                <p class="text-slate-600">Facture {{ r.facture ?? '—' }} · {{ r.patient }}</p>
                <p class="text-slate-500">Demandé par {{ r.demandePar }}, {{ r.demandeLe }}</p>

                <div class="mt-2 flex flex-wrap gap-2">
                    <button v-if="r.peutValider" type="button" class="bouton w-auto bg-vert-700 hover:bg-vert-900"
                            :disabled="validation.processing" @click="valider(r)">
                        {{ enCours === r.id ? 'Envoi…' : 'Valider' }}
                    </button>
                    <span v-else class="text-slate-500">Votre demande : un autre agent la valide.</span>
                    <button v-if="enRefus !== r.id" type="button" class="text-red-700 underline" @click="ouvrirRefus(r.id)">Refuser</button>
                </div>
                <form v-if="enRefus === r.id" class="mt-3 space-y-2" @submit.prevent="refuser(r)">
                    <label :for="`motif-${r.id}`" class="etiquette">Motif du refus</label>
                    <input :id="`motif-${r.id}`" v-model="refus.motif" class="champ" maxlength="255" required>
                    <p v-if="refus.errors.motif" class="erreur">{{ refus.errors.motif }}</p>
                    <div class="flex gap-2">
                        <button type="submit" class="bouton bg-red-700 hover:bg-red-800" :disabled="refus.processing">Confirmer le refus</button>
                        <button type="button" class="bouton-secondaire" @click="enRefus = null">Annuler</button>
                    </div>
                </form>
            </li>
        </ul>
        <p v-else class="mt-4 text-slate-600">Aucun remboursement à valider.</p>

        <template v-if="aSuivre.length">
            <h2 class="mt-8 text-lg font-semibold">À suivre</h2>
            <p class="mt-1 text-sm text-slate-700">Sans réponse de l'opérateur, refusés par lui, ou à rembourser à la main. Vérifiez auprès de l'opérateur avant toute nouvelle demande : l'argent est peut-être déjà parti.</p>
            <ul class="mt-3 divide-y divide-amber-200 rounded-xl border border-amber-300 bg-amber-50 text-sm">
                <li v-for="r in aSuivre" :key="r.id" class="px-4 py-3">
                    <div class="flex justify-between gap-2"><strong>{{ r.montant }}</strong><span>{{ r.statut }}</span></div>
                    <p>{{ r.motif }}<span v-if="r.automatique"> (règle automatique)</span> · facture {{ r.facture ?? '—' }} · {{ r.patient }}</p>
                    <p class="text-amber-900">{{ r.raison }}</p>
                </li>
            </ul>
        </template>

        <h2 class="mt-8 text-lg font-semibold">Geste commercial</h2>
        <form class="mt-3 flex gap-2" @submit.prevent="chercher">
            <label for="recherche" class="sr-only">Numéro de facture</label>
            <input id="recherche" v-model="recherche" class="champ mt-0" placeholder="FA-2026-000123" autocapitalize="characters">
            <button type="submit" class="bouton-secondaire w-auto">Chercher</button>
        </form>
        <p v-if="numero && !paiementTrouve" class="erreur">Aucune facture ne porte ce numéro.</p>
        <div v-if="paiementTrouve" class="mt-3 carte px-4 py-3 text-sm">
            <p><strong>{{ paiementTrouve.facture }}</strong> · {{ paiementTrouve.patient }}</p>
            <p>Payé {{ paiementTrouve.paye }} ({{ paiementTrouve.moyen }}) · reste remboursable {{ paiementTrouve.resteRemboursable }}</p>
            <form class="mt-3 space-y-3" @submit.prevent="demanderGeste">
                <div>
                    <label for="montant" class="etiquette">Montant ({{ paiementTrouve.devise }})</label>
                    <input id="montant" v-model="geste.montant" inputmode="decimal" class="champ" required>
                    <p v-if="geste.errors.montant" class="erreur">{{ geste.errors.montant }}</p>
                </div>
                <div>
                    <label for="justification" class="etiquette">Motif (obligatoire)</label>
                    <input id="justification" v-model="geste.justification" maxlength="255" class="champ" required>
                    <p v-if="geste.errors.justification" class="erreur">{{ geste.errors.justification }}</p>
                </div>
                <p v-if="geste.errors.numero" class="erreur">{{ geste.errors.numero }}</p>
                <button type="submit" class="bouton" :disabled="geste.processing">Demander le geste commercial</button>
            </form>
        </div>

        <h2 class="mt-8 text-lg font-semibold">Derniers traités</h2>
        <ul class="mt-2 divide-y divide-slate-200 carte text-sm">
            <li v-for="r in recents" :key="r.id" class="px-4 py-2">
                <div class="flex justify-between gap-2">
                    <span>{{ r.montant }} · {{ r.motif }}<span v-if="r.motifRefus" class="text-slate-500"> ({{ r.motifRefus }})</span></span>
                    <span class="text-slate-600">{{ r.statut }}</span>
                </div>
                <div v-if="r.simulable" class="mt-2 rounded-lg border border-dashed border-slate-400 p-3">
                    <p class="text-sm font-semibold text-slate-700">Simulation (développement seulement)</p>
                    <button type="button" class="bouton mt-2" :disabled="simulation.processing" @click="simuler(r, 'reussi')">Simuler : remboursement réussi</button>
                    <button type="button" class="bouton-secondaire mt-2" :disabled="simulation.processing" @click="simuler(r, 'echoue')">Simuler : remboursement refusé</button>
                </div>
            </li>
            <li v-if="recents.length === 0" class="px-4 py-2 text-slate-500">Aucun.</li>
        </ul>
    </GabaritPersonnel>
</template>
