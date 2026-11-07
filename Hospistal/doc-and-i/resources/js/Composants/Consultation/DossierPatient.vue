<script setup>
// TLC-05 : dossier du patient pendant l'appel, côté médecin seulement.
import { onMounted, ref } from 'vue';
import { messageErreur, requete } from './requete';

const props = defineProps({
    rendezVousId: { type: String, required: true },
    prefixe: { type: String, default: '/personnel/medecin/consultation' },
});

const base = `${props.prefixe}/${props.rendezVousId}`;
const dossier = ref(null);
const erreur = ref(null);
const chargement = ref(true);

const taille = (octets) => (octets >= 1024 * 1024 ? `${(octets / 1024 / 1024).toFixed(1)} Mo` : `${Math.ceil(octets / 1024)} Ko`);

onMounted(async () => {
    const { ok, donnees } = await requete(`${base}/dossier`);
    chargement.value = false;
    if (ok) {
        dossier.value = donnees;
    } else {
        erreur.value = messageErreur(donnees);
    }
});
</script>

<template>
    <section class="space-y-4 text-sm" aria-label="Dossier du patient">
        <p v-if="chargement" class="text-slate-500">Chargement du dossier…</p>
        <p v-else-if="erreur" class="erreur" role="alert">{{ erreur }}</p>

        <template v-else-if="dossier">
            <!-- Allergies d'abord, bien visibles (DOS-01). -->
            <div v-if="dossier.antecedents" class="rounded-lg border-2 p-3" role="note"
                 :class="dossier.antecedents.allergies.length ? 'border-red-600 bg-red-50 text-red-900' : 'border-slate-200 bg-white text-slate-700'">
                <h3 class="text-base font-bold">Allergies</h3>
                <ul v-if="dossier.antecedents.allergies.length" class="ml-4 list-disc font-semibold">
                    <li v-for="(a, i) in dossier.antecedents.allergies" :key="`a${i}`">{{ a }}</li>
                </ul>
                <p v-else>Aucune allergie déclarée (ce qui ne veut pas dire aucune : demandez au patient).</p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <h3 class="text-base font-semibold">{{ dossier.patient.prenom }} {{ dossier.patient.nom }}</h3>
                <p class="text-slate-700">
                    <span v-if="dossier.patient.age !== null">{{ dossier.patient.age }} ans</span>
                    <span v-if="dossier.patient.sexe"> · {{ dossier.patient.sexe }}</span>
                    <span v-if="dossier.patient.commune"> · {{ dossier.patient.commune }}</span>
                </p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <h3 class="font-semibold">Motif</h3>
                <p class="whitespace-pre-wrap">{{ dossier.demande.motif }}</p>
                <template v-if="dossier.demande.symptomes">
                    <h4 class="mt-2 font-semibold">Symptômes</h4>
                    <p class="whitespace-pre-wrap">{{ dossier.demande.symptomes }}</p>
                </template>
                <p v-if="dossier.demande.depuis" class="mt-2 text-slate-700">{{ dossier.demande.depuis }}</p>

                <h4 class="mt-3 font-semibold">Questionnaire de tri</h4>
                <p v-if="dossier.demande.alerte" class="font-medium text-red-700">Signe d'alerte déclaré.</p>
                <ul class="mt-1 space-y-1">
                    <li v-for="(r, i) in dossier.demande.tri" :key="i" class="flex justify-between gap-2">
                        <span class="text-slate-700">{{ r.question }}</span>
                        <strong :class="r.reponse === 'oui' ? 'text-red-700' : 'text-slate-600'">{{ r.reponse }}</strong>
                    </li>
                </ul>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <h3 class="font-semibold">Pièces jointes</h3>
                <ul v-if="dossier.piecesJointes.length" class="mt-1 space-y-1">
                    <li v-for="p in dossier.piecesJointes" :key="p.id">
                        <a :href="`${base}/pieces-jointes/${p.id}`" class="text-marque-700 underline">{{ p.nom }}</a>
                        <span class="text-slate-500"> ({{ taille(p.taille) }})</span>
                    </li>
                </ul>
                <p v-else class="text-slate-500">Aucune.</p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-3">
                <h3 class="font-semibold">Consultations précédentes avec vous</h3>
                <ul v-if="dossier.historique.length" class="mt-1 space-y-1">
                    <li v-for="h in dossier.historique" :key="h.id">
                        <span class="first-letter:uppercase">{{ h.date }}</span> · {{ h.statut }}
                        <span v-if="h.motif" class="block text-slate-600">{{ h.motif }}</span>
                    </li>
                </ul>
                <p v-else class="text-slate-500">Première consultation avec ce patient.</p>
            </div>

            <div v-if="dossier.antecedents" class="rounded-lg border border-slate-200 bg-white p-3">
                <h3 class="font-semibold">Dossier médical</h3>
                <p class="mt-1">
                    Groupe sanguin :
                    <strong v-if="dossier.antecedents.groupeSanguin">{{ dossier.antecedents.groupeSanguin }}</strong>
                    <span v-else class="text-slate-500">inconnu</span>
                </p>

                <h4 class="mt-3 font-semibold">Traitements en cours</h4>
                <ul v-if="dossier.antecedents.traitements.length" class="ml-4 list-disc">
                    <li v-for="(t, i) in dossier.antecedents.traitements" :key="`t${i}`">{{ t }}</li>
                </ul>
                <p v-else class="text-slate-500">Aucun déclaré.</p>

                <template v-for="(libelle, cle) in { medical: 'Antécédents médicaux', chirurgical: 'Antécédents chirurgicaux', familial: 'Antécédents familiaux' }" :key="cle">
                    <h4 class="mt-3 font-semibold">{{ libelle }}</h4>
                    <ul v-if="dossier.antecedents.antecedents[cle].length" class="ml-4 list-disc">
                        <li v-for="(a, i) in dossier.antecedents.antecedents[cle]" :key="`${cle}${i}`">{{ a }}</li>
                    </ul>
                    <p v-else class="text-slate-500">Aucun déclaré.</p>
                </template>

                <h4 class="mt-3 font-semibold">Dernières constantes</h4>
                <ul v-if="dossier.antecedents.constantes.length" class="mt-1 space-y-1">
                    <li v-for="c in dossier.antecedents.constantes" :key="c.type" class="flex justify-between gap-2">
                        <span>{{ c.libelle }}</span><span><strong>{{ c.valeur }}</strong> <span class="text-slate-500">· {{ c.quand }}</span></span>
                    </li>
                </ul>
                <p v-else class="text-slate-500">Aucune mesure.</p>
            </div>
        </template>
    </section>
</template>
