// ============================================================
// Edge Function : invite-preview
//   GET /functions/v1/invite-preview/<token>?app=<url-front>&v=<cache-buster>
//
//   1. Récupère l'invité par son token (clé service_role, contourne la RLS).
//   2. Renvoie une page HTML avec des balises Open Graph / Twitter
//      PERSONNALISÉES (nom de l'invité, message, image) -> bel aperçu
//      quand le lien est partagé (WhatsApp, SMS, réseaux sociaux).
//   3. Redirige l'humain vers la SPA : <app>/i/<token>.
// ============================================================

const SUPABASE_URL = Deno.env.get("SUPABASE_URL") ?? "";
const SERVICE_ROLE_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY") ?? "";
const DEFAULT_APP_URL = Deno.env.get("APP_URL") ?? "http://localhost:5173";
const OG_IMAGE_URL = Deno.env.get("OG_IMAGE_URL") ?? "";

interface Invitation {
  token: string;
  honorific: string | null;
  guest_name: string;
  event_title: string;
  message: string;
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/** N'accepte l'`app` fournie que si elle est en https et sur un hôte de confiance. */
function resolveAppUrl(appParam: string | null): string {
  if (!appParam) return DEFAULT_APP_URL;
  try {
    const u = new URL(appParam);
    const def = new URL(DEFAULT_APP_URL);
    const ok = u.protocol === "https:" && (u.host === def.host || u.host.endsWith(".lovable.app"));
    return ok ? u.origin : DEFAULT_APP_URL;
  } catch {
    return DEFAULT_APP_URL;
  }
}

async function fetchInvitation(token: string): Promise<Invitation | null> {
  if (!SUPABASE_URL || !SERVICE_ROLE_KEY) return null;
  const res = await fetch(`${SUPABASE_URL}/rest/v1/rpc/get_invitation`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      apikey: SERVICE_ROLE_KEY,
      Authorization: `Bearer ${SERVICE_ROLE_KEY}`,
    },
    body: JSON.stringify({ p_token: token }),
  });
  if (!res.ok) return null;
  const data = await res.json();
  const row = Array.isArray(data) ? data[0] : data;
  return row ?? null;
}

function page(inv: Invitation | null, appUrl: string, token: string): string {
  const fullName = inv
    ? [inv.honorific, inv.guest_name].filter(Boolean).join(" ")
    : "Vous êtes convié·e";
  const title = inv ? `${fullName} — ${inv.event_title}` : "Invitation Équinoxe";
  const desc = inv?.message ?? "Votre présence sera pour nous un honneur.";
  const target = `${appUrl}/i/${encodeURIComponent(token)}`;

  const og = OG_IMAGE_URL
    ? `<meta property="og:image" content="${escapeHtml(OG_IMAGE_URL)}" />
    <meta name="twitter:image" content="${escapeHtml(OG_IMAGE_URL)}" />`
    : "";

  return `<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>${escapeHtml(title)}</title>
  <meta name="description" content="${escapeHtml(desc)}" />
  <meta property="og:type" content="website" />
  <meta property="og:title" content="${escapeHtml(title)}" />
  <meta property="og:description" content="${escapeHtml(desc)}" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="${escapeHtml(title)}" />
  <meta name="twitter:description" content="${escapeHtml(desc)}" />
  ${og}
  <link rel="canonical" href="${escapeHtml(target)}" />
  <meta http-equiv="refresh" content="0; url=${escapeHtml(target)}" />
  <style>
    html,body{margin:0;height:100%;background:#0a0f1e;color:#f2ede2;
      font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center}
    a{color:#f4dd9a}
  </style>
</head>
<body>
  <p>Ouverture de votre invitation… <a href="${escapeHtml(target)}">Continuer</a></p>
  <script>location.replace(${JSON.stringify(target)});</script>
</body>
</html>`;
}

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") {
    return new Response("ok", {
      headers: {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Methods": "GET, OPTIONS",
        "Access-Control-Allow-Headers": "authorization, apikey, content-type",
      },
    });
  }

  const url = new URL(req.url);
  // .../invite-preview/<token>
  const token = url.pathname.split("/").filter(Boolean).pop() ?? "";
  const appUrl = resolveAppUrl(url.searchParams.get("app"));

  if (!token || token === "invite-preview") {
    return new Response("Token manquant.", { status: 400 });
  }

  const inv = await fetchInvitation(token);
  const html = page(inv, appUrl, token);

  return new Response(html, {
    status: 200,
    headers: {
      "Content-Type": "text/html; charset=utf-8",
      "Cache-Control": "public, max-age=300",
      "Access-Control-Allow-Origin": "*",
    },
  });
});
