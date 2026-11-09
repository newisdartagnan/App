<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Gabarit from '../../Composants/Gabarit.vue';
import Pictogramme from '../../Composants/Ui/Pictogramme.vue';

defineProps({
    prenom: String,
    prochainRendezVous: { type: Object, default: null },
});

const tuiles = [
    { href: '/mes-paiements', titre: 'Paiements', texte: 'Reçus et factures', picto: 'carte', teinte: 'bg-vert-50 text-vert-700' },
    { href: '/mon-dossier', titre: 'Mon dossier', texte: 'Allergies, traitements, historique', picto: 'dossier', teinte: 'bg-marque-50 text-marque-700' },
    { href: '/mes-ordonnances', titre: 'Mes ordonnances', texte: 'À montrer en pharmacie', picto: 'ordonnance', teinte: 'bg-amber-50 text-amber-800' },
];
</script>

<template>
    <Head title="Mon espace" />
    <Gabarit>
        <section class="relative overflow-hidden rounded-2xl bg-marque-700 p-5 text-white shadow-sm sm:p-6">
            <span class="absolute -top-10 -right-10 h-40 w-40 rounded-full bg-vert-500/30" aria-hidden="true" />
            <span class="absolute -right-4 -bottom-16 h-32 w-32 rounded-full bg-white/10" aria-hidden="true" />
            <h1 class="relative text-2xl font-bold">Bonjour {{ prenom }}</h1>
            <p class="relative mt-1 max-w-md opacity-90">Un médecin, depuis votre téléphone. Décrivez votre besoin, choisissez un médecin et une heure.</p>
            <Link href="/consultation/demande"
                  class="relative mt-4 inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-white px-5 font-semibold text-marque-800 shadow-sm hover:bg-marque-50">
                <Pictogramme nom="plus" /> Demander une consultation
            </Link>
        </section>

        <h2 class="mt-6 mb-3 text-sm font-semibold tracking-wide text-slate-600 uppercase">En un coup d'œil</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <Link href="/mes-rendez-vous" class="carte group flex min-h-24 gap-4 p-4 hover:shadow-md sm:col-span-2">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-marque-50 text-marque-700">
                    <Pictogramme nom="calendrier" :taille="24" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-slate-600">Prochain rendez-vous</span>
                    <template v-if="prochainRendezVous">
                        <span class="block text-lg font-bold text-slate-900 first-letter:uppercase">{{ prochainRendezVous.jour }} à {{ prochainRendezVous.heure }}</span>
                        <span class="block text-sm text-slate-700">{{ prochainRendezVous.medecin }}</span>
                    </template>
                    <span v-else class="block font-semibold text-slate-800">Aucun rendez-vous prévu</span>
                </span>
                <span class="self-center text-slate-400" aria-hidden="true"><Pictogramme nom="fleche" /></span>
            </Link>
            <Link v-for="t in tuiles" :key="t.href" :href="t.href" class="carte group flex min-h-20 items-center gap-4 p-4 hover:shadow-md">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl" :class="t.teinte">
                    <Pictogramme :nom="t.picto" :taille="24" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold text-slate-900">{{ t.titre }}</span>
                    <span class="block text-sm text-slate-600">{{ t.texte }}</span>
                </span>
                <span class="text-slate-400" aria-hidden="true"><Pictogramme nom="fleche" /></span>
            </Link>
        </div>

        <h2 class="mt-6 mb-3 text-sm font-semibold tracking-wide text-slate-600 uppercase">Raccourcis</h2>
        <nav class="carte divide-y divide-slate-200" aria-label="Raccourcis">
            <Link href="/mes-rendez-vous" class="flex min-h-12 items-center gap-3 px-4 hover:bg-slate-50">
                <Pictogramme nom="calendrier" class="text-slate-500" /> <span class="flex-1">Mes rendez-vous</span> <Pictogramme nom="fleche" class="text-slate-400" :taille="16" />
            </Link>
            <Link href="/profil" class="flex min-h-12 items-center gap-3 px-4 hover:bg-slate-50">
                <Pictogramme nom="profil" class="text-slate-500" /> <span class="flex-1">Mon profil</span> <Pictogramme nom="fleche" class="text-slate-400" :taille="16" />
            </Link>
            <Link href="/consentement" class="flex min-h-12 items-center gap-3 px-4 hover:bg-slate-50">
                <Pictogramme nom="bouclier" class="text-slate-500" /> <span class="flex-1">Mes accords</span> <Pictogramme nom="fleche" class="text-slate-400" :taille="16" />
            </Link>
        </nav>

        <p class="mt-6 flex gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            <Pictogramme nom="alerte" class="mt-0.5" />
            <span><strong>Urgence vitale ?</strong> N'attendez pas une consultation en ligne : allez immédiatement à l'hôpital le plus proche.</span>
        </p>
    </Gabarit>
</template>
