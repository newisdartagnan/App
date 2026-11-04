<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const utilisateur = computed(() => page.props.utilisateur);
const succes = computed(() => page.props.flash?.succes);

const deconnecter = () => router.post('/deconnexion');
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-lg items-center justify-between px-4 py-3">
                <Link href="/" class="flex items-center gap-2" aria-label="MEDIHOME, accueil">
                    <img src="/images/embleme-64.png" width="32" height="32" alt="" class="h-8 w-8">
                    <span class="text-xl font-extrabold tracking-tight"><span class="text-marque-700">MEDI</span><span class="text-vert-600">HOME</span></span>
                </Link>
                <button v-if="utilisateur" type="button" class="text-sm text-slate-600 underline" @click="deconnecter">
                    Se déconnecter
                </button>
            </div>
        </header>
        <main class="mx-auto max-w-lg px-4 py-6">
            <p v-if="succes" class="mb-4 rounded-lg border border-vert-200 bg-vert-50 px-4 py-3 text-sm text-vert-900" role="status">
                {{ succes }}
            </p>
            <slot />
        </main>
    </div>
</template>
