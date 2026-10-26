// Client WhatsApp Cloud API (Meta Graph API) + vérification de signature.

const GRAPH_VERSION = Deno.env.get("WA_GRAPH_VERSION") ?? "v21.0";
const PHONE_NUMBER_ID = Deno.env.get("WA_PHONE_NUMBER_ID") ?? "";
const WA_TOKEN = Deno.env.get("WA_ACCESS_TOKEN") ?? "";
const APP_SECRET = Deno.env.get("WA_APP_SECRET") ?? "";

export const hasWhatsApp = () => Boolean(PHONE_NUMBER_ID && WA_TOKEN);

async function send(payload: Record<string, unknown>): Promise<boolean> {
  if (!hasWhatsApp()) {
    console.warn("WhatsApp non configuré — message non envoyé:", payload);
    return false;
  }
  const res = await fetch(
    `https://graph.facebook.com/${GRAPH_VERSION}/${PHONE_NUMBER_ID}/messages`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${WA_TOKEN}`,
      },
      body: JSON.stringify({ messaging_product: "whatsapp", ...payload }),
    },
  );
  if (!res.ok) {
    console.error("WhatsApp send failed:", res.status, await res.text());
    return false;
  }
  return true;
}

/** Message texte libre — valable seulement dans la fenêtre de 24 h après un message de l'invité. */
export function sendText(to: string, body: string): Promise<boolean> {
  return send({ to, type: "text", text: { preview_url: true, body } });
}

/**
 * Message « template » — le SEUL format autorisé pour initier une conversation
 * (donc pour envoyer l'invitation). Le template doit être approuvé par Meta.
 * Variables attendues : {{1}} = nom de l'invité, {{2}} = lien personnalisé.
 */
export function sendTemplate(
  to: string,
  templateName: string,
  languageCode: string,
  variables: string[],
): Promise<boolean> {
  return send({
    to,
    type: "template",
    template: {
      name: templateName,
      language: { code: languageCode },
      components: [
        {
          type: "body",
          parameters: variables.map((v) => ({ type: "text", text: v })),
        },
      ],
    },
  });
}

/** Comparaison à temps constant (évite une fuite par timing). */
function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

/**
 * Vérifie l'en-tête X-Hub-Signature-256 de Meta sur le corps BRUT de la requête.
 * Renvoie false si le secret n'est pas configuré : on refuse plutôt que d'accepter.
 */
export async function verifySignature(rawBody: string, header: string | null): Promise<boolean> {
  if (!APP_SECRET || !header?.startsWith("sha256=")) return false;

  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(APP_SECRET),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  const mac = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(rawBody));
  const expected = Array.from(new Uint8Array(mac))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");

  return timingSafeEqual(expected, header.slice("sha256=".length).toLowerCase());
}
