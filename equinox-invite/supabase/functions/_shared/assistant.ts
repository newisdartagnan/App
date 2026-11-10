// Cerveau de l'assistant, partagé par la page web et le bot WhatsApp.
import Anthropic from "npm:@anthropic-ai/sdk@^0.120.0";
import type { Invitation } from "./db.ts";

const ANTHROPIC_API_KEY = Deno.env.get("ANTHROPIC_API_KEY") ?? "";

export interface Turn {
  role: "user" | "assistant";
  content: string;
}

/** Contexte factuel injecté dans le prompt : l'assistant ne doit rien inventer. */
function eventContext(inv: Invitation | null): string {
  if (!inv) {
    return "Aucune invitation n'est associée à cette conversation. Reste général et propose de contacter les organisateurs.";
  }
  const rsvp = {
    pending: "pas encore répondu",
    yes: "a confirmé sa présence",
    no: "a décliné",
    maybe: "a répondu « peut-être »",
  }[inv.rsvp_status];

  return [
    `Invité : ${[inv.honorific, inv.guest_name].filter(Boolean).join(" ")}`,
    `Événement : ${inv.event_title}`,
    `Date : ${inv.event_date} ${inv.event_year}`,
    `Heure : ${inv.event_time}${inv.event_time_note ? ` (${inv.event_time_note})` : ""}`,
    `Lieu : ${inv.event_venue}${inv.event_venue_note ? ` (${inv.event_venue_note})` : ""}`,
    `Statut RSVP : ${rsvp}`,
    `Nombre de personnes prévues : ${inv.party_size}`,
  ].join("\n");
}

const SYSTEM_RULES = `Tu es l'assistant d'accueil de cet événement. Tu réponds aux invités en français, avec chaleur et concision.

Règles :
- Réponds en 2 à 3 phrases maximum. Ton courtois, jamais familier.
- Utilise UNIQUEMENT les informations de la fiche ci-dessous. N'invente jamais une adresse, un horaire, un code vestimentaire ou un détail qui n'y figure pas.
- Si l'information demandée est absente de la fiche, dis-le simplement et invite la personne à contacter les organisateurs.
- Si l'invité veut confirmer ou décliner sa présence, explique qu'il peut répondre « OUI » ou « NON », ou utiliser le bouton de confirmation sur sa page d'invitation.
- Tu t'adresses à l'invité nommé dans la fiche : tutoie jamais, vouvoie toujours.`;

/** Réponses de repli sans LLM (aucune clé API configurée) : suffisant pour la démo. */
export function localAnswer(question: string, inv: Invitation | null): string {
  const q = question.toLowerCase();
  const has = (...w: string[]) => w.some((x) => q.includes(x));

  if (!inv) return "Je n'ai pas retrouvé votre invitation. Contactez les organisateurs, ils vous aideront.";

  if (has("quand", "date", "jour", "heure", "quelle heure"))
    return `C'est le ${inv.event_date} ${inv.event_year}, à ${inv.event_time}${
      inv.event_time_note ? ` (${inv.event_time_note})` : ""
    }.`;
  if (has("où", "ou est", "lieu", "adresse", "endroit"))
    return `Cela se passe à ${inv.event_venue}${
      inv.event_venue_note ? `. ${inv.event_venue_note}.` : "."
    }`;
  if (has("confirmer", "rsvp", "présence", "je viens", "venir"))
    return "Vous pouvez répondre « OUI » pour confirmer ou « NON » pour décliner, ou utiliser le bouton sur votre page d'invitation.";
  if (has("qr", "code", "entrée", "entrer"))
    return "Votre invitation contient un QR code : présentez-le à l'entrée, il suffit de le montrer depuis votre téléphone.";
  if (has("bonjour", "salut", "bonsoir"))
    return `Bonjour ${[inv.honorific, inv.guest_name].filter(Boolean).join(" ")}, comment puis-je vous aider ?`;

  return "Je n'ai pas cette information sous la main. Les organisateurs pourront vous répondre précisément.";
}

/**
 * Interroge Claude. Repli automatique sur `localAnswer` si aucune clé API
 * n'est configurée ou si l'appel échoue — l'invité obtient toujours une réponse.
 */
export async function ask(
  question: string,
  inv: Invitation | null,
  history: Turn[] = [],
): Promise<string> {
  if (!ANTHROPIC_API_KEY) return localAnswer(question, inv);

  const client = new Anthropic({ apiKey: ANTHROPIC_API_KEY });

  try {
    const response = await client.beta.messages.create({
      model: "claude-opus-5",
      // Réponses volontairement courtes (2-3 phrases, limite WhatsApp de 4096 caractères).
      max_tokens: 1024,
      // Questions d'accueil simples : priorité à la latence.
      output_config: { effort: "low" },
      // Reprise automatique côté serveur si un refus de sécurité survient.
      betas: ["server-side-fallback-2026-07-01"],
      fallbacks: "default",
      system: `${SYSTEM_RULES}\n\n--- Fiche de l'invité ---\n${eventContext(inv)}`,
      messages: [
        ...history.slice(-8).map((t) => ({ role: t.role, content: t.content })),
        { role: "user" as const, content: question },
      ],
    });

    // Toujours vérifier stop_reason avant de lire content.
    if (response.stop_reason === "refusal") {
      return "Je préfère ne pas répondre à cela. Les organisateurs pourront vous aider.";
    }

    const text = response.content
      .filter((b): b is Anthropic.TextBlock => b.type === "text")
      .map((b) => b.text)
      .join("")
      .trim();

    return text || localAnswer(question, inv);
  } catch (error) {
    // Du plus spécifique au plus général.
    if (error instanceof Anthropic.AuthenticationError) {
      console.error("Clé API Anthropic invalide");
    } else if (error instanceof Anthropic.RateLimitError) {
      console.error("Anthropic: quota atteint");
    } else if (error instanceof Anthropic.APIError) {
      console.error(`Anthropic API ${error.status}: ${error.message}`);
    } else {
      console.error("Assistant:", error);
    }
    return localAnswer(question, inv);
  }
}
