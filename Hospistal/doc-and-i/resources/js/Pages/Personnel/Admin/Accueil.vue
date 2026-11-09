<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import Badge from '../../../Composants/Ui/Badge.vue';
import Carte from '../../../Composants/Ui/Carte.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';
import EtatVide from '../../../Composants/Ui/EtatVide.vue';
import TuileChiffre from '../../../Composants/Ui/TuileChiffre.vue';

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
        <EnTeteDePage titre="Tableau de bord" pictogramme="graphique">
            <template #sousTitre>{{ periode.libelle }}, heure de Kinshasa.</template>
            <template #actions>
                <nav class="flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1" aria-label="Période">
                    <Link v-for="p in periodes" :key="p.cle" :href="`/personnel/admin?periode=${p.cle}`" preserve-scroll
                          class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium"
                          :class="p.cle === periode.cle ? 'bg-white text-marque-800 shadow-sm' : 'text-slate-700 hover:text-slate-900'"
                          :aria-current="p.cle === periode.cle ? 'page' : undefined">
                        {{ p.libelle }}
                    </Link>
                </nav>
            </template>
        </EnTeteDePage>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <TuileChiffre v-for="c in chiffreAffaires" :key="c.devise" :valeur="c.net" :libelle="`Chiffre d'affaires ${c.devise}`"
                          :detail="`Encaissé ${c.encaisse} · remboursé ${c.rembourse}`" pictogramme="portefeuille" couleur="vert" />
            <TuileChiffre v-if="chiffreAffaires.length === 0" valeur="0" libelle="Chiffre d'affaires" detail="Aucun mouvement de caisse" pictogramme="portefeuille" couleur="vert" />
            <TuileChiffre :valeur="taux.payes" libelle="Rendez-vous payés" pictogramme="calendrier" couleur="marque" />
            <TuileChiffre :valeur="delaiAttente.libelle ?? '—'" libelle="Délai d'attente moyen" :detail="`Sur ${delaiAttente.nombre} rendez-vous`" pictogramme="horloge" couleur="marque" />
            <TuileChiffre :valeur="taux.annulationMedecin.pourcentage ?? '—'" libelle="Annulés par le médecin" :detail="`${taux.annulationMedecin.nombre} rendez-vous`"
                          pictogramme="absence" :couleur="taux.annulationMedecin.nombre > 0 ? 'rouge' : 'ambre'" />
        </div>
        <p class="mt-2 text-xs text-slate-600">Chiffre d'affaires : encaissements moins remboursements, par devise (jamais additionnées).</p>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <Carte titre="Annulations et absences" :sous-titre="`Sur ${taux.payes} rendez-vous payés.`" pictogramme="alerte">
                <dl class="space-y-2 text-sm">
                    <div v-for="ligne in [
                        ['Annulés par le patient', taux.annulationPatient],
                        ['Annulés par le médecin', taux.annulationMedecin],
                        ['Absences du patient', taux.absencePatient],
                        ['Absences du médecin', taux.absenceMedecin],
                    ]" :key="ligne[0]" class="flex items-center justify-between gap-2">
                        <dt class="text-slate-700">{{ ligne[0] }}</dt>
                        <dd><Badge :ton="ligne[1].nombre > 0 ? 'attention' : 'neutre'">{{ ligne[1].pourcentage ?? '—' }} ({{ ligne[1].nombre }})</Badge></dd>
                    </div>
                </dl>
            </Carte>

            <Carte titre="Rendez-vous par statut" pictogramme="calendrier" plein>
                <ul class="divide-y divide-slate-200 text-sm">
                    <li v-for="s in parStatut" :key="s.statut" class="flex items-center justify-between px-5 py-2.5">
                        <span class="text-slate-700">{{ s.libelle }}</span><span class="font-semibold tabular-nums">{{ s.nombre }}</span>
                    </li>
                </ul>
            </Carte>
        </div>

        <Carte titre="Médecins les plus sollicités" pictogramme="stethoscope" class="mt-6" plein>
            <EtatVide v-if="topMedecins.length === 0" class="m-4" titre="Aucune consultation sur la période." pictogramme="stethoscope" />
            <div v-else class="overflow-x-auto">
                <table class="tableau">
                    <thead>
                        <tr><th>Médecin</th><th class="text-right">Consultations</th><th class="text-right">Annulées par lui</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(m, i) in topMedecins" :key="i">
                            <td>{{ m.medecin }}</td>
                            <td class="text-right font-semibold tabular-nums">{{ m.consultations }}</td>
                            <td class="text-right tabular-nums" :class="m.annulations > 0 ? 'text-red-700' : ''">{{ m.annulations }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Carte>

        <section class="mt-6 rounded-xl border border-dashed border-slate-300 p-4">
            <h2 class="titre-section">Satisfaction</h2>
            <p class="mt-1 text-sm text-slate-600">{{ satisfaction }}</p>
        </section>
    </GabaritPersonnel>
</template>
