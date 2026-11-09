<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import Alerte from '../../../Composants/Ui/Alerte.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';
import TuileChiffre from '../../../Composants/Ui/TuileChiffre.vue';

defineProps({ tauxDuJour: String, aFaire: Object });
</script>

<template>
    <Head title="Caisse" />
    <GabaritPersonnel navigation espace="caisse">
        <EnTeteDePage titre="Caisse" sous-titre="Ce qui attend votre validation aujourd'hui." pictogramme="portefeuille" />

        <Alerte v-if="!tauxDuJour" ton="attention" class="mb-5">
            Pas de taux du jour : les patients ne peuvent payer qu'en dollars.
            <Link href="/personnel/caisse/taux" class="font-semibold underline">Saisir le taux</Link>
        </Alerte>
        <Alerte v-else ton="succes" class="mb-5">
            Taux du jour : 1 USD = {{ tauxDuJour }} CDF.
        </Alerte>

        <div class="grid gap-4 sm:grid-cols-3">
            <TuileChiffre :valeur="aFaire.remboursements" libelle="remboursements à valider" pictogramme="remboursement"
                          :couleur="aFaire.remboursements > 0 ? 'ambre' : 'vert'" href="/personnel/caisse/remboursements" />
            <TuileChiffre :valeur="aFaire.ecarts" libelle="écarts de rapprochement" pictogramme="balance"
                          :couleur="aFaire.ecarts > 0 ? 'rouge' : 'vert'" href="/personnel/caisse/rapprochement" />
            <TuileChiffre :valeur="aFaire.reversements" libelle="reversements à payer" pictogramme="portefeuille"
                          couleur="marque" href="/personnel/caisse/reversements" />
        </div>
    </GabaritPersonnel>
</template>
