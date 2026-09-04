import { useMemo, useRef, useState } from "react";
import type { Invitation as InvitationData, RsvpStatus } from "../types";
import { setRsvp } from "../lib/supabase";
import QrCode from "./QrCode";

const CelestialMark = () => (
  <svg className="mark reveal d1" viewBox="0 0 120 60" aria-hidden="true">
    <defs>
      <radialGradient id="equinox-disc" cx="50%" cy="60%" r="60%">
        <stop offset="0%" stopColor="#f4dd9a" />
        <stop offset="60%" stopColor="#e3c069" />
        <stop offset="100%" stopColor="#b98f3c" />
      </radialGradient>
    </defs>
    <g className="disc">
      <circle cx="60" cy="46" r="14" fill="url(#equinox-disc)" />
      <g stroke="#e3c069" strokeWidth="1" opacity="0.7">
        <line x1="60" y1="20" x2="60" y2="10" />
        <line x1="41" y1="27" x2="34" y2="20" />
        <line x1="79" y1="27" x2="86" y2="20" />
        <line x1="33" y1="46" x2="23" y2="46" />
        <line x1="87" y1="46" x2="97" y2="46" />
      </g>
    </g>
    <line x1="6" y1="46" x2="114" y2="46" stroke="#e3c069" strokeWidth="1" opacity="0.55" />
  </svg>
);

export default function Invitation({ data }: { data: InvitationData }) {
  const cardRef = useRef<HTMLElement | null>(null);
  const [busy, setBusy] = useState(false);
  const [rsvp, setRsvpState] = useState<RsvpStatus>(data.rsvp_status);
  const [rsvpBusy, setRsvpBusy] = useState(false);

  const fullName = [data.honorific, data.guest_name].filter(Boolean).join(" ");

  // Lien encodé dans le QR : la page personnalisée de cet invité.
  const inviteUrl = useMemo(
    () => `${window.location.origin}/i/${encodeURIComponent(data.token)}`,
    [data.token],
  );

  const handleDownload = async () => {
    if (!cardRef.current || busy) return;
    setBusy(true);
    try {
      const { downloadInvitationPdf } = await import("../lib/pdf");
      const safe = data.guest_name.normalize("NFD").replace(/[^\w]+/g, "_");
      await downloadInvitationPdf(cardRef.current, `invitation-${safe || "equinoxe"}.pdf`);
    } catch (err) {
      console.error(err);
      window.print(); // repli
    } finally {
      setBusy(false);
    }
  };

  const answer = async (status: Exclude<RsvpStatus, "pending">) => {
    if (rsvpBusy) return;
    setRsvpBusy(true);
    const previous = rsvp;
    setRsvpState(status); // optimiste
    const ok = await setRsvp(data.token, status);
    if (!ok) setRsvpState(previous);
    setRsvpBusy(false);
  };

  return (
    <main className="stage">
      <article className="card" ref={cardRef}>
        <span className="corner tl" />
        <span className="corner tr" />
        <span className="corner bl" />
        <span className="corner br" />

        <p className="eyebrow reveal d1">{data.event_title} · Vous êtes convié·e</p>

        <CelestialMark />

        <p className="greet reveal d2">Bonjour</p>
        <h1 className="name reveal d2">{fullName}</h1>

        <div className="rule reveal d3" aria-hidden="true" />

        <p className="message reveal d3">{data.message}</p>

        <div className="meta reveal d4">
          <div>
            <div className="k">Date</div>
            <div className="v">{data.event_date}</div>
            <div className="s">{data.event_year}</div>
          </div>
          <div>
            <div className="k">Heure</div>
            <div className="v">{data.event_time}</div>
            {data.event_time_note && <div className="s">{data.event_time_note}</div>}
          </div>
          <div>
            <div className="k">Lieu</div>
            <div className="v">{data.event_venue}</div>
            {data.event_venue_note && <div className="s">{data.event_venue_note}</div>}
          </div>
        </div>

        <div className="reveal d5">
          <QrCode value={inviteUrl} />
        </div>

        {data.signature && <p className="sig reveal d6">{data.signature}</p>}
      </article>

      <div className="rsvp reveal d5">
        <p className="rsvp-q">
          {rsvp === "yes"
            ? "Votre présence est confirmée. Merci !"
            : rsvp === "no"
              ? "Vous avez décliné. Merci de nous avoir prévenus."
              : rsvp === "maybe"
                ? "Vous avez répondu « peut-être »."
                : "Nous ferez-vous l'honneur de votre présence ?"}
        </p>
        <div className="rsvp-actions">
          <button
            type="button"
            className={rsvp === "yes" ? "pill on" : "pill"}
            onClick={() => void answer("yes")}
            disabled={rsvpBusy}
            aria-pressed={rsvp === "yes"}
          >
            Je serai présent·e
          </button>
          <button
            type="button"
            className={rsvp === "no" ? "pill on" : "pill"}
            onClick={() => void answer("no")}
            disabled={rsvpBusy}
            aria-pressed={rsvp === "no"}
          >
            Je ne pourrai pas
          </button>
        </div>
      </div>

      <div className="cta-wrap reveal d5">
        <button className="cta" type="button" onClick={handleDownload} disabled={busy}>
          {busy ? "Préparation…" : "Télécharger votre invitation"}
        </button>
        <p className="hint">Le PDF inclut votre QR code d'entrée.</p>
      </div>
    </main>
  );
}
