<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({ medecin: Object, resume: Object });
</script>

<template>
    <Head title="Espace médecin" />
    <GabaritPersonnel navigation>
        <h1 class="text-2xl font-bold">{{ medecin.nom }}</h1>
        <p class="mt-1 text-slate-700">{{ medecin.specialite }} · Ordre n° {{ medecin.numeroOrdre }}</p>

        <p v-if="!medecin.verifie" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            Votre inscription à l'Ordre est en cours de vérification : vous n'êtes pas encore proposé aux patients.
            Vous pouvez déjà préparer vos disponibilités.
        </p>
        <p v-else-if="!medecin.actif" class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            Votre profil est désactivé : vous n'êtes pas proposé aux patients.
        </p>

        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <Link href="/personnel/medecin/agenda" class="rounded-lg border border-slate-200 bg-white p-4">
                <span class="block text-3xl font-bold text-marque-700">{{ resume.rendezVousAVenir }}</span>
                <span class="text-sm text-slate-700">rendez-vous à venir</span>
            </Link>
            <Link href="/personnel/medecin/disponibilites" class="rounded-lg border border-slate-200 bg-white p-4">
                <span class="block text-3xl font-bold text-marque-700">{{ resume.plages }}</span>
                <span class="text-sm text-slate-700">plages par semaine</span>
            </Link>
            <Link href="/personnel/medecin/absences" class="rounded-lg border border-slate-200 bg-white p-4">
                <span class="block text-3xl font-bold text-marque-700">{{ resume.absencesAVenir }}</span>
                <span class="text-sm text-slate-700">absences prévues</span>
            </Link>
        </div>
    </GabaritPersonnel>
</template>
