<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ compte: Object, lienActivation: String, medecin: Object, specialites: Array });

const base = `/personnel/admin/personnel/${props.compte.id}`;
const lien = useForm({});
const ordre = useForm({});
const profil = useForm({ specialite: props.medecin?.specialite ?? '', presentation: props.medecin?.presentation ?? '' });
const desactivation = useForm({ motif: '', annuler_rendez_vous: false });
const reactivation = useForm({ motif_reactivation: '' });
const copie = ref(false);

const emettreLien = () => lien.post(`${base}/lien-activation`, { preserveScroll: true });
const verifierOrdre = () => ordre.post(`${base}/ordre`, { preserveScroll: true });
const enregistrerProfil = () => profil.put(`${base}/medecin`, { preserveScroll: true });
const desactiver = () => {
    if (desactivation.annuler_rendez_vous && !window.confirm(`Annuler les ${props.medecin.confirmesAVenir} rendez-vous confirmés, avec remboursement intégral ?`)) {
        return;
    }
    desactivation.post(`${base}/desactivation`, { preserveScroll: true, onSuccess: () => desactivation.reset() });
};
const reactiver = () => reactivation.post(`${base}/reactivation`, { preserveScroll: true, onSuccess: () => reactivation.reset() });
const copier = async () => {
    try {
        await navigator.clipboard.writeText(props.lienActivation);
        copie.value = true;
    } catch {
        copie.value = false;
    }
};
</script>

