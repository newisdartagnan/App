<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../Composants/Ui/EnTeteDePage.vue';

const props = defineProps({ specialites: Array, durees: Object });

// Un formulaire par action : chaque bouton est désactivé pendant son propre envoi.
const etat = useForm({ active: true });
const changerEtat = (specialite) => {
    etat.active = !specialite.active;
    etat.post(`/personnel/admin/tarifs/specialites/${specialite.id}/etat`, { preserveScroll: true });
};

const vide = () => ({ nom: '', duree_minutes: 20, prix: '', devise: 'USD', actif: true });
const ajoutOuvert = ref(null);
const ajout = useForm(vide());
const ouvrirAjout = (specialite) => {
    ajout.defaults(vide());
    ajout.reset();
    ajout.clearErrors();
    ajoutOuvert.value = specialite.id;
};
const creer = (specialite) => ajout.post(`/personnel/admin/tarifs/specialites/${specialite.id}/types`, {
    preserveScroll: true,
    onSuccess: () => { ajoutOuvert.value = null; },
});

const edition = ref(null);
const modif = useForm(vide());
const ouvrirEdition = (type) => {
    modif.defaults({ nom: type.nom, duree_minutes: type.duree_minutes, prix: type.prix ?? '', devise: type.devise, actif: type.actif });
    modif.reset();
    modif.clearErrors();
    edition.value = type.id;
};
const enregistrer = (type) => modif.put(`/personnel/admin/tarifs/types/${type.id}`, {
    preserveScroll: true,
    onSuccess: () => { edition.value = null; },
});
</script>

