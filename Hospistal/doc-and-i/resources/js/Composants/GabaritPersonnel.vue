<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Gabarit de l'espace du personnel : navigation du médecin, déconnexion du personnel.
const props = defineProps({ navigation: { type: Boolean, default: false } });

const page = usePage();
const utilisateur = computed(() => page.props.utilisateur);
const succes = computed(() => page.props.flash?.succes);
const deconnexionEnCours = ref(false);

const liens = [
    { href: '/personnel/medecin', libelle: 'Accueil' },
    { href: '/personnel/medecin/agenda', libelle: 'Agenda' },
    { href: '/personnel/medecin/disponibilites', libelle: 'Disponibilités' },
    { href: '/personnel/medecin/absences', libelle: 'Absences' },
];

const actif = (href) => page.url === href || (href !== '/personnel/medecin' && page.url.startsWith(href));

const deconnecter = () => {
    router.post('/personnel/deconnexion', {}, {
        onStart: () => { deconnexionEnCours.value = true; },
        onFinish: () => { deconnexionEnCours.value = false; },
    });
};
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-2xl items-center justify-between px-4 py-3">
                <span class="text-xl font-bold text-teal-800">Doc&amp;I <span class="text-sm font-medium text-slate-500">· personnel</span></span>
                <button v-if="utilisateur" type="button" class="text-sm text-slate-600 underline"
                        :disabled="deconnexionEnCours" @click="deconnecter">
                    Se déconnecter
                </button>
            </div>
            <nav v-if="props.navigation" class="mx-auto flex max-w-2xl gap-4 overflow-x-auto px-4 pb-2 text-sm" aria-label="Espace médecin">
                <Link v-for="lien in liens" :key="lien.href" :href="lien.href"
                      class="whitespace-nowrap py-1"
                      :class="actif(lien.href) ? 'border-b-2 border-teal-700 font-semibold text-teal-800' : 'text-slate-600'">
                    {{ lien.libelle }}
                </Link>
            </nav>
        </header>
        <main class="mx-auto max-w-2xl px-4 py-6">
            <p v-if="succes" class="mb-4 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900" role="status">
                {{ succes }}
            </p>
            <slot />
        </main>
    </div>
</template>
