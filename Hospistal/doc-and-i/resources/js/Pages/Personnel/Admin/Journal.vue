<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import GabaritPersonnel from '../../../Composants/GabaritPersonnel.vue';

const props = defineProps({ filtres: Object, actions: Array, auteurs: Array, entrees: Object });

const choix = reactive({ ...props.filtres });
const enCours = ref(false);
const filtrer = () => router.get('/personnel/admin/journal',
    Object.fromEntries(Object.entries(choix).filter(([, v]) => v)),
    { preserveScroll: true, onStart: () => { enCours.value = true; }, onFinish: () => { enCours.value = false; } });
</script>

<template>
    <Head title="Journal d'administration" />
    <GabaritPersonnel navigation espace="admin">
        <h1 class="text-2xl font-bold">Journal d'administration</h1>
        <p class="mt-2 text-slate-700">Qui a fait quoi, sur quel compte, quand. Lecture seule : rien ne s'y modifie ni ne s'y efface.</p>

        <form class="mt-4 grid grid-cols-2 gap-3" @submit.prevent="filtrer">
            <div>
                <label for="action" class="etiquette">Action</label>
                <select id="action" v-model="choix.action" class="champ">
                    <option :value="null">Toutes</option>
                    <option v-for="a in actions" :key="a.valeur" :value="a.valeur">{{ a.libelle }}</option>
                </select>
            </div>
            <div>
                <label for="auteur" class="etiquette">Auteur</label>
                <select id="auteur" v-model="choix.auteur" class="champ">
                    <option :value="null">Tous</option>
                    <option v-for="a in auteurs" :key="a.id" :value="a.id">{{ a.nom }}</option>
                </select>
            </div>
            <div><label for="du" class="etiquette">Du</label><input id="du" v-model="choix.du" type="date" class="champ"></div>
            <div><label for="au" class="etiquette">Au</label><input id="au" v-model="choix.au" type="date" class="champ"></div>
            <button type="submit" class="bouton-secondaire col-span-2" :disabled="enCours">Filtrer</button>
        </form>

        <ul class="mt-6 divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white text-sm">
            <li v-for="e in entrees.data" :key="e.id" class="px-4 py-3">
                <div class="flex justify-between gap-2"><strong>{{ e.action }}</strong><span class="text-slate-500">{{ e.quand }}</span></div>
                <p class="text-slate-700">Par {{ e.auteur }}<span v-if="e.cible"> — {{ e.cible }}</span></p>
                <p v-for="d in e.details" :key="d.cle" class="text-slate-600">{{ d.cle }} : {{ d.valeur }}</p>
            </li>
            <li v-if="entrees.data.length === 0" class="px-4 py-3 text-slate-500">Aucune entrée.</li>
        </ul>

        <nav v-if="entrees.last_page > 1" class="mt-4 flex flex-wrap gap-2 text-sm" aria-label="Pages">
            <template v-for="lien in entrees.links" :key="lien.label">
                <Link v-if="lien.url" :href="lien.url" class="rounded border px-3 py-1" :class="lien.active ? 'border-marque-700 bg-marque-700 text-white' : 'border-slate-300'" preserve-scroll>{{ lien.label.replace('&laquo;', '«').replace('&raquo;', '»') }}</Link>
            </template>
        </nav>
    </GabaritPersonnel>
</template>
