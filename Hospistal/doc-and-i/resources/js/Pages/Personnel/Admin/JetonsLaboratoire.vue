<script setup>
// PRE-06 : jetons d'API des laboratoires, affichés une seule fois, révocables.
import { Head, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ laboratoires: Array, jetons: Array, jetonClair: String });

const emission = useForm({ partenaire_id: props.laboratoires[0]?.id ?? '', libelle: '' });
const emettre = () => emission.post('/personnel/admin/jetons-laboratoire', { preserveScroll: true, onSuccess: () => emission.reset('libelle') });

const revocation = useForm({});
const revoquer = (j) => {
    if (window.confirm(`Révoquer le jeton « ${j.libelle} » ? Le logiciel du laboratoire ne pourra plus envoyer de résultats avec lui.`)) {
        revocation.post(`/personnel/admin/jetons-laboratoire/${j.id}/revocation`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Jetons des laboratoires" />
    <GabaritPersonnel navigation espace="admin">
        <h1 class="text-2xl font-bold">Jetons d'API des laboratoires</h1>
        <p class="mt-2 text-sm text-slate-700">Un jeton permet au logiciel d'un laboratoire d'envoyer ses résultats (voir la documentation de l'API). Il n'est affiché qu'une fois : transmettez-le au laboratoire par un canal sûr.</p>

        <div v-if="jetonClair" class="mt-4 rounded-lg border border-vert-200 bg-vert-50 p-4" role="status">
            <p class="font-semibold text-vert-900">Copiez ce jeton maintenant : il ne sera plus affiché.</p>
            <code class="mt-2 block break-all rounded bg-white p-2 text-sm">{{ jetonClair }}</code>
        </div>

        <form class="mt-4 space-y-3 rounded-lg bg-marque-50 p-4" @submit.prevent="emettre">
            <div>
                <label for="laboratoire" class="etiquette">Laboratoire</label>
                <select id="laboratoire" v-model="emission.partenaire_id" class="champ" required>
                    <option v-for="l in laboratoires" :key="l.id" :value="l.id">{{ l.nom }}</option>
                </select>
                <p v-if="emission.errors.partenaire_id" class="erreur">{{ emission.errors.partenaire_id }}</p>
            </div>
            <div>
                <label for="libelle" class="etiquette">Nom du jeton</label>
                <input id="libelle" v-model="emission.libelle" class="champ" maxlength="100" required placeholder="Logiciel du laboratoire">
                <p v-if="emission.errors.libelle" class="erreur">{{ emission.errors.libelle }}</p>
            </div>
            <button type="submit" class="bouton" :disabled="emission.processing || !laboratoires.length">Émettre un jeton</button>
        </form>

        <ul class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            <li v-for="j in jetons" :key="j.id" class="flex items-center justify-between gap-2 px-4 py-3" :class="{ 'opacity-60': j.revoque }">
                <span>
                    <span class="block font-medium">{{ j.libelle }} — {{ j.laboratoire }}</span>
                    <span class="block text-sm text-slate-600">Émis {{ j.creeLe }}<template v-if="j.dernierUsage"> · dernier envoi {{ j.dernierUsage }}</template></span>
                </span>
                <span v-if="j.revoque" class="text-sm text-slate-600">Révoqué</span>
                <button v-else type="button" class="text-sm text-red-700 underline" :disabled="revocation.processing" @click="revoquer(j)">Révoquer</button>
            </li>
            <li v-if="!jetons.length" class="px-4 py-3 text-slate-600">Aucun jeton.</li>
        </ul>
    </GabaritPersonnel>
</template>
