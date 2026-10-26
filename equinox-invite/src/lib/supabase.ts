import { createClient, type SupabaseClient } from "@supabase/supabase-js";
import type { ChatTurn, Invitation, RsvpStatus } from "../types";

const url = import.meta.env.VITE_SUPABASE_URL as string | undefined;
const anonKey = import.meta.env.VITE_SUPABASE_ANON_KEY as string | undefined;

/** Actif seulement si les deux variables sont renseignées, sinon mode démo. */
export const hasBackend = Boolean(url && anonKey);

export const supabase: SupabaseClient | null = hasBackend
  ? createClient(url as string, anonKey as string)
  : null;

/** Invitation d'exemple servie en mode démo (aucun backend configuré). */
const SAMPLE: Invitation = {
  token: "demo",
  honorific: "M.",
  guest_name: "Boris Ikula",
  event_title: "Équinoxe",
  event_date: "Sam. 21 sept.",
  event_year: "2026",
  event_time: "18 h 30",
  event_time_note: "Accueil dès 18 h",
  event_venue: "Domaine du Lac",
  event_venue_note: "Plan à suivre",
  message:
    "Votre présence sera pour nous un honneur et rendra cette journée encore plus mémorable.",
  signature: "Avec toute notre reconnaissance",
  rsvp_status: "pending",
  party_size: 1,
};

/**
 * Récupère une invitation par son token.
 * - Avec backend : RPC `get_invitation` (SECURITY DEFINER), qui ne renvoie que
 *   la ligne correspondant au token — la table n'est jamais exposée.
 * - Sans backend : renvoie l'exemple.
 */
export async function getInvitation(token: string | null): Promise<Invitation | null> {
  if (!token) return { ...SAMPLE };

  if (!hasBackend || !supabase) {
    return { ...SAMPLE, token };
  }

  const { data, error } = await supabase.rpc("get_invitation", { p_token: token });
  if (error) {
    console.error("get_invitation:", error.message);
    return null;
  }
  const row = Array.isArray(data) ? data[0] : data;
  return (row as Invitation) ?? null;
}

/** Enregistre la réponse de l'invité. En mode démo, l'état reste local. */
export async function setRsvp(
  token: string,
  status: Exclude<RsvpStatus, "pending">,
): Promise<boolean> {
  if (!hasBackend || !supabase) return true;

  const { error } = await supabase.rpc("set_rsvp", {
    p_token: token,
    p_status: status,
    p_party_size: 1,
  });
  if (error) {
    console.error("set_rsvp:", error.message);
    return false;
  }
  return true;
}

/**
 * Pose une question à l'assistant.
 * Avec backend : Edge Function `assistant` (la clé Anthropic reste serveur).
 * Sans backend : `null`, l'appelant utilise sa réponse de repli locale.
 */
export async function askAssistant(
  token: string,
  question: string,
  history: ChatTurn[],
): Promise<string | null> {
  if (!hasBackend || !supabase) return null;

  const { data, error } = await supabase.functions.invoke("assistant", {
    body: { token, question, history },
  });
  if (error) {
    console.error("assistant:", error.message);
    return null;
  }
  return (data as { answer?: string } | null)?.answer ?? null;
}
