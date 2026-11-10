// Synthèse et reconnaissance vocales via la Web Speech API.
// Support inégal selon les navigateurs : tout est dégradable sans casse.

const LANG = "fr-FR";

/* ------------------------- Synthèse (l'assistant parle) ------------------------ */

export const canSpeak = (): boolean =>
  typeof window !== "undefined" && "speechSynthesis" in window;

/** Choisit une voix française si le navigateur en propose une. */
function frenchVoice(): SpeechSynthesisVoice | null {
  if (!canSpeak()) return null;
  const voices = window.speechSynthesis.getVoices();
  return voices.find((v) => v.lang?.startsWith("fr")) ?? null;
}

export function speak(text: string): void {
  if (!canSpeak() || !text.trim()) return;
  window.speechSynthesis.cancel();

  const utter = new SpeechSynthesisUtterance(text);
  utter.lang = LANG;
  utter.rate = 1;
  utter.pitch = 1;
  const voice = frenchVoice();
  if (voice) utter.voice = voice;

  window.speechSynthesis.speak(utter);
}

export function stopSpeaking(): void {
  if (canSpeak()) window.speechSynthesis.cancel();
}

/* --------------------- Reconnaissance (l'invité parle) ------------------------- */

/** Constructeur non standard : préfixé sur les navigateurs WebKit. */
type RecognitionCtor = new () => SpeechRecognitionLike;

interface SpeechRecognitionLike {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  start(): void;
  stop(): void;
  onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
  onerror: ((e: unknown) => void) | null;
  onend: (() => void) | null;
}

function recognitionCtor(): RecognitionCtor | null {
  if (typeof window === "undefined") return null;
  const w = window as unknown as {
    SpeechRecognition?: RecognitionCtor;
    webkitSpeechRecognition?: RecognitionCtor;
  };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

export const canListen = (): boolean => recognitionCtor() !== null;

/**
 * Écoute une seule phrase. Renvoie une fonction d'annulation.
 * `onResult` reçoit la transcription ; `onEnd` est toujours appelé à la fin.
 */
export function listenOnce(
  onResult: (transcript: string) => void,
  onEnd: () => void,
): () => void {
  const Ctor = recognitionCtor();
  if (!Ctor) {
    onEnd();
    return () => {};
  }

  const recognition = new Ctor();
  recognition.lang = LANG;
  recognition.continuous = false;
  recognition.interimResults = false;

  recognition.onresult = (e) => {
    const transcript = e.results?.[0]?.[0]?.transcript ?? "";
    if (transcript) onResult(transcript);
  };
  recognition.onerror = () => onEnd();
  recognition.onend = () => onEnd();

  try {
    recognition.start();
  } catch {
    onEnd();
  }

  return () => {
    try {
      recognition.stop();
    } catch {
      /* déjà arrêtée */
    }
  };
}
