<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

defineProps({
    periode: Object,
    periodes: Array,
    parStatut: Array,
    taux: Object,
    delaiAttente: Object,
    chiffreAffaires: Array,
    topMedecins: Array,
    satisfaction: String,
});
</script>

<template>
    <Head title="Tableau de bord" />
    <GabaritPersonnel navigation espace="admin">
        <h1 class="text-2xl font-bold">Tableau de bord</h1>
        <p class="mt-1 text-sm text-slate-600">{{ periode.libelle }}, heure de Kinshasa.</p>

        <nav class="mt-4 flex gap-2" aria-label="Période">
            <Link v-for="p in periodes" :key="p.cle" :href="`/personnel/admin?periode=${p.cle}`" preserve-scroll
                  class="rounded-full border px-3 py-1 text-sm"
                  :class="p.cle === periode.cle ? 'border-marque-700 bg-marque-700 text-white' : 'border-slate-300 bg-white text-slate-700'">
                {{ p.libelle }}
            </Link>
        </nav>

        <section class="mt-6">
            <h2 class="text-lg font-semibold">Chiffre d'affaires</h2>
            <p class="text-sm text-slate-600">Encaissements moins remboursements, par devise (jamais additionnées).</p>
            <p v-if="chiffreAffaires.length === 0" class="mt-2 text-slate-600">Aucun mouvement de caisse sur la période.</p>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <div v-for="c in chiffreAffaires" :key="c.devise" class="rounded-lg border border-vert-200 bg-vert-50 p-4">
                    <p class="text-sm font-medium text-vert-900">{{ c.devise }}</p>
                    <p class="text-2xl font-bold text-vert-900">{{ c.net }}</p>
                    <p class="text-sm text-slate-700">Encaissé {{ c.encaisse }} · remboursé {{ c.rembourse }}</p>
                </div>
            </div>
        </section>

        <section class="mt-6 grid gap-3 sm:grid-cols-2">
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <h2 class="font-semibold">Délai d'attente moyen</h2>
                <p class="mt-1 text-2xl font-bold text-marque-800">{{ delaiAttente.libelle ?? '—' }}</p>
                <p class="text-sm text-slate-600">De la demande au rendez-vous, sur {{ delaiAttente.nombre }} rendez-vous.</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <h2 class="font-semibold">Annulations et absences</h2>
                <p class="text-sm text-slate-600">Sur {{ taux.payes }} rendez-vous payés.</p>
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt>Annulés par le patient</dt><dd>{{ taux.annulationPatient.pourcentage ?? '—' }} ({{ taux.annulationPatient.nombre }})</dd></div>
                    <div class="flex justify-between"><dt>Annulés par le médecin</dt><dd>{{ taux.annulationMedecin.pourcentage ?? '—' }} ({{ taux.annulationMedecin.nombre }})</dd></div>
                    <div class="flex justify-between"><dt>Absences du patient</dt><dd>{{ taux.absencePatient.pourcentage ?? '—' }} ({{ taux.absencePatient.nombre }})</dd></div>
                    <div class="flex justify-between"><dt>Absences du médecin</dt><dd>{{ taux.absenceMedecin.pourcentage ?? '—' }} ({{ taux.absenceMedecin.nombre }})</dd></div>
                </dl>
            </div>
        </section>

        <section class="mt-6">
            <h2 class="text-lg font-semibold">Rendez-vous par statut</h2>
            <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
                <li v-for="s in parStatut" :key="s.statut" class="flex justify-between px-4 py-2">
                    <span>{{ s.libelle }}</span><span class="font-semibold">{{ s.nombre }}</span>
                </li>
            </ul>
        </section>

        <section class="mt-6">
            <h2 class="text-lg font-semibold">Médecins les plus sollicités</h2>
            <p v-if="topMedecins.length === 0" class="mt-2 text-slate-600">Aucune consultation sur la période.</p>
            <table v-else class="mt-2 w-full rounded-lg border border-slate-200 bg-white text-sm">
                <thead class="text-left text-slate-600">
                    <tr><th class="px-3 py-2 font-medium">Médecin</th><th class="px-3 py-2 text-right font-medium">Consultations</th><th class="px-3 py-2 text-right font-medium">Annulées par lui</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <tr v-for="(m, i) in topMedecins" :key="i">
                        <td class="px-3 py-2">{{ m.medecin }}</td>
                        <td class="px-3 py-2 text-right font-semibold">{{ m.consultations }}</td>
                        <td class="px-3 py-2 text-right" :class="m.annulations > 0 ? 'text-red-700' : ''">{{ m.annulations }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="mt-6 rounded-lg border border-dashed border-slate-300 p-4">
            <h2 class="font-semibold">Satisfaction</h2>
            <p class="mt-1 text-sm text-slate-600">{{ satisfaction }}</p>
        </section>
    </GabaritPersonnel>
</template>
