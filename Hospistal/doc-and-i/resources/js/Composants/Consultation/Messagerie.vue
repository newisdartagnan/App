<script setup>
// TLC-03 : messagerie de la consultation, côté patient ou médecin.
// prefixe : « /consultation » (patient) ou « /personnel/medecin/consultation » (médecin).
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { messageErreur, requete } from './requete';

const props = defineProps({
    rendezVousId: { type: String, required: true },
    prefixe: { type: String, required: true },
    intervalleMs: { type: Number, default: 4000 },
});

const base = `${props.prefixe}/${props.rendezVousId}/messages`;
const messages = ref([]);
const texte = ref('');
const erreur = ref(null);
const envoi = ref(false);
const fil = ref(null);
const champPhoto = ref(null);
let minuterie = null;

const dernierId = () => (messages.value.length ? messages.value[messages.value.length - 1].id : null);

async function charger() {
    const apres = dernierId();
    const { ok, donnees } = await requete(apres ? `${base}?apres=${encodeURIComponent(apres)}` : base);
    if (!ok) {
        erreur.value = messageErreur(donnees);
        return;
    }
    if (donnees.messages?.length) {
        const connus = new Set(messages.value.map((m) => m.id));
        messages.value.push(...donnees.messages.filter((m) => !connus.has(m.id)));
        await nextTick();
        fil.value?.scrollTo({ top: fil.value.scrollHeight });
    }
}

async function envoyer(corps) {
    envoi.value = true;
    erreur.value = null;
    const { ok, donnees } = await requete(base, { methode: 'POST', corps });
    envoi.value = false;
    if (!ok) {
        erreur.value = messageErreur(donnees);
        return false;
    }
    await charger();
    return true;
}

async function envoyerTexte() {
    if (!texte.value.trim()) {
        return;
    }
    if (await envoyer({ texte: texte.value })) {
        texte.value = '';
    }
}

async function envoyerPhoto(evenement) {
    const fichier = evenement.target.files?.[0];
    if (!fichier) {
        return;
    }
    const donnees = new FormData();
    donnees.append('photo', fichier);
    await envoyer(donnees);
    champPhoto.value.value = '';
}

onMounted(() => {
    charger();
    minuterie = window.setInterval(charger, props.intervalleMs);
});
onBeforeUnmount(() => window.clearInterval(minuterie));
</script>

<template>
    <section class="flex h-full flex-col rounded-lg border border-slate-200 bg-white" aria-label="Messages de la consultation">
        <ol ref="fil" class="flex-1 space-y-2 overflow-y-auto p-3 text-sm" aria-live="polite">
            <li v-for="m in messages" :key="m.id" class="flex" :class="m.moi ? 'justify-end' : 'justify-start'">
                <div class="max-w-[80%] rounded-lg px-3 py-2" :class="m.moi ? 'bg-marque-700 text-white' : 'bg-slate-100 text-slate-900'">
                    <p v-if="m.texte" class="whitespace-pre-wrap break-words">{{ m.texte }}</p>
                    <a v-if="m.photo" :href="`${base}/${m.id}/photo`" target="_blank" rel="noopener">
                        <img :src="`${base}/${m.id}/photo`" alt="Photo envoyée pendant la consultation" class="max-h-48 rounded" loading="lazy">
                    </a>
                    <p class="mt-1 text-right text-xs" :class="m.moi ? 'text-marque-100' : 'text-slate-500'">{{ m.heure }}</p>
                </div>
            </li>
            <li v-if="messages.length === 0" class="text-center text-slate-500">Aucun message pour le moment.</li>
        </ol>

        <p v-if="erreur" class="erreur px-3" role="alert">{{ erreur }}</p>

        <form class="flex items-end gap-2 border-t border-slate-200 p-2" @submit.prevent="envoyerTexte">
            <label for="message-consultation" class="sr-only">Votre message</label>
            <textarea id="message-consultation" v-model="texte" rows="2" maxlength="2000" class="champ mt-0 flex-1"
                      placeholder="Écrire un message…" @keydown.enter.exact.prevent="envoyerTexte"></textarea>
            <label class="bouton-secondaire w-auto cursor-pointer px-3" :class="{ 'pointer-events-none opacity-60': envoi }" title="Envoyer une photo">
                Photo
                <input ref="champPhoto" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="envoi" @change="envoyerPhoto">
            </label>
            <button type="submit" class="bouton w-auto px-4" :disabled="envoi || !texte.trim()">Envoyer</button>
        </form>
    </section>
</template>
