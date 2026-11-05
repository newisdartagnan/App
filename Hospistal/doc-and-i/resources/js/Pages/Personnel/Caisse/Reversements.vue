<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ commissionPourcent: Number, medecins: Array, reversements: Array });

const preparation = useForm({ medecin: '', debut: '', fin: '' });
const preparer = () => preparation.post('/personnel/caisse/reversements', { preserveScroll: true });

const paiement = useForm({ reference: '' });
const enPaiement = ref(null);
const ouvrir = (id) => { enPaiement.value = id; paiement.reset(); paiement.clearErrors(); };
const payer = (r) => paiement.post(`/personnel/caisse/reversements/${r.id}/paiement`, {
    preserveScroll: true,
    onSuccess: () => { enPaiement.value = null; },
});
</script>

<template>
    <Head title="Reversements" />
    <GabaritPersonnel navigation espace="caisse">
        <h1 class="text-2xl font-bold">Reversements aux médecins</h1>
        <p v-if="commissionPourcent === null" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            La commission de la plateforme n'est pas configurée : aucun reversement possible.
        </p>
        <p v-else class="mt-2 text-slate-700">Encaissements moins remboursements des rendez-vous du médecin, par devise, moins {{ commissionPourcent }} % de commission. Payé par un autre agent que celui qui l'a préparé.</p>

        <form class="mt-4 space-y-3" @submit.prevent="preparer">
            <div>
                <label for="medecin" class="etiquette">Médecin</label>
                <select id="medecin" v-model="preparation.medecin" class="champ" required>
                    <option value="" disabled>Choisir</option>
                    <option v-for="m in medecins" :key="m.id" :value="m.id">{{ m.nom }}</option>
                </select>
                <p v-if="preparation.errors.medecin" class="erreur">{{ preparation.errors.medecin }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label for="debut" class="etiquette">Du</label><input id="debut" v-model="preparation.debut" type="date" class="champ" required></div>
                <div><label for="fin" class="etiquette">Au (période close)</label><input id="fin" v-model="preparation.fin" type="date" class="champ" required></div>
            </div>
            <p v-if="preparation.errors.debut || preparation.errors.fin" class="erreur">{{ preparation.errors.debut || preparation.errors.fin }}</p>
            <button type="submit" class="bouton" :disabled="preparation.processing || commissionPourcent === null">Préparer</button>
        </form>

        <ul class="mt-8 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="r in reversements" :key="r.id" class="px-4 py-3">
                <div class="flex justify-between gap-2"><strong>{{ r.medecin }}</strong><span :class="r.paye ? 'text-vert-700' : 'text-amber-800'">{{ r.statut }}</span></div>
                <p>{{ r.periode }} · {{ r.devise }}</p>
                <p>Solde de la période {{ r.solde }}<span v-if="r.detteReprise"> − dette reprise {{ r.detteReprise }}</span></p>
                <p>Brut {{ r.brut }} − commission {{ r.commission }} = <strong>{{ r.net }}</strong></p>
                <p v-if="r.detteReportee" class="font-medium text-red-700">Dette reportée sur le prochain reversement : {{ r.detteReportee }}</p>
                <p v-if="r.paye" class="text-slate-600">Payé le {{ r.payeLe }}, virement {{ r.referenceVirement }}</p>
                <button v-if="r.peutPayer && enPaiement !== r.id" type="button" class="mt-2 text-marque-700 underline" @click="ouvrir(r.id)">Payer</button>
                <span v-else-if="r.aPayer && !r.peutPayer" class="text-slate-500">Préparé par vous : un autre agent le paie.</span>
                <form v-if="enPaiement === r.id" class="mt-2 space-y-2" @submit.prevent="payer(r)">
                    <label :for="`ref-${r.id}`" class="etiquette">Référence du virement</label>
                    <input :id="`ref-${r.id}`" v-model="paiement.reference" maxlength="100" class="champ" required>
                    <p v-if="paiement.errors.reference" class="erreur">{{ paiement.errors.reference }}</p>
                    <button type="submit" class="bouton bg-vert-700 hover:bg-vert-900" :disabled="paiement.processing">Confirmer le paiement</button>
                </form>
            </li>
            <li v-if="reversements.length === 0" class="px-4 py-3 text-slate-500">Aucun reversement.</li>
        </ul>
    </GabaritPersonnel>
</template>
