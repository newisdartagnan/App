<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import GabaritPersonnel from '../../../../Composants/GabaritPersonnel.vue';
import EnTeteDePage from '../../../../Composants/Ui/EnTeteDePage.vue';

const props = defineProps({ filtres: Object, roles: Object, etats: Object, personnel: Object });

const choix = reactive({ ...props.filtres });
const enCours = ref(false);
const filtrer = () => router.get('/personnel/admin/personnel',
    Object.fromEntries(Object.entries(choix).filter(([, v]) => v)),
    { preserveScroll: true, onStart: () => { enCours.value = true; }, onFinish: () => { enCours.value = false; } });
</script>

<template>
    <Head title="Personnel" />
    <GabaritPersonnel navigation espace="admin">
        <div class="flex items-center justify-between gap-3">
            <EnTeteDePage titre="Personnel" pictogramme="utilisateurs" />
            <Link href="/personnel/admin/personnel/nouveau" class="bouton w-auto">Créer un compte</Link>
        </div>

        <form class="mt-4 grid grid-cols-2 gap-3" @submit.prevent="filtrer">
            <div>
                <label for="role" class="etiquette">Rôle</label>
                <select id="role" v-model="choix.role" class="champ">
                    <option :value="null">Tous</option>
                    <option v-for="(libelle, valeur) in roles" :key="valeur" :value="valeur">{{ libelle }}</option>
                </select>
            </div>
            <div>
                <label for="etat" class="etiquette">État</label>
                <select id="etat" v-model="choix.etat" class="champ">
                    <option :value="null">Tous</option>
                    <option v-for="(libelle, valeur) in etats" :key="valeur" :value="valeur">{{ libelle }}</option>
                </select>
            </div>
            <button type="submit" class="bouton-secondaire col-span-2" :disabled="enCours">Filtrer</button>
        </form>

        <ul class="mt-6 divide-y divide-slate-200 carte text-sm">
            <li v-for="p in personnel.data" :key="p.id">
                <Link :href="`/personnel/admin/personnel/${p.id}`" class="block px-4 py-3 hover:bg-marque-50">
                    <div class="flex justify-between gap-2">
                        <strong>{{ p.nom }}</strong>
                        <span :class="{ 'text-vert-700': p.etat === 'Actif', 'text-amber-800': p.etat === 'À activer', 'text-red-700': p.etat === 'Désactivé' }">{{ p.etat }}</span>
                    </div>
                    <p class="text-slate-600">{{ p.roles.join(', ') }} · {{ p.email }}</p>
                    <p class="text-slate-500">Dernière connexion : {{ p.derniereConnexion ?? 'jamais' }}</p>
                </Link>
            </li>
            <li v-if="personnel.data.length === 0" class="px-4 py-3 text-slate-500">Aucun compte.</li>
        </ul>

        <nav v-if="personnel.last_page > 1" class="mt-4 flex flex-wrap gap-2 text-sm" aria-label="Pages">
            <template v-for="lien in personnel.links" :key="lien.label">
                <Link v-if="lien.url" :href="lien.url" class="rounded border px-3 py-1" :class="lien.active ? 'border-marque-700 bg-marque-700 text-white' : 'border-slate-300'" preserve-scroll>{{ lien.label.replace('&laquo;', '«').replace('&raquo;', '»') }}</Link>
            </template>
        </nav>
    </GabaritPersonnel>
</template>
