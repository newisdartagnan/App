<script setup>
// Vitrine publique : logo et devise, trois étapes, services, appel à l'action.
// Une seule image (le logo, déjà servie) ; le reste en CSS et SVG intégré.
import { Head, Link, usePage } from '@inertiajs/vue3';
import Gabarit from '../Composants/Gabarit.vue';
import Pictogramme from '../Composants/Ui/Pictogramme.vue';

const connecte = !!usePage().props.utilisateur;

const etapes = [
    { picto: 'message', titre: 'Décrivez votre besoin', texte: 'Quelques questions simples, puis choisissez un médecin et un créneau.' },
    { picto: 'telephone', titre: 'Payez d\'avance', texte: 'M-Pesa, Orange Money, Airtel Money ou carte. Reçu immédiat.' },
    { picto: 'video', titre: 'Consultez', texte: 'En vidéo ou en audio. Ordonnance et compte rendu dans votre dossier.' },
];
const services = [
    { picto: 'stethoscope', titre: 'Médecine privée', texte: 'Généralistes et spécialistes vérifiés auprès de l\'Ordre, en téléconsultation.' },
    { picto: 'maison', titre: 'Médecine à domicile', texte: 'Quand il faut examiner, le soin se rapproche de chez vous.' },
    { picto: 'batiment', titre: 'Médecine d\'entreprise', texte: 'Suivi des équipes et visites, sans déplacer vos collaborateurs.' },
];
</script>

<template>
    <Head title="La médecine vient à vous" />
    <Gabarit large>
        <!-- Hero -->
        <section class="relative -mx-4 -mt-6 overflow-hidden bg-gradient-to-b from-marque-50 to-transparent px-4 pt-8 pb-10 sm:mx-0 sm:rounded-3xl sm:px-10">
            <span class="absolute -top-24 -right-24 h-72 w-72 rounded-full bg-vert-100 opacity-70" aria-hidden="true" />
            <span class="absolute top-40 -left-20 h-56 w-56 rounded-full bg-marque-100 opacity-70" aria-hidden="true" />
            <div class="relative grid items-center gap-8 md:grid-cols-[1.1fr_1fr]">
                <div class="text-center md:text-left">
                    <p class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-vert-800 shadow-sm ring-1 ring-vert-200">
                        <Pictogramme nom="bouclier" :taille="14" /> Médecins vérifiés · Kinshasa
                    </p>
                    <h1 class="mt-4 text-3xl leading-tight font-extrabold text-slate-900 sm:text-4xl">
                        Consultez un médecin <span class="text-marque-700">sans vous déplacer</span>
                    </h1>
                    <p class="mt-3 text-lg text-slate-700">
                        <span class="font-bold"><span class="text-marque-700">MEDI</span><span class="text-vert-700">HOME</span></span>,
                        la médecine vient à vous. Réservez, payez par Mobile Money et parlez à un médecin depuis votre téléphone.
                    </p>
                    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-center md:justify-start">
                        <Link :href="connecte ? '/espace' : '/connexion'" class="bouton sm:w-auto sm:px-8">
                            {{ connecte ? 'Mon espace' : 'Commencer' }} <Pictogramme nom="fleche" />
                        </Link>
                        <a href="#etapes" class="bouton-secondaire sm:w-auto sm:px-6">Comment ça marche</a>
                    </div>
                </div>
                <div class="mx-auto w-56 sm:w-72 md:w-full md:max-w-sm">
                    <div class="carte p-6">
                        <img src="/images/medihome-logo.webp" width="560" height="473"
                             alt="MEDIHOME — La médecine vient à vous." class="mx-auto w-full">
                    </div>
                </div>
            </div>
        </section>

        <!-- Trois étapes -->
        <section id="etapes" class="mt-10 scroll-mt-4">
            <h2 class="text-center text-2xl font-bold text-slate-900">Trois étapes, depuis votre téléphone</h2>
            <ol class="mt-6 grid gap-4 md:grid-cols-3">
                <li v-for="(e, i) in etapes" :key="e.titre" class="carte relative p-5">
                    <span class="absolute top-4 right-4 text-4xl font-extrabold text-slate-100" aria-hidden="true">{{ i + 1 }}</span>
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-marque-700 text-white shadow-sm">
                        <Pictogramme :nom="e.picto" :taille="24" />
                    </span>
                    <h3 class="mt-4 font-semibold text-slate-900"><span class="sr-only">Étape {{ i + 1 }} : </span>{{ e.titre }}</h3>
                    <p class="mt-1 text-sm text-slate-600">{{ e.texte }}</p>
                </li>
            </ol>
        </section>

        <!-- Services -->
        <section class="mt-10">
            <h2 class="text-center text-2xl font-bold text-slate-900">Nos services</h2>
            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <article v-for="s in services" :key="s.titre" class="carte flex gap-4 p-5 md:flex-col">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-vert-50 text-vert-700 ring-1 ring-vert-200">
                        <Pictogramme :nom="s.picto" :taille="24" />
                    </span>
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ s.titre }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ s.texte }}</p>
                    </div>
                </article>
            </div>
        </section>

        <!-- Appel à l'action -->
        <section class="panneau-marque mt-10 overflow-hidden rounded-2xl p-6 text-center shadow-sm sm:p-10">
            <h2 class="text-2xl font-bold">Un médecin, aujourd'hui</h2>
            <p class="mx-auto mt-2 max-w-xl opacity-90">Pas de mot de passe à retenir : votre numéro de téléphone et un code par SMS suffisent.</p>
            <Link :href="connecte ? '/espace' : '/connexion'"
                  class="panneau-bouton mt-5 px-8">
                {{ connecte ? 'Mon espace' : 'Commencer' }} <Pictogramme nom="fleche" />
            </Link>
        </section>

        <p class="mt-8 flex gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            <Pictogramme nom="alerte" class="mt-0.5" />
            <span>
                <strong>Urgence vitale ?</strong> Difficulté à respirer, douleur forte dans la poitrine, perte de connaissance, saignement important :
                n'attendez pas une consultation en ligne, rendez-vous immédiatement à l'hôpital le plus proche.
            </span>
        </p>
    </Gabarit>
</template>
