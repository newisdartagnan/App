import { useEffect, useState } from "react";
import Starfield from "./components/Starfield";
import Invitation from "./components/Invitation";
import Assistant from "./components/Assistant";
import { getInvitation } from "./lib/supabase";
import type { Invitation as InvitationData } from "./types";

type Status = "loading" | "ready" | "notfound";

/** Récupère le token depuis ?token=... ou depuis le chemin /i/<token>. */
function readToken(): string | null {
  const params = new URLSearchParams(window.location.search);
  const q = params.get("token");
  if (q) return q;
  const m = window.location.pathname.match(/\/i\/([^/?#]+)/);
  return m ? decodeURIComponent(m[1]) : null;
}

export default function App() {
  const [status, setStatus] = useState<Status>("loading");
  const [data, setData] = useState<InvitationData | null>(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      const invitation = await getInvitation(readToken());
      if (!alive) return;
      if (invitation) {
        setData(invitation);
        setStatus("ready");
        document.title = `Invitation · ${[invitation.honorific, invitation.guest_name]
          .filter(Boolean)
          .join(" ")}`;
      } else {
        setStatus("notfound");
      }
    })();
    return () => {
      alive = false;
    };
  }, []);

  return (
    <>
      <div className="sky" />
      <Starfield />

      {status === "loading" && (
        <div className="state">
          <span className="spin" aria-hidden="true" />
          <p>Ouverture de votre invitation…</p>
        </div>
      )}

      {status === "notfound" && (
        <div className="state">
          <p>Cette invitation est introuvable ou a expiré.</p>
        </div>
      )}

      {status === "ready" && data && (
        <>
          <Invitation data={data} />
          <Assistant data={data} />
        </>
      )}
    </>
  );
}
