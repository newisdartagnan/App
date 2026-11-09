<script setup>
// Gabarit de l'espace du personnel (médecin, caisse, administration).
// Bandeau aux couleurs de la marque avec le nom et le rôle de la personne
// connectée ; menu par groupes : latéral sur ordinateur, rangée défilante
// sur téléphone. Les menus ci-dessous restent la source des liens.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Pictogramme from './Ui/Pictogramme.vue';
import SelecteurTheme from './Ui/SelecteurTheme.vue';

const props = defineProps({
    navigation: { type: Boolean, default: false },
    espace: { type: String, default: 'medecin' },
    // Écrans larges (salle de consultation) : toute la largeur utile.
    large: { type: Boolean, default: false },
});

const page = usePage();
const utilisateur = computed(() => page.props.utilisateur);
const succes = computed(() => page.props.flash?.succes);
const deconnexionEnCours = ref(false);

const menus = {
    medecin: [
        { groupe: 'Consultations', liens: [
            { href: '/personnel/medecin', libelle: 'Accueil', picto: 'accueil' },
            { href: '/personnel/medecin/agenda', libelle: 'Agenda', picto: 'calendrier' },
        ] },
        { groupe: 'Organisation', liens: [
            { href: '/personnel/medecin/disponibilites', libelle: 'Disponibilités', picto: 'horloge' },
            { href: '/personnel/medecin/absences', libelle: 'Absences', picto: 'absence' },
        ] },
        { groupe: 'Finances', liens: [
            { href: '/personnel/medecin/reversements', libelle: 'Reversements', picto: 'portefeuille' },
        ] },
    ],
    caisse: [
        { groupe: 'Caisse', liens: [
            { href: '/personnel/caisse', libelle: 'Accueil', picto: 'accueil' },
            { href: '/personnel/caisse/taux', libelle: 'Taux du jour', picto: 'taux' },
        ] },
        { groupe: 'Mouvements', liens: [
            { href: '/personnel/caisse/remboursements', libelle: 'Remboursements', picto: 'remboursement' },
            { href: '/personnel/caisse/journal', libelle: 'Journal', picto: 'journal' },
            { href: '/personnel/caisse/reversements', libelle: 'Reversements', picto: 'portefeuille' },
        ] },
        { groupe: 'Contrôle', liens: [
            { href: '/personnel/caisse/rapprochement', libelle: 'Rapprochement', picto: 'balance' },
            { href: '/personnel/caisse/factures', libelle: 'Factures', picto: 'facture' },
        ] },
    ],
    admin: [
        { groupe: 'Pilotage', liens: [
            { href: '/personnel/admin', libelle: 'Tableau de bord', picto: 'graphique' },
            { href: '/personnel/admin/journal', libelle: 'Journal', picto: 'journal' },
        ] },
        { groupe: 'Référentiels', liens: [
            { href: '/personnel/admin/personnel', libelle: 'Personnel', picto: 'utilisateurs' },
            { href: '/personnel/admin/tarifs', libelle: 'Spécialités et tarifs', picto: 'etiquette' },
            { href: '/personnel/admin/partenaires', libelle: 'Partenaires', picto: 'batiment' },
        ] },
    ],
};
const groupes = computed(() => menus[props.espace] ?? menus.medecin);
const liens = computed(() => groupes.value.flatMap((g) => g.liens));
const accueil = computed(() => liens.value[0].href);
const nomEspace = computed(() => ({ caisse: 'Espace caisse', admin: 'Administration' }[props.espace] ?? 'Espace médecin'));

const chemin = computed(() => page.url.split('?')[0]);
const actif = (href) => chemin.value === href || (href !== accueil.value && chemin.value.startsWith(href));