<template>
    <Head title="Spécialités et tarifs" />
    <GabaritPersonnel navigation espace="admin">
        <EnTeteDePage titre="Spécialités et tarifs" pictogramme="etiquette" />
        <p class="mt-2 text-sm text-slate-700">
            Un type sans prix n'est pas proposé aux patients. Un nouveau prix vaut pour les prochaines réservations :
            les rendez-vous déjà réservés gardent le leur. Durée de {{ durees.min }} à {{ durees.max }} minutes, par pas de {{ durees.pas }}.
        </p>
        <p v-if="etat.errors.active" class="erreur">{{ etat.errors.active }}</p>

        <section v-for="s in specialites" :key="s.id" class="mt-6 carte p-4"
                 :class="s.active ? 'border-slate-200' : 'border-dashed border-slate-300 opacity-90'">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    {{ s.nom }}
                    <span v-if="!s.active" class="ml-1 rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700">Non proposée</span>
                </h2>
                <button type="button" class="text-sm font-medium underline" :class="s.active ? 'text-red-700' : 'text-vert-700'"
                        :disabled="etat.processing" @click="changerEtat(s)">
                    {{ s.active ? 'Ne plus proposer' : 'Proposer de nouveau' }}
                </button>
            </div>
            <p class="text-sm text-slate-600">{{ s.medecins }} médecin(s) réservable(s).</p>

            <ul class="mt-3 divide-y divide-slate-200">
                <li v-for="t in s.types" :key="t.id" class="py-2">
                    <div v-if="edition !== t.id" class="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            <span class="font-medium">{{ t.nom }}</span>
                            <span class="text-sm text-slate-600"> · {{ t.duree_minutes }} min · </span>
                            <span v-if="t.prix_affiche" class="font-semibold">{{ t.prix_affiche }}</span>
                            <span v-else class="text-sm text-amber-800">Sans prix : non proposé</span>
                            <span v-if="!t.actif" class="ml-1 rounded bg-slate-200 px-2 py-0.5 text-xs">Inactif</span>
                            <span v-if="t.rendez_vous_a_venir" class="block text-xs text-slate-500">
                                {{ t.rendez_vous_a_venir }} rendez-vous à venir : leur prix ne change pas.
                            </span>
                        </span>
                        <button type="button" class="text-sm text-marque-700 underline" @click="ouvrirEdition(t)">Modifier</button>
                    </div>

                    <form v-else class="space-y-3 rounded-lg bg-slate-50 p-3" @submit.prevent="enregistrer(t)">
                        <div>
                            <label :for="`nom-${t.id}`" class="etiquette">Nom</label>
                            <input :id="`nom-${t.id}`" v-model="modif.nom" class="champ" maxlength="150" required>
                            <p v-if="modif.errors.nom" class="erreur">{{ modif.errors.nom }}</p>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label :for="`duree-${t.id}`" class="etiquette">Durée (min)</label>
                                <input :id="`duree-${t.id}`" v-model.number="modif.duree_minutes" type="number" :min="durees.min" :max="durees.max" :step="durees.pas" class="champ" required>
                            </div>
                            <div>
                                <label :for="`prix-${t.id}`" class="etiquette">Prix</label>
                                <input :id="`prix-${t.id}`" v-model="modif.prix" inputmode="decimal" class="champ" placeholder="vide = non proposé">
                            </div>
                            <div>
                                <label :for="`devise-${t.id}`" class="etiquette">Devise</label>
                                <select :id="`devise-${t.id}`" v-model="modif.devise" class="champ"><option>USD</option><option>CDF</option></select>
                            </div>
                        </div>
                        <p v-if="modif.errors.duree_minutes" class="erreur">{{ modif.errors.duree_minutes }}</p>
                        <p v-if="modif.errors.prix" class="erreur">{{ modif.errors.prix }}</p>
                        <p v-if="modif.errors.devise" class="erreur">{{ modif.errors.devise }}</p>
                        <label class="flex items-center gap-2 text-sm"><input v-model="modif.actif" type="checkbox"> Proposé aux patients (actif)</label>
                        <div class="flex gap-2">
                            <button type="submit" class="bouton w-auto" :disabled="modif.processing">Enregistrer</button>
                            <button type="button" class="bouton-secondaire w-auto" :disabled="modif.processing" @click="edition = null">Annuler</button>
                        </div>
                    </form>
                </li>
                <li v-if="s.types.length === 0" class="py-2 text-sm text-slate-500">Aucun type de consultation.</li>
            </ul>

            <button v-if="ajoutOuvert !== s.id" type="button" class="mt-2 text-sm font-medium text-marque-700 underline" @click="ouvrirAjout(s)">
                Ajouter un type de consultation
            </button>
            <form v-else class="mt-3 space-y-3 rounded-lg bg-marque-50 p-3" @submit.prevent="creer(s)">
                <div>
                    <label :for="`nouveau-nom-${s.id}`" class="etiquette">Nom</label>
                    <input :id="`nouveau-nom-${s.id}`" v-model="ajout.nom" class="champ" maxlength="150" required placeholder="Consultation de suivi">
                    <p v-if="ajout.errors.nom" class="erreur">{{ ajout.errors.nom }}</p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label :for="`nouveau-duree-${s.id}`" class="etiquette">Durée (min)</label>
                        <input :id="`nouveau-duree-${s.id}`" v-model.number="ajout.duree_minutes" type="number" :min="durees.min" :max="durees.max" :step="durees.pas" class="champ" required>
                    </div>
                    <div>
                        <label :for="`nouveau-prix-${s.id}`" class="etiquette">Prix</label>
                        <input :id="`nouveau-prix-${s.id}`" v-model="ajout.prix" inputmode="decimal" class="champ" placeholder="vide = non proposé">
                    </div>
                    <div>
                        <label :for="`nouveau-devise-${s.id}`" class="etiquette">Devise</label>
                        <select :id="`nouveau-devise-${s.id}`" v-model="ajout.devise" class="champ"><option>USD</option><option>CDF</option></select>
                    </div>
                </div>
                <p v-if="ajout.errors.duree_minutes" class="erreur">{{ ajout.errors.duree_minutes }}</p>
                <p v-if="ajout.errors.prix" class="erreur">{{ ajout.errors.prix }}</p>
                <p v-if="ajout.errors.devise" class="erreur">{{ ajout.errors.devise }}</p>
                <div class="flex gap-2">
                    <button type="submit" class="bouton w-auto" :disabled="ajout.processing">Créer</button>
                    <button type="button" class="bouton-secondaire w-auto" :disabled="ajout.processing" @click="ajoutOuvert = null">Annuler</button>
                </div>
            </form>
        </section>
    </GabaritPersonnel>
</template>
