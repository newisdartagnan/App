<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import Alerte from '../../../Composants/Ui/Alerte.vue';
import Badge from '../../../Composants/Ui/Badge.vue';
import Carte from '../../../Composants/Ui/Carte.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';
import Pictogramme from '../../../Composants/Ui/Pictogramme.vue';
import TuileChiffre from '../../../Composants/Ui/TuileChiffre.vue';

defineProps({ medecin: Object, resume: Object });
</script>

<template>
    <Head title="Espace médecin" />
    <GabaritPersonnel navigation>
        <EnTeteDePage :titre="medecin.nom" pictogramme="stethoscope">
            <template #sousTitre>{{ medecin.specialite }} · Ordre n° {{ medecin.numeroOrdre }}</template>
            <template #actions>
                <Badge v-if="medecin.verifie && medecin.actif" ton="succes"><Pictogramme nom="coche" :taille="14" /> Proposé aux patients</Badge>
                <Badge v-else ton="attention">Non proposé aux patients</Badge>
            </template>
        </EnTeteDePage>

        <Alerte v-if="!medecin.verifie" ton="attention" class="mb-5">
            Votre inscription à l'Ordre est en cours de vérification : vous n'êtes pas encore proposé aux patients.
            Vous pouvez déjà préparer vos disponibilités.
        </Alerte>
        <Alerte v-else-if="!medecin.actif" ton="attention" class="mb-5">
            Votre profil est désactivé : vous n'êtes pas proposé aux patients.
        </Alerte>

        <div class="grid gap-4 sm:grid-cols-3">
            <TuileChiffre :valeur="resume.rendezVousAVenir" libelle="rendez-vous à venir" pictogramme="calendrier" couleur="marque" href="/personnel/medecin/agenda" />
            <TuileChiffre :valeur="resume.plages" libelle="plages par semaine" pictogramme="horloge" couleur="vert" href="/personnel/medecin/disponibilites" />
            <TuileChiffre :valeur="resume.absencesAVenir" libelle="absences prévues" pictogramme="absence" couleur="ambre" href="/personnel/medecin/absences" />
        </div>

        <Carte titre="Raccourcis" class="mt-6" plein>
            <nav class="grid divide-y divide-slate-200 sm:grid-cols-2 sm:divide-y-0" aria-label="Raccourcis">
                <Link href="/personnel/medecin/agenda" class="flex min-h-14 items-center gap-3 px-5 hover:bg-slate-50">
                    <Pictogramme nom="video" class="text-marque-700" /><span class="flex-1">Rejoindre une consultation depuis l'agenda</span>
                </Link>
                <Link href="/personnel/medecin/reversements" class="flex min-h-14 items-center gap-3 px-5 hover:bg-slate-50">
                    <Pictogramme nom="portefeuille" class="text-marque-700" /><span class="flex-1">Mes reversements</span>
                </Link>
            </nav>
        </Carte>
    </GabaritPersonnel>
</template>
