<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Gabarit de l'espace du personnel : navigation du médecin ou de la caisse, déconnexion du personnel.
const props = defineProps({
    navigation: { type: Boolean, default: false },
    espace: { type: String, default: 'medecin' },
});

const page = usePage();
const utilisateur = computed(() => page.props.utilisateur);
const succes = computed(() => page.props.flash?.succes);
const deconnexionEnCours = ref(false);

const menus = {
    medecin: [
        { href: '/personnel/medecin', libelle: 'Accueil' },
        { href: '/personnel/medecin/agenda', libelle: 'Agenda' },
        { href: '/personnel/medecin/disponibilites', libelle: 'Disponibilités' },
        { href: '/personnel/medecin/absences', libelle: 'Absences' },
        { href: '/personnel/medecin/reversements', libelle: 'Reversements' },
    ],
    caisse: [
        { href: '/personnel/caisse', libelle: 'Accueil' },
        { href: '/personnel/caisse/taux', libelle: 'Taux du jour' },
        { href: '/personnel/caisse/remboursements', libelle: 'Remboursements' },
        { href: '/personnel/caisse/journal', libelle: 'Journal' },
        { href: '/personnel/caisse/reversements', libelle: 'Reversements' },
        { href: '/personnel/caisse/rapprochement', libelle: 'Rapprochement' },
        { href: '/personnel/caisse/factures', libelle: 'Factures' },
    ],
    admin: [
        { href: '/personnel/admin', libelle: 'Tableau de bord' },
        { href: '/personnel/admin/personnel', libelle: 'Personnel' },
        { href: '/personnel/admin/tarifs', libelle: 'Spécialités et tarifs' },
        { href: '/personnel/admin/partenaires', libelle: 'Partenaires' },
        { href: '/personnel/admin/journal', libelle: 'Journal' },
    ],
};
const liens = computed(() => menus[props.espace] ?? menus.medecin);
const accueil = computed(() => liens.value[0].href);

const actif = (href) => page.url.split('?')[0] === href || (href !== accueil.value && page.url.startsWith(href));

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
                <span class="flex items-center gap-2">
                    <img src="/images/embleme-64.png" width="32" height="32" alt="" class="h-8 w-8">
                    <span class="text-xl font-extrabold tracking-tight"><span class="text-marque-700">MEDI</span><span class="text-vert-600">HOME</span></span>
                    <span class="text-sm font-medium text-slate-500">· personnel</span>
                </span>
                <button v-if="utilisateur" type="button" class="text-sm text-slate-600 underline"
                        :disabled="deconnexionEnCours" @click="deconnecter">
                    Se déconnecter
                </button>
            </div>
            <nav v-if="props.navigation" class="mx-auto flex max-w-2xl gap-4 overflow-x-auto px-4 pb-2 text-sm" :aria-label="{ caisse: 'Espace caisse', admin: 'Administration' }[espace] ?? 'Espace médecin'">
                <Link v-for="lien in liens" :key="lien.href" :href="lien.href"
                      class="whitespace-nowrap py-1"
                      :class="actif(lien.href) ? 'border-b-2 border-marque-700 font-semibold text-marque-700' : 'text-slate-600'">
                    {{ lien.libelle }}
                </Link>
            </nav>
        </header>
        <main class="mx-auto max-w-2xl px-4 py-6">
            <p v-if="succes" class="mb-4 rounded-lg border border-vert-200 bg-vert-50 px-4 py-3 text-sm text-vert-900" role="status">
                {{ succes }}
            </p>
            <slot />
        </main>
    </div>
</template>
