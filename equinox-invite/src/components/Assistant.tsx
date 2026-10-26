import { useEffect, useRef, useState } from "react";
import type { ChatTurn, Invitation } from "../types";
import { askAssistant } from "../lib/supabase";
import { localAnswer } from "../lib/localAnswers";
import { canListen, canSpeak, listenOnce, speak, stopSpeaking } from "../lib/speech";

const SUGGESTIONS = ["À quelle heure ?", "C'est où ?", "Comment confirmer ?"];

export default function Assistant({ data }: { data: Invitation }) {
  const [open, setOpen] = useState(false);
  const [turns, setTurns] = useState<ChatTurn[]>([]);
  const [input, setInput] = useState("");
  const [busy, setBusy] = useState(false);
  const [listening, setListening] = useState(false);
  const [voiceOn, setVoiceOn] = useState(true);

  const logRef = useRef<HTMLDivElement | null>(null);
  const stopListenRef = useRef<(() => void) | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);

  // Message d'accueil à la première ouverture.
  useEffect(() => {
    if (open && turns.length === 0) {
      const hello = `Bonjour ${[data.honorific, data.guest_name]
        .filter(Boolean)
        .join(" ")}, je suis l'assistant de la soirée. Posez-moi vos questions.`;
      setTurns([{ role: "assistant", content: hello }]);
      if (voiceOn) speak(hello);
    }
  }, [open, turns.length, data, voiceOn]);

  // Défilement vers le dernier message.
  useEffect(() => {
    logRef.current?.scrollTo({ top: logRef.current.scrollHeight, behavior: "smooth" });
  }, [turns, busy]);

  // Coupe la voix et le micro en quittant.
  useEffect(() => {
    return () => {
      stopSpeaking();
      stopListenRef.current?.();
    };
  }, []);

  async function send(question: string) {
    const q = question.trim();
    if (!q || busy) return;

    setInput("");
    setBusy(true);
    const history = turns;
    setTurns((prev) => [...prev, { role: "user", content: q }]);

    let answer: string | null = null;
    try {
      answer = await askAssistant(data.token, q, history);
    } catch (err) {
      console.error(err);
    }
    const finalAnswer = answer ?? localAnswer(q, data);

    setTurns((prev) => [...prev, { role: "assistant", content: finalAnswer }]);
    setBusy(false);
    if (voiceOn) speak(finalAnswer);
    inputRef.current?.focus();
  }

  function toggleMic() {
    if (listening) {
      stopListenRef.current?.();
      setListening(false);
      return;
    }
    stopSpeaking();
    setListening(true);
    stopListenRef.current = listenOnce(
      (transcript) => void send(transcript),
      () => setListening(false),
    );
  }

  function toggleVoice() {
    setVoiceOn((on) => {
      if (on) stopSpeaking();
      return !on;
    });
  }

  return (
    <>
      <button
        className="assistant-fab"
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-controls="assistant-panel"
      >
        {open ? "Fermer" : "Assistant"}
      </button>

      {open && (
        <section className="assistant" id="assistant-panel" aria-label="Assistant de la soirée">
          <header className="assistant-head">
            <span className="assistant-title">Assistant</span>
            <div className="assistant-actions">
              <button
                type="button"
                className="icon-btn"
                onClick={toggleVoice}
                aria-pressed={voiceOn}
                title={voiceOn ? "Couper la voix" : "Activer la voix"}
                disabled={!canSpeak()}
              >
                {voiceOn ? "🔊" : "🔇"}
              </button>
              <button
                type="button"
                className="icon-btn"
                onClick={() => setOpen(false)}
                title="Fermer"
              >
                ✕
              </button>
            </div>
          </header>

          <div className="assistant-log" ref={logRef} aria-live="polite">
            {turns.map((t, i) => (
              <p key={i} className={t.role === "user" ? "bubble me" : "bubble bot"}>
                {t.content}
              </p>
            ))}
            {busy && (
              <p className="bubble bot typing" aria-label="L'assistant rédige">
                <span /> <span /> <span />
              </p>
            )}
          </div>

          {turns.length <= 1 && !busy && (
            <div className="assistant-suggest">
              {SUGGESTIONS.map((s) => (
                <button key={s} type="button" onClick={() => void send(s)}>
                  {s}
                </button>
              ))}
            </div>
          )}

          <form
            className="assistant-input"
            onSubmit={(e) => {
              e.preventDefault();
              void send(input);
            }}
          >
            <input
              ref={inputRef}
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder={listening ? "Je vous écoute…" : "Votre question…"}
              aria-label="Votre question"
              disabled={busy}
            />
            {canListen() && (
              <button
                type="button"
                className={listening ? "icon-btn mic on" : "icon-btn mic"}
                onClick={toggleMic}
                aria-pressed={listening}
                title={listening ? "Arrêter le micro" : "Parler"}
              >
                🎙
              </button>
            )}
            <button type="submit" className="icon-btn send" disabled={busy || !input.trim()} title="Envoyer">
              ➤
            </button>
          </form>
        </section>
      )}
    </>
  );
}
