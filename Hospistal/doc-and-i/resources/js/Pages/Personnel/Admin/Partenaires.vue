<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ filtre: String, types: Array, partenaires: Array });

const libelles = { laboratoire: 'Laboratoire', pharmacie: 'Pharmacie' };
const vide = () => ({ type: props.filtre ?? 'laboratoire', nom: '', commune: '', adresse: '', telephone: '', email: '' });

const ajoutOuvert = ref(false);
const ajout = useForm(vide());
const creer = () => ajout.post('/personnel/admin/partenaires', {
    preserveScroll: true,
    onSuccess: () => { ajout.reset(); ajoutOuvert.value = false; },
});

const edition = ref(null);
const modif = useForm(vide());
const ouvrirEdition = (p) => {
    modif.defaults({ type: p.type, nom: p.nom, commune: p.commune ?? '', adresse: p.adresse ?? '', telephone: p.telephone ?? '', email: p.email ?? '' });
    modif.reset();
    modif.clearErrors();
    edition.value = p.id;
};
const enregistrer = (p) => modif.put(`/personnel/admin/partenaires/${p.id}`, { preserveScroll: true, onSuccess: () => { edition.value = null; } });

const etat = useForm({ actif: true });
const changerEtat = (p) => {
    etat.actif = !p.actif;
    etat.post(`/personnel/admin/partenaires/${p.id}/etat`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Partenaires" />
    <GabaritPersonnel navigation espace="admin">
        <h1 class="text-2xl font-bold">Laboratoires et pharmacies</h1>
        <p class="mt-2 text-sm text-slate-700">Un partenaire ne se supprime pas : désactivé, il n'est plus proposé et son historique reste.</p>

        <nav class="mt-4 flex gap-2" aria-label="Filtrer par type">
            <Link href="/personnel/admin/partenaires" class="rounded-full border px-3 py-1 text-sm"
                  :class="!filtre ? 'border-marque-700 bg-marque-700 text-white' : 'border-slate-300 bg-white'">Tous</Link>
            <Link v-for="t in types" :key="t" :href="`/personnel/admin/partenaires?type=${t}`" class="rounded-full border px-3 py-1 text-sm"
                  :class="filtre === t ? 'border-marque-700 bg-marque-700 text-white' : 'border-slate-300 bg-white'">{{ libelles[t] }}s</Link>
        </nav>

        <button v-if="!ajoutOuvert" type="button" class="bouton mt-4" @click="ajoutOuvert = true">Ajouter un partenaire</button>
        <form v-else class="mt-4 space-y-3 rounded-lg bg-marque-50 p-4" @submit.prevent="creer">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="type" class="etiquette">Type</label>
                    <select id="type" v-model="ajout.type" class="champ">
                        <option v-for="t in types" :key="t" :value="t">{{ libelles[t] }}</option>
                    </select>
                    <p v-if="ajout.errors.type" class="erreur">{{ ajout.errors.type }}</p>
                </div>
                <div>
                    <label for="commune" class="etiquette">Commune</label>
                    <input id="commune" v-model="ajout.commune" class="champ" maxlength="100" placeholder="Gombe">
                </div>
            </div>
            <div>
                <label for="nom" class="etiquette">Nom</label>
                <input id="nom" v-model="ajout.nom" class="champ" maxlength="150" required>
                <p v-if="ajout.errors.nom" class="erreur">{{ ajout.errors.nom }}</p>
            </div>
            <div>
                <label for="adresse" class="etiquette">Adresse</label>
                <input id="adresse" v-model="ajout.adresse" class="champ" maxlength="255">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="telephone" class="etiquette">Téléphone</label>
                    <input id="telephone" v-model="ajout.telephone" type="tel" inputmode="tel" class="champ" placeholder="081 234 56 78">
                    <p v-if="ajout.errors.telephone" class="erreur">{{ ajout.errors.telephone }}</p>
                </div>
                <div>
                    <label for="email" class="etiquette">Courriel</label>
                    <input id="email" v-model="ajout.email" type="email" class="champ">
                    <p v-if="ajout.errors.email" class="erreur">{{ ajout.errors.email }}</p>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bouton w-auto" :disabled="ajout.processing">Ajouter</button>
                <button type="button" class="bouton-secondaire w-auto" :disabled="ajout.processing" @click="ajoutOuvert = false">Annuler</button>
            </div>
        </form>

        <p v-if="etat.errors.actif" class="erreur mt-2">{{ etat.errors.actif }}</p>
        <p v-if="partenaires.length === 0" class="mt-6 text-slate-600">Aucun partenaire.</p>
        <ul class="mt-6 space-y-3">
            <li v-for="p in partenaires" :key="p.id" class="rounded-lg border bg-white p-4" :class="p.actif ? 'border-slate-200' : 'border-dashed border-slate-300'">
                <div v-if="edition !== p.id">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <span>
                            <span class="font-semibold">{{ p.nom }}</span>
                            <span class="ml-1 text-sm text-slate-600">{{ libelles[p.type] }}</span>
                            <span v-if="!p.actif" class="ml-1 rounded bg-slate-200 px-2 py-0.5 text-xs">Désactivé</span>
                        </span>
                        <span class="flex gap-3 text-sm">
                            <button type="button" class="text-marque-700 underline" @click="ouvrirEdition(p)">Modifier</button>
                            <button type="button" class="underline" :class="p.actif ? 'text-red-700' : 'text-vert-700'" :disabled="etat.processing" @click="changerEtat(p)">
                                {{ p.actif ? 'Désactiver' : 'Réactiver' }}
                            </button>
                        </span>
                    </div>
                    <p class="text-sm text-slate-600">{{ [p.commune, p.adresse].filter(Boolean).join(' · ') }}</p>
                    <p class="text-sm text-slate-600">{{ [p.telephone, p.email].filter(Boolean).join(' · ') }}</p>
                </div>
                <form v-else class="space-y-3" @submit.prevent="enregistrer(p)">
                    <div class="grid grid-cols-2 gap-2">
                        <select v-model="modif.type" class="champ" aria-label="Type">
                            <option v-for="t in types" :key="t" :value="t">{{ libelles[t] }}</option>
                        </select>
                        <input v-model="modif.commune" class="champ" aria-label="Commune" placeholder="Commune">
                    </div>
                    <input v-model="modif.nom" class="champ" aria-label="Nom" required>
                    <p v-if="modif.errors.nom" class="erreur">{{ modif.errors.nom }}</p>
                    <input v-model="modif.adresse" class="champ" aria-label="Adresse" placeholder="Adresse">
                    <div class="grid grid-cols-2 gap-2">
                        <input v-model="modif.telephone" type="tel" class="champ" aria-label="Téléphone" placeholder="Téléphone">
                        <input v-model="modif.email" type="email" class="champ" aria-label="Courriel" placeholder="Courriel">
                    </div>
                    <p v-if="modif.errors.telephone" class="erreur">{{ modif.errors.telephone }}</p>
                    <p v-if="modif.errors.email" class="erreur">{{ modif.errors.email }}</p>
                    <div class="flex gap-2">
                        <button type="submit" class="bouton w-auto" :disabled="modif.processing">Enregistrer</button>
                        <button type="button" class="bouton-secondaire w-auto" :disabled="modif.processing" @click="edition = null">Annuler</button>
                    </div>
                </form>
            </li>
        </ul>
    </GabaritPersonnel>
</template>
