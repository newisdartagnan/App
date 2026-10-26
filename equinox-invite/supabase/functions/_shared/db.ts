// Accès Postgres via PostgREST avec la clé service_role.
// Utilisé uniquement côté serveur (Edge Functions) — jamais exposé au client.

const SUPABASE_URL = Deno.env.get("SUPABASE_URL") ?? "";
const SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? "";

export interface Invitation {
  id: string;
  token: string;
  honorific: string | null;
  guest_name: string;
  event_title: string;
  event_date: string;
  event_year: string;
  event_time: string;
  event_time_note: string | null;
  event_venue: string;
  event_venue_note: string | null;
  message: string;
  signature: string | null;
  phone: string | null;
  wa_opt_in: boolean;
  rsvp_status: "pending" | "yes" | "no" | "maybe";
  party_size: number;
  checked_in_at: string | null;
}

export const hasDb = () => Boolean(SUPABASE_URL && SERVICE_ROLE_KEY);

async function rest(path: string, init: RequestInit = {}): Promise<Response> {
  return await fetch(`${SUPABASE_URL}/rest/v1/${path}`, {
    ...init,
    headers: {
      "Content-Type": "application/json",
      apikey: SERVICE_ROLE_KEY,
      Authorization: `Bearer ${SERVICE_ROLE_KEY}`,
      ...(init.headers ?? {}),
    },
  });
}

async function firstRow<T>(res: Response): Promise<T | null> {
  if (!res.ok) return null;
  const data = await res.json();
  const row = Array.isArray(data) ? data[0] : data;
  return (row as T) ?? null;
}

export async function getInvitationByToken(token: string): Promise<Invitation | null> {
  if (!hasDb()) return null;
  return await firstRow<Invitation>(
    await rest("rpc/get_invitation", {
      method: "POST",
      body: JSON.stringify({ p_token: token }),
    }),
  );
}

export async function getInvitationByPhone(phone: string): Promise<Invitation | null> {
  if (!hasDb()) return null;
  return await firstRow<Invitation>(
    await rest(`invitations?phone=eq.${encodeURIComponent(phone)}&limit=1`),
  );
}

/** Invités joignables sur WhatsApp : numéro renseigné ET consentement donné. */
export async function listSendableInvitations(): Promise<Invitation[]> {
  if (!hasDb()) return [];
  const res = await rest("invitations?wa_opt_in=is.true&phone=not.is.null&select=*");
  if (!res.ok) return [];
  return (await res.json()) as Invitation[];
}

export async function setRsvpByToken(
  token: string,
  status: "yes" | "no" | "maybe",
): Promise<Invitation | null> {
  if (!hasDb()) return null;
  return await firstRow<Invitation>(
    await rest("rpc/set_rsvp", {
      method: "POST",
      body: JSON.stringify({ p_token: token, p_status: status }),
    }),
  );
}

/** Journalise un message WhatsApp. Renvoie false si l'id Meta est déjà connu (rejeu). */
export async function logWaMessage(entry: {
  wa_message_id?: string | null;
  invitation_id?: string | null;
  direction: "in" | "out";
  phone?: string | null;
  body?: string | null;
}): Promise<boolean> {
  if (!hasDb()) return true;
  const res = await rest("wa_messages", {
    method: "POST",
    headers: { Prefer: "return=minimal" },
    body: JSON.stringify(entry),
  });
  // 409 = wa_message_id déjà présent -> webhook rejoué par Meta, à ignorer.
  return res.status !== 409;
}
