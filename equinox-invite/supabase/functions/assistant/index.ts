// ============================================================
// Edge Function : assistant
//   POST { token, question, history? } -> { answer }
//
// Alimente l'assistant vocal de la page d'invitation. La clé Anthropic
// reste côté serveur : elle n'est jamais exposée au navigateur.
// ============================================================

import { ask, type Turn } from "../_shared/assistant.ts";
import { getInvitationByToken } from "../_shared/db.ts";
import { corsHeaders, json } from "../_shared/cors.ts";

const MAX_QUESTION_LEN = 500;

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") return new Response("ok", { headers: corsHeaders });
  if (req.method !== "POST") return json({ error: "Méthode non autorisée." }, 405);

  let payload: { token?: string; question?: string; history?: Turn[] };
  try {
    payload = await req.json();
  } catch {
    return json({ error: "Corps de requête invalide." }, 400);
  }

  const question = (payload.question ?? "").trim();
  if (!question) return json({ error: "Question vide." }, 400);
  if (question.length > MAX_QUESTION_LEN) {
    return json({ error: "Question trop longue." }, 413);
  }

  const invitation = payload.token ? await getInvitationByToken(payload.token) : null;

  const history = Array.isArray(payload.history)
    ? payload.history
        .filter((t) => t && (t.role === "user" || t.role === "assistant") && typeof t.content === "string")
        .slice(-8)
    : [];

  const answer = await ask(question, invitation, history);
  return json({ answer });
});
