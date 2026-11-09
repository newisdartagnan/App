<script setup>
// PRE-03 : vérification publique d'une ordonnance (QR code du pharmacien).
// Le patient n'apparaît que par ses initiales ; un code inconnu reçoit une
// réponse neutre.
import { Head } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';
import EnTeteDePage from '../../Composants/Ui/EnTeteDePage.vue';

defineProps({ verification: Object });
</script>

<template>
    <Head title="Vérification d'ordonnance" />
    <Gabarit>
        <EnTeteDePage titre="Vérification d'ordonnance" pictogramme="bouclier" />

        <div v-if="!verification" class="mt-6 carte p-4">
            <p class="font-semibold">Aucune ordonnance ne correspond à ce code.</p>
            <p class="mt-1 text-sm text-slate-600">Vérifiez que le QR code a été scanné en entier, ou demandez au patient le PDF de son ordonnance.</p>
        </div>

        <template v-else>
            <p v-if="verification.statut === 'authentique'" class="mt-6 rounded-xl border border-vert-200 bg-vert-50 p-4 font-semibold text-vert-900" role="status">
                Ordonnance authentique : son contenu est celui signé par le médecin.
            </p>
            <p v-else class="mt-6 rounded-xl border border-red-300 bg-red-50 p-4 font-semibold text-red-800" role="alert">
                Ordonnance altérée : son contenu ne correspond plus à la signature du médecin. Ne la délivrez pas.
            </p>

            <dl class="mt-4 space-y-1 carte p-4 text-sm">
                <div class="flex justify-between gap-2"><dt class="text-slate-600">Numéro</dt><dd class="font-semibold">{{ verification.numero }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-600">Date</dt><dd>{{ verification.date }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-600">Médecin</dt><dd>{{ verification.medecin }}</dd></div>
                <div v-if="verification.numeroOrdre" class="flex justify-between gap-2"><dt class="text-slate-600">N° d'Ordre</dt><dd>{{ verification.numeroOrdre }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-600">Patient</dt><dd>{{ verification.patient }}</dd></div>
            </dl>

            <ol class="mt-4 space-y-2">
                <li v-for="l in verification.lignes" :key="l.rang" class="carte p-3 text-sm">
                    <p class="font-semibold">{{ l.rang }}. {{ l.medicament }} — {{ l.dosage }}</p>
                    <p class="text-slate-700">Voie : {{ l.voie }} · {{ l.posologie }} · {{ l.duree }}</p>
                    <p v-if="l.consignes" class="text-slate-700">{{ l.consignes }}</p>
                </li>
            </ol>
            <p v-if="verification.consignes" class="mt-3 whitespace-pre-wrap carte p-3 text-sm">{{ verification.consignes }}</p>
        </template>
    </Gabarit>
</template>
