// ============================================================
// Edge Function : whatsapp-send
//   POST { tokens: string[] }  ou  { all: true }   -> envoi des invitations
//   En-tête requis : x-admin-secret: <ADMIN_SECRET>
//
// Envoie le lien d'invitation personnalisé via un template WhatsApp approuvé.
//
// Conformité (non négociable, imposé par Meta) :
//   - Initier une conversation exige un TEMPLATE approuvé ; le texte libre
//     n'est possible que dans les 24 h suivant un message de l'invité.
//   - N'envoie qu'aux invités ayant donné leur consentement (wa_opt_in).
// ============================================================

import {
  getInvitationByToken,
  listSendableInvitations,
  logWaMessage,
  type Invitation,
} from "../_shared/db.ts";
import { hasWhatsApp, sendTemplate } from "../_shared/whatsapp.ts";
import { corsHeaders, json } from "../_shared/cors.ts";

const ADMIN_SECRET = Deno.env.get("ADMIN_SECRET") ?? "";
const APP_URL = Deno.env.get("APP_URL") ?? "";
const TEMPLATE_NAME = Deno.env.get("WA_TEMPLATE_NAME") ?? "invitation_equinoxe";
const TEMPLATE_LANG = Deno.env.get("WA_TEMPLATE_LANG") ?? "fr";

interface SendResult {
  token: string;
  sent: boolean;
  reason?: string;
}

async function sendOne(inv: Invitation): Promise<SendResult> {
  if (!inv.phone) return { token: inv.token, sent: false, reason: "numéro manquant" };
  if (!inv.wa_opt_in) return { token: inv.token, sent: false, reason: "consentement absent" };

  const name = [inv.honorific, inv.guest_name].filter(Boolean).join(" ");
  const link = `${APP_URL}/i/${encodeURIComponent(inv.token)}`;

  const ok = await sendTemplate(inv.phone, TEMPLATE_NAME, TEMPLATE_LANG, [name, link]);
  if (ok) {
    await logWaMessage({
      invitation_id: inv.id,
      direction: "out",
      phone: inv.phone,
      body: `[template ${TEMPLATE_NAME}] ${link}`,
    });
  }
  return { token: inv.token, sent: ok, reason: ok ? undefined : "échec de l'envoi" };
}

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (req.method !== "POST") return json({ error: "Méthode non autorisée." }, 405);

  if (!ADMIN_SECRET || req.headers.get("x-admin-secret") !== ADMIN_SECRET) {
    return json({ error: "Non autorisé." }, 401);
  }
  if (!hasWhatsApp()) return json({ error: "WhatsApp non configuré." }, 503);
  if (!APP_URL) return json({ error: "APP_URL non configurée." }, 503);

  let payload: { tokens?: string[]; all?: boolean };
  try {
    payload = await req.json();
  } catch {
    return json({ error: "Corps de requête invalide." }, 400);
  }

  let targets: Invitation[] = [];

  if (payload.all) {
    targets = await listSendableInvitations();
  } else if (Array.isArray(payload.tokens) && payload.tokens.length > 0) {
    const found = await Promise.all(
      payload.tokens.slice(0, 200).map((t) => getInvitationByToken(t)),
    );
    targets = found.filter((x): x is Invitation => x !== null);
  } else {
    return json({ error: "Fournir `tokens` ou `all: true`." }, 400);
  }

  const results: SendResult[] = [];
  for (const inv of targets) {
    results.push(await sendOne(inv));
  }

  return json({
    total: results.length,
    sent: results.filter((r) => r.sent).length,
    results,
  });
});
