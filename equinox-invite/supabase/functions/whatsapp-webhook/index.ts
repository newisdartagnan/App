// ============================================================
// Edge Function : whatsapp-webhook
//   GET  -> vérification du webhook par Meta (hub.challenge)
//   POST -> messages entrants : RSVP par mot-clé, sinon réponse de l'assistant
//
// Déployer avec --no-verify-jwt : Meta appelle sans JWT Supabase.
// L'authenticité est garantie par la signature HMAC X-Hub-Signature-256.
// ============================================================

import { ask } from "../_shared/assistant.ts";
import { getInvitationByPhone, logWaMessage, setRsvpByToken, type Invitation } from "../_shared/db.ts";
import { sendText, verifySignature } from "../_shared/whatsapp.ts";

const VERIFY_TOKEN = Deno.env.get("WA_VERIFY_TOKEN") ?? "";
const APP_URL = Deno.env.get("APP_URL") ?? "";

/** Meta envoie le numéro sans « + » : on le remet pour coller au format E.164 stocké. */
function normalizePhone(from: string): string {
  const digits = from.replace(/\D/g, "");
  return `+${digits}`;
}

type Rsvp = "yes" | "no" | "maybe";

/** Détecte une intention RSVP explicite. Renvoie null si le message est autre chose. */
function detectRsvp(text: string): Rsvp | null {
  const t = text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim();

  if (/^(oui|yes|ok|d'accord|daccord|je viens|present|presente|confirme)\b/.test(t)) return "yes";
  if (/^(non|no|nope|je ne viens pas|absent|absente|decline)\b/.test(t)) return "no";
  if (/^(peut ?etre|maybe|je ne sais pas|incertain)\b/.test(t)) return "maybe";
  return null;
}

function rsvpReply(status: Rsvp, inv: Invitation): string {
  const name = [inv.honorific, inv.guest_name].filter(Boolean).join(" ");
  if (status === "yes") {
    return `Merci ${name}, votre présence est confirmée ! Rendez-vous le ${inv.event_date} ${inv.event_year} à ${inv.event_time}, ${inv.event_venue}. Pensez à garder votre QR code, il sert d'entrée.`;
  }
  if (status === "no") {
    return `C'est noté ${name}, merci de nous avoir prévenus. Vous nous manquerez !`;
  }
  return `Merci ${name}, j'ai noté « peut-être ». Vous pourrez confirmer plus tard en répondant simplement OUI ou NON.`;
}

async function handleMessage(from: string, text: string, waId: string): Promise<void> {
  const phone = normalizePhone(from);

  // Idempotence : Meta rejoue les webhooks non acquittés.
  const isNew = await logWaMessage({
    wa_message_id: waId,
    direction: "in",
    phone,
    body: text,
  });
  if (!isNew) return;

  const inv = await getInvitationByPhone(phone);

  if (!inv) {
    await sendText(
      phone,
      "Bonjour ! Je ne retrouve pas d'invitation associée à ce numéro. Contactez les organisateurs pour toute question.",
    );
    return;
  }

  let reply: string;
  const rsvp = detectRsvp(text);

  if (rsvp) {
    const updated = await setRsvpByToken(inv.token, rsvp);
    reply = rsvpReply(rsvp, updated ?? inv);
  } else {
    reply = await ask(text, inv);
    // Rappel du lien personnalisé quand l'invité cherche son invitation.
    if (APP_URL && /invitation|lien|qr|billet|carton/i.test(text)) {
      reply += `\n\nVotre invitation : ${APP_URL}/i/${encodeURIComponent(inv.token)}`;
    }
  }

  const sent = await sendText(phone, reply);
  if (sent) {
    await logWaMessage({
      invitation_id: inv.id,
      direction: "out",
      phone,
      body: reply,
    });
  }
}

Deno.serve(async (req: Request) => {
  const url = new URL(req.url);

  // --- Vérification initiale du webhook par Meta ---
  if (req.method === "GET") {
    const mode = url.searchParams.get("hub.mode");
    const token = url.searchParams.get("hub.verify_token");
    const challenge = url.searchParams.get("hub.challenge") ?? "";

    if (mode === "subscribe" && VERIFY_TOKEN && token === VERIFY_TOKEN) {
      return new Response(challenge, { status: 200 });
    }
    return new Response("Forbidden", { status: 403 });
  }

  if (req.method !== "POST") return new Response("Method not allowed", { status: 405 });

  // --- Authenticité : HMAC sur le corps brut, avant tout parsing ---
  const raw = await req.text();
  if (!(await verifySignature(raw, req.headers.get("x-hub-signature-256")))) {
    return new Response("Invalid signature", { status: 401 });
  }

  try {
    const body = JSON.parse(raw);
    for (const entry of body.entry ?? []) {
      for (const change of entry.changes ?? []) {
        for (const msg of change.value?.messages ?? []) {
          if (msg.type !== "text") {
            await sendText(
              normalizePhone(msg.from),
              "Je ne traite que les messages texte pour le moment. Posez-moi votre question par écrit !",
            );
            continue;
          }
          await handleMessage(msg.from, msg.text?.body ?? "", msg.id);
        }
      }
    }
  } catch (err) {
    // On journalise mais on renvoie 200 : sinon Meta rejoue indéfiniment.
    console.error("whatsapp-webhook:", err);
  }

  return new Response("EVENT_RECEIVED", { status: 200 });
});

// Réexport pour les tests unitaires éventuels.
export { detectRsvp, normalizePhone };