const deconnecter = () => {
    router.post('/personnel/deconnexion', {}, {
        onStart: () => { deconnexionEnCours.value = true; },
        onFinish: () => { deconnexionEnCours.value = false; },
    });
};
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">
            Aller au contenu
        </a>
        <header class="bandeau">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-2 px-4 py-2">
                <span class="flex min-h-11 shrink-0 items-center gap-2">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/95 p-0.5 shadow-sm">
                        <img src="/images/embleme-64.png" width="32" height="32" alt="" class="h-8 w-8">
                    </span>
                    <span class="text-xl font-extrabold tracking-tight"><span class="marque-medi">MEDI</span><span class="marque-home">HOME</span></span>
                    <span v-if="navigation" class="bandeau-doux hidden truncate border-l border-white/30 pl-2 text-sm font-medium md:inline">{{ nomEspace }}</span>
                    <span v-else class="bandeau-doux hidden text-sm font-medium sm:inline">· personnel</span>
                </span>
                <div class="flex min-w-0 items-center gap-1">
                    <SelecteurTheme />
                    <template v-if="utilisateur">
                        <span class="max-w-[7.5rem] min-w-0 px-1 text-right leading-tight sm:max-w-none sm:px-2">
                            <span class="block truncate text-sm font-semibold">{{ utilisateur.prenom }} {{ utilisateur.nom }}</span>
                            <span v-if="utilisateur.role" class="bandeau-doux block truncate text-xs">{{ utilisateur.role }}</span>
                        </span>
                        <button type="button" class="bandeau-bouton" :disabled="deconnexionEnCours" @click="deconnecter">
                            <Pictogramme nom="deconnexion" />
                            <span class="hidden lg:inline">Se déconnecter</span>
                            <span class="sr-only lg:hidden">Se déconnecter</span>
                        </button>
                    </template>
                </div>
            </div>
        </header>

        <!-- Téléphone et tablette : rangée défilante, les groupes séparés d'un trait. -->
        <nav v-if="navigation" class="border-b border-slate-200 bg-white lg:hidden" :aria-label="nomEspace">
            <ul class="flex gap-1 overflow-x-auto px-3 py-2">
                <template v-for="(g, i) in groupes" :key="g.groupe">
                    <li v-if="i > 0" class="my-2 w-px shrink-0 bg-slate-200" aria-hidden="true" />
                    <li v-for="lien in g.liens" :key="lien.href" class="shrink-0">
                        <Link :href="lien.href" class="flex min-h-11 items-center gap-2 rounded-full px-3 text-sm whitespace-nowrap"
                              :class="actif(lien.href) ? 'bg-marque-700 font-semibold text-white' : 'text-slate-700 hover:bg-slate-100'"
                              :aria-current="actif(lien.href) ? 'page' : undefined">
                            <Pictogramme :nom="lien.picto" :taille="18" /> {{ lien.libelle }}
                        </Link>
                    </li>
                </template>
            </ul>
        </nav>

        <div class="mx-auto w-full flex-1 px-4 py-6" :class="navigation ? 'max-w-7xl lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-8' : 'max-w-2xl'">
            <!-- Ordinateur : menu latéral par groupes. -->
            <nav v-if="navigation" class="hidden lg:block" :aria-label="nomEspace">
                <div class="carte sticky top-4 p-3">
                    <div v-for="g in groupes" :key="g.groupe" class="mb-3 last:mb-0">
                        <p class="px-3 pt-1 pb-1.5 text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ g.groupe }}</p>
                        <ul class="space-y-0.5">
                            <li v-for="lien in g.liens" :key="lien.href">
                                <Link :href="lien.href" class="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm"
                                      :class="actif(lien.href) ? 'bg-marque-50 font-semibold text-marque-800 shadow-[inset_3px_0_0_var(--color-marque-600)]' : 'text-slate-700 hover:bg-slate-100'"
                                      :aria-current="actif(lien.href) ? 'page' : undefined">
                                    <Pictogramme :nom="lien.picto" /> {{ lien.libelle }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
            <main id="contenu" class="min-w-0" :class="navigation && !large ? 'lg:max-w-4xl' : ''">
                <p v-if="succes" class="mb-4 flex items-start gap-2 rounded-xl border border-vert-200 bg-vert-50 px-4 py-3 text-sm text-vert-900" role="status">
                    <Pictogramme nom="coche" class="mt-0.5" /> {{ succes }}
                </p>
                <slot />
            </main>
        </div>
    </div>
</template>