<template>
    <Head :title="compte.nom" />
    <GabaritPersonnel navigation espace="admin">
        <Link href="/personnel/admin/personnel" class="text-sm text-slate-600 underline">← Personnel</Link>
        <h1 class="mt-2 text-2xl font-bold">{{ compte.nom }}</h1>
        <p class="text-slate-700">{{ compte.roles.join(', ') }} · {{ compte.etatLibelle }}</p>
        <p class="text-sm text-slate-600">{{ compte.email }} · {{ compte.telephone }}</p>
        <p v-if="compte.laboratoire" class="text-sm text-slate-600">Laboratoire : {{ compte.laboratoire }}</p>
        <p class="text-sm text-slate-500">Dernière connexion : {{ compte.derniereConnexion ?? 'jamais' }}</p>

        <section v-if="lienActivation" class="mt-4 rounded-lg border border-vert-200 bg-vert-50 p-4 text-sm text-vert-900" role="status">
            <p class="font-semibold">Lien d'activation, affiché une seule fois</p>
            <p class="mt-1">Transmettez-le à la personne seule (valable 72 heures, un seul usage). Il ne sera plus affiché : en cas de perte, émettez-en un nouveau.</p>
            <p class="mt-2 break-all rounded bg-white px-3 py-2 font-mono text-xs">{{ lienActivation }}</p>
            <button type="button" class="bouton-secondaire mt-2 w-auto" @click="copier">{{ copie ? 'Copié' : 'Copier le lien' }}</button>
        </section>

        <section v-if="compte.etat === 'a_activer' && !lienActivation" class="mt-6">
            <h2 class="text-lg font-semibold">Activation</h2>
            <p class="text-sm text-slate-700">Ce compte n'est pas encore activé.</p>
            <button v-if="!compte.estAdministrateur" type="button" class="bouton-secondaire mt-2" :disabled="lien.processing" @click="emettreLien">Émettre un nouveau lien</button>
            <p v-else class="mt-1 text-sm text-slate-600">Compte administrateur ou d'audit : lien par la commande personnel:lien-activation.</p>
            <p v-if="lien.errors.lien" class="erreur">{{ lien.errors.lien }}</p>
        </section>

        <template v-if="medecin">
            <section class="mt-6 rounded-lg border border-slate-200 bg-white p-4">
                <h2 class="text-lg font-semibold">Médecin</h2>
                <p class="text-sm">Ordre n° {{ medecin.numeroOrdre }} —
                    <span v-if="medecin.ordreVerifieLe" class="text-vert-700">vérifié le {{ medecin.ordreVerifieLe }}</span>
                    <span v-else class="text-amber-800">non vérifié : pas proposé aux patients</span>
                </p>
                <button v-if="!medecin.ordreVerifieLe" type="button" class="bouton mt-2" :disabled="ordre.processing" @click="verifierOrdre">J'ai vérifié l'inscription à l'Ordre</button>
                <p v-if="ordre.errors.ordre" class="erreur">{{ ordre.errors.ordre }}</p>
                <p class="mt-2 text-sm" :class="medecin.reservable ? 'text-vert-700' : 'text-slate-600'">{{ medecin.reservable ? 'Proposé aux patients.' : 'Pas proposé aux patients.' }}</p>

                <form class="mt-4 space-y-3" @submit.prevent="enregistrerProfil">
                    <div>
                        <label for="specialite" class="etiquette">Spécialité</label>
                        <select id="specialite" v-model="profil.specialite" class="champ">
                            <option v-for="s in specialites" :key="s.slug" :value="s.slug">{{ s.nom }}</option>
                        </select>
                        <p v-if="profil.errors.specialite" class="erreur">{{ profil.errors.specialite }}</p>
                    </div>
                    <div>
                        <label for="presentation" class="etiquette">Présentation (vue par les patients)</label>
                        <textarea id="presentation" v-model="profil.presentation" rows="4" maxlength="2000" class="champ"></textarea>
                        <p v-if="profil.errors.presentation" class="erreur">{{ profil.errors.presentation }}</p>
                    </div>
                    <button type="submit" class="bouton-secondaire" :disabled="profil.processing">Enregistrer le profil</button>
                </form>
            </section>

            <section class="mt-6">
                <h2 class="text-lg font-semibold">Planning</h2>
                <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
                    <li v-for="p in medecin.plages" :key="p.id" class="flex justify-between px-4 py-2"><span>{{ p.jour }}</span><span>{{ p.heures }}</span></li>
                    <li v-if="medecin.plages.length === 0" class="px-4 py-2 text-slate-500">Aucune plage hebdomadaire.</li>
                </ul>
                <h3 class="mt-4 font-semibold">Absences à venir</h3>
                <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
                    <li v-for="a in medecin.absences" :key="a.id" class="px-4 py-2">{{ a.periode }}</li>
                    <li v-if="medecin.absences.length === 0" class="px-4 py-2 text-slate-500">Aucune.</li>
                </ul>
                <h3 class="mt-4 font-semibold">Rendez-vous à venir</h3>
                <ul class="mt-2 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
                    <li v-for="r in medecin.rendezVous" :key="r.statut" class="flex justify-between px-4 py-2"><span>{{ r.libelle }}</span><strong>{{ r.nombre }}</strong></li>
                    <li v-if="medecin.rendezVous.length === 0" class="px-4 py-2 text-slate-500">Aucun.</li>
                </ul>
            </section>
        </template>

        <section v-if="compte.estAdministrateur" class="mt-8 text-sm text-slate-600">
            <h2 class="text-lg font-semibold text-slate-900">Désactivation, réactivation</h2>
            <p class="mt-1">Compte administrateur ou d'audit : par les commandes personnel:desactiver et personnel:reactiver, sur le serveur.</p>
        </section>
        <section v-else class="mt-8">
            <h2 class="text-lg font-semibold">{{ compte.etat === 'desactive' ? 'Réactiver le compte' : 'Désactiver le compte' }}</h2>
            <template v-if="compte.etat === 'desactive'">
                <p class="text-sm text-slate-700">Désactivé {{ compte.desactiveLe }} : « {{ compte.motifDesactivation }} ».</p>
                <form class="mt-3 space-y-2" @submit.prevent="reactiver">
                    <label for="motif_reactivation" class="etiquette">Motif de la réactivation</label>
                    <input id="motif_reactivation" v-model="reactivation.motif_reactivation" maxlength="255" class="champ" required>
                    <p v-if="reactivation.errors.motif_reactivation" class="erreur">{{ reactivation.errors.motif_reactivation }}</p>
                    <button type="submit" class="bouton" :disabled="reactivation.processing">Réactiver</button>
                </form>
            </template>
            <p v-else-if="compte.estSoiMeme" class="text-sm text-slate-600">Vous ne pouvez pas désactiver votre propre compte.</p>
            <form v-else class="mt-3 space-y-2" @submit.prevent="desactiver">
                <p class="text-sm text-slate-700">Le compte ne pourra plus se connecter ; sa session en cours est fermée. Rien n'est supprimé.</p>
                <label for="motif" class="etiquette">Motif (obligatoire)</label>
                <input id="motif" v-model="desactivation.motif" maxlength="255" class="champ" required>
                <label v-if="medecin && medecin.confirmesAVenir > 0" class="flex items-start gap-2 text-sm">
                    <input v-model="desactivation.annuler_rendez_vous" type="checkbox" class="mt-1">
                    <span>Annuler ses {{ medecin.confirmesAVenir }} rendez-vous confirmés à venir, avec remboursement intégral des patients (prévenus par SMS).</span>
                </label>
                <p v-if="desactivation.errors.motif" class="erreur">{{ desactivation.errors.motif }}</p>
                <button type="submit" class="bouton bg-red-700 hover:bg-red-800" :disabled="desactivation.processing">Désactiver</button>
            </form>
        </section>
    </GabaritPersonnel>
</template>
