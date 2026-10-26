import { useEffect, useState } from "react";
import QRCode from "qrcode";

interface Props {
  /** Contenu encodé — ici le lien personnalisé de l'invitation. */
  value: string;
  size?: number;
  caption?: string;
}

/**
 * QR code d'entrée. Modules sombres sur pastille ivoire : c'est le contraste
 * qui rend le code scannable, un QR doré sur fond nuit ne se lit pas.
 */
export default function QrCode({ value, size = 132, caption }: Props) {
  const [src, setSrc] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    QRCode.toDataURL(value, {
      width: size * 2, // rendu 2x pour rester net en PDF
      margin: 1,
      errorCorrectionLevel: "M",
      color: { dark: "#0a0f1eff", light: "#f2ede2ff" },
    })
      .then((url) => {
        if (alive) setSrc(url);
      })
      .catch((err) => console.error("QR:", err));
    return () => {
      alive = false;
    };
  }, [value, size]);

  return (
    <figure className="qr">
      {src ? (
        <img src={src} width={size} height={size} alt="QR code d'entrée pour cette invitation" />
      ) : (
        <div className="qr-placeholder" style={{ width: size, height: size }} aria-hidden="true" />
      )}
      <figcaption>{caption ?? "Présentez ce code à l'entrée"}</figcaption>
    </figure>
  );
}
