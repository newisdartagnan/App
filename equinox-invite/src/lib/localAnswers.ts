import type { Invitation } from "../types";

/**
 * Réponses de repli sans backend (mode démo, ou Edge Function injoignable).
 * Miroir volontaire de `supabase/functions/_shared/assistant.ts` : l'invité
 * obtient toujours une réponse utile, même hors ligne côté serveur.
 */
export function localAnswer(question: string, inv: Invitation | null): string {
  const q = question.toLowerCase();
  const has = (...w: string[]) => w.some((x) => q.includes(x));

  if (!inv) return "Je n'ai pas retrouvé votre invitation. Contactez les organisateurs, ils vous aideront.";

  if (has("quand", "date", "jour", "heure"))
    return `C'est le ${inv.event_date} ${inv.event_year}, à ${inv.event_time}${
      inv.event_time_note ? ` (${inv.event_time_note})` : ""
    }.`;

  if (has("où", "ou est", "lieu", "adresse", "endroit"))
    return `Cela se passe à ${inv.event_venue}${
      inv.event_venue_note ? `. ${inv.event_venue_note}.` : "."
    }`;

  if (has("confirmer", "rsvp", "présence", "je viens", "venir"))
    return "Vous pouvez confirmer avec les boutons « Je serai présent·e » ou « Je ne pourrai pas » sur cette page.";

  if (has("qr", "code", "entrée", "entrer", "billet"))
    return "Votre invitation contient un QR code : présentez-le à l'entrée, depuis votre téléphone ou le PDF.";

  if (has("télécharger", "pdf", "enregistrer"))
    return "Le bouton « Télécharger votre invitation » enregistre votre carton en PDF, QR code compris.";

  if (has("bonjour", "salut", "bonsoir"))
    return `Bonjour ${[inv.honorific, inv.guest_name].filter(Boolean).join(" ")}, comment puis-je vous aider ?`;

  return "Je n'ai pas cette information sous la main. Les organisateurs pourront vous répondre précisément.";
}
