<script setup>
// DOS-03 : historique chronologique, du plus récent au plus ancien. Le serveur
// n'y met que des notes signées (jamais un brouillon).
defineProps({ evenements: { type: Array, required: true } });

const pastille = (type) => ({
    consultation: 'bg-marque-50 text-marque-900',
    note: 'bg-marque-50 text-marque-900',
    ordonnance: 'bg-vert-50 text-vert-900',
    paiement: 'bg-slate-100 text-slate-700',
    antecedent_retire: 'bg-amber-50 text-amber-900',
}[type] ?? 'bg-slate-100 text-slate-700');

const nature = (type) => ({
    consultation: 'Consultation',
    note: 'Note',
    ordonnance: 'Ordonnance',
    paiement: 'Paiement',
    antecedent_ajoute: 'Ajout',
    antecedent_retire: 'Retrait',
}[type] ?? '');
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <h2 class="text-lg font-semibold">Historique</h2>
        <ol v-if="evenements.length" class="mt-2 space-y-3">
            <li v-for="(e, i) in evenements" :id="e.type === 'note' ? `note-${e.id}` : undefined" :key="`${e.type}-${e.id}-${i}`"
                class="border-l-2 border-slate-200 pl-3">
                <p class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <span class="rounded-full px-2 py-0.5 font-medium" :class="pastille(e.type)">{{ nature(e.type) }}</span>
                    <span class="first-letter:uppercase">{{ e.date }}</span>
                </p>
                <p class="mt-1 font-medium">{{ e.titre }}</p>
                <ul v-if="e.details.length" class="mt-1 space-y-0.5 text-sm text-slate-700">
                    <li v-for="(d, j) in e.details" :key="j" class="whitespace-pre-wrap">{{ d }}</li>
                </ul>
                <div v-for="(a, j) in e.avenants ?? []" :key="`av${j}`" class="mt-2 rounded bg-amber-50 p-2 text-sm text-amber-900">
                    <p class="font-medium">Avenant du {{ a.date }} ({{ a.auteur }})</p>
                    <p class="whitespace-pre-wrap">{{ a.contenu }}</p>
                </div>
            </li>
        </ol>
        <p v-else class="mt-1 text-slate-600">Rien pour l'instant.</p>
    </section>
</template>
