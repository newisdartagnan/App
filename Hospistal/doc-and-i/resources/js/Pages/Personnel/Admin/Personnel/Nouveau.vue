<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GabaritPersonnel from '../../../../Composants/GabaritPersonnel.vue';

defineProps({ roles: Object, specialites: Array, laboratoires: Array });

const form = useForm({ role: '', email: '', telephone: '', nom: '', prenom: '', specialite: '', ordre: '', partenaire_id: '' });
const creer = () => form.post('/personnel/admin/personnel');
</script>

<template>
    <Head title="Créer un compte" />
    <GabaritPersonnel navigation espace="admin">
        <Link href="/personnel/admin/personnel" class="text-sm text-slate-600 underline">← Personnel</Link>
        <h1 class="mt-2 text-2xl font-bold">Créer un compte du personnel</h1>
        <p class="mt-2 text-slate-700">Aucun mot de passe n'est créé : la personne choisit le sien par le lien d'activation, puis relie son application d'authentification.</p>

        <form class="mt-6 space-y-4" @submit.prevent="creer">
            <div>
                <label for="role" class="etiquette">Rôle</label>
                <select id="role" v-model="form.role" class="champ" required>
                    <option value="" disabled>Choisir</option>
                    <option v-for="(libelle, valeur) in roles" :key="valeur" :value="valeur">{{ libelle }}</option>
                </select>
                <p v-if="form.errors.role" class="erreur">{{ form.errors.role }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="nom" class="etiquette">Nom</label>
                    <input id="nom" v-model="form.nom" class="champ" maxlength="100" required>
                    <p v-if="form.errors.nom" class="erreur">{{ form.errors.nom }}</p>
                </div>
                <div>
                    <label for="prenom" class="etiquette">Prénom</label>
                    <input id="prenom" v-model="form.prenom" class="champ" maxlength="100" required>
                    <p v-if="form.errors.prenom" class="erreur">{{ form.errors.prenom }}</p>
                </div>
            </div>
            <div>
                <label for="email" class="etiquette">Courriel professionnel</label>
                <input id="email" v-model="form.email" type="email" class="champ" required>
                <p v-if="form.errors.email" class="erreur">{{ form.errors.email }}</p>
            </div>
            <div>
                <label for="telephone" class="etiquette">Téléphone mobile</label>
                <input id="telephone" v-model="form.telephone" type="tel" inputmode="tel" placeholder="081 234 56 78" class="champ" required>
                <p v-if="form.errors.telephone" class="erreur">{{ form.errors.telephone }}</p>
            </div>

            <template v-if="form.role === 'medecin'">
                <div>
                    <label for="specialite" class="etiquette">Spécialité</label>
                    <select id="specialite" v-model="form.specialite" class="champ" required>
                        <option value="" disabled>Choisir</option>
                        <option v-for="s in specialites" :key="s.slug" :value="s.slug">{{ s.nom }}</option>
                    </select>
                    <p v-if="form.errors.specialite" class="erreur">{{ form.errors.specialite }}</p>
                </div>
                <div>
                    <label for="ordre" class="etiquette">Numéro d'inscription à l'Ordre</label>
                    <input id="ordre" v-model="form.ordre" class="champ" maxlength="50" required>
                    <p v-if="form.errors.ordre" class="erreur">{{ form.errors.ordre }}</p>
                    <p class="mt-1 text-sm text-slate-600">Le médecin n'est proposé aux patients qu'une fois l'Ordre vérifié.</p>
                </div>
            </template>

            <div v-if="form.role === 'laboratoire'">
                <label for="partenaire" class="etiquette">Laboratoire partenaire</label>
                <select id="partenaire" v-model="form.partenaire_id" class="champ" required>
                    <option value="" disabled>Choisir</option>
                    <option v-for="l in laboratoires" :key="l.id" :value="l.id">{{ l.nom }}<template v-if="l.commune"> ({{ l.commune }})</template></option>
                </select>
                <p v-if="laboratoires.length === 0" class="mt-1 text-sm text-amber-800">Aucun laboratoire partenaire actif : ajoutez-en un dans « Partenaires ».</p>
                <p v-if="form.errors.partenaire_id" class="erreur">{{ form.errors.partenaire_id }}</p>
            </div>

            <button type="submit" class="bouton" :disabled="form.processing">Créer le compte</button>
        </form>
    </GabaritPersonnel>
</template>
