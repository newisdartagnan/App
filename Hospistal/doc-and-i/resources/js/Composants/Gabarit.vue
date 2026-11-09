<script setup>
// Gabarit du patient et des pages publiques. Bandeau aux couleurs de la
// marque (logo, nom et rôle, thème, déconnexion) ; pour un patient connecté,
// barre de navigation en bas sur téléphone et rangée d'onglets sur ordinateur.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Pictogramme from './Ui/Pictogramme.vue';
import SelecteurTheme from './Ui/SelecteurTheme.vue';

const props = defineProps({
    // Pages larges (accueil public) : plus de place sur ordinateur.
    large: { type: Boolean, default: false },
});

const page = usePage();
const utilisateur = computed(() => page.props.utilisateur);
const succes = computed(() => page.props.flash?.succes);
const estPatient = computed(() => utilisateur.value?.espace === 'patient');
const deconnexionEnCours = ref(false);

// Navigation du patient : les cinq rubriques de son espace.
const liens = [
    { href: '/espace', libelle: 'Accueil', picto: 'accueil' },
    { href: '/mes-rendez-vous', libelle: 'Rendez-vous', picto: 'calendrier' },
    { href: '/mon-dossier', libelle: 'Dossier', picto: 'dossier' },
    { href: '/mes-paiements', libelle: 'Paiements', picto: 'carte' },
    { href: '/profil', libelle: 'Profil', picto: 'profil' },
];
const chemin = computed(() => page.url.split('?')[0]);
const actif = (href) => chemin.value === href || chemin.value.startsWith(`${href}/`)
    || (href === '/mon-dossier' && (chemin.value.startsWith('/mes-ordonnances') || chemin.value.startsWith('/mes-examens')));

const deconnecter = () => router.post('/deconnexion', {}, {
    onStart: () => { deconnexionEnCours.value = true; },
    onFinish: () => { deconnexionEnCours.value = false; },
});
const largeur = computed(() => (props.large ? 'max-w-5xl' : 'max-w-3xl'));
</script>

<template>
    <div class="flex min-h-screen flex-col">
        <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">
            Aller au contenu
        </a>
        <header class="bandeau">
            <div class="mx-auto flex items-center justify-between gap-2 px-4 py-2" :class="large ? 'max-w-5xl' : 'max-w-3xl'">
                <Link :href="estPatient ? '/espace' : '/'" class="flex min-h-11 items-center gap-2" aria-label="MEDIHOME, accueil">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white/95 p-0.5 shadow-sm">
                        <img src="/images/embleme-64.png" width="32" height="32" alt="" class="h-8 w-8">
                    </span>
                    <span class="text-xl font-extrabold tracking-tight"><span class="marque-medi">MEDI</span><span class="marque-home">HOME</span></span>
                </Link>
                <div class="flex items-center gap-1">
                    <SelecteurTheme />
                    <template v-if="utilisateur">
                        <span class="hidden px-2 text-right leading-tight sm:block">
                            <span class="block text-sm font-semibold">{{ utilisateur.prenom }} {{ utilisateur.nom }}</span>
                            <span v-if="utilisateur.role" class="bandeau-doux block text-xs">{{ utilisateur.role }}</span>
                        </span>
                        <button type="button" class="bandeau-bouton" :disabled="deconnexionEnCours" @click="deconnecter">
                            <Pictogramme nom="deconnexion" />
                            <span class="hidden sm:inline">Se déconnecter</span>
                            <span class="sr-only sm:hidden">Se déconnecter</span>
                        </button>
                    </template>
                    <Link v-else href="/connexion" class="bandeau-bouton bg-white/10">
                        <Pictogramme nom="profil" /> <span>Se connecter</span>
                    </Link>
                </div>
            </div>
            <!-- Ordinateur : onglets de l'espace patient sous le bandeau. -->
            <nav v-if="estPatient" class="mx-auto hidden max-w-3xl gap-1 px-4 sm:flex" aria-label="Espace patient">
                <Link v-for="lien in liens" :key="lien.href" :href="lien.href"
                      class="flex min-h-11 items-center gap-2 rounded-t-lg px-3 text-sm font-medium"
                      :class="actif(lien.href) ? 'bg-[var(--fond-page)] text-marque-700' : 'bandeau-bouton rounded-b-none'"
                      :aria-current="actif(lien.href) ? 'page' : undefined">
                    <Pictogramme :nom="lien.picto" :taille="18" /> {{ lien.libelle }}
                </Link>
            </nav>
        </header>

        <!-- Téléphone : nom et rôle restent visibles, sous le bandeau. -->
        <p v-if="utilisateur" class="mx-auto w-full max-w-3xl px-4 pt-3 text-xs text-slate-600 sm:hidden">
            Connecté : <strong class="text-slate-800">{{ utilisateur.prenom }} {{ utilisateur.nom }}</strong><template v-if="utilisateur.role"> · {{ utilisateur.role }}</template>
        </p>

        <main id="contenu" class="mx-auto w-full flex-1 px-4 py-6" :class="[largeur, estPatient ? 'pb-28 sm:pb-8' : '']">
            <p v-if="succes" class="mb-4 flex items-start gap-2 rounded-xl border border-vert-200 bg-vert-50 px-4 py-3 text-sm text-vert-900" role="status">
                <Pictogramme nom="coche" class="mt-0.5" /> {{ succes }}
            </p>
            <slot />
        </main>

        <footer v-if="!estPatient" class="mt-auto border-t border-slate-200 py-6 text-center text-xs text-slate-600">
            <span class="font-bold"><span class="text-marque-700">MEDI</span><span class="text-vert-700">HOME</span></span> · La médecine vient à vous.
        </footer>

        <!-- Téléphone : barre de navigation du bas, cibles de 56 px. -->
        <nav v-if="estPatient" class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] sm:hidden"
             aria-label="Espace patient">
            <ul class="mx-auto flex max-w-lg">
                <li v-for="lien in liens" :key="lien.href" class="flex-1">
                    <Link :href="lien.href" class="flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] font-medium"
                          :class="actif(lien.href) ? 'text-marque-700' : 'text-slate-600'"
                          :aria-current="actif(lien.href) ? 'page' : undefined">
                        <span class="flex h-7 w-12 items-center justify-center rounded-full" :class="actif(lien.href) ? 'bg-marque-50' : ''">
                            <Pictogramme :nom="lien.picto" :taille="20" />
                        </span>
                        {{ lien.libelle }}
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
