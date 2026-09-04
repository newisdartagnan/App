import html2canvas from "html2canvas";
import { jsPDF } from "jspdf";

/**
 * Génère un vrai fichier PDF téléchargeable à partir de l'élément de la carte.
 * (Fonctionne une fois l'app déployée / en local — pas dans le sandbox d'aperçu.)
 */
export async function downloadInvitationPdf(
  element: HTMLElement,
  fileName = "invitation.pdf",
): Promise<void> {
  const canvas = await html2canvas(element, {
    backgroundColor: "#0a0f1e",
    scale: Math.min(3, window.devicePixelRatio * 2 || 2),
    useCORS: true,
    logging: false,
  });

  const img = canvas.toDataURL("image/png");
  const orientation = canvas.width >= canvas.height ? "landscape" : "portrait";
  const pdf = new jsPDF({ orientation, unit: "px", format: [canvas.width, canvas.height] });
  pdf.addImage(img, "PNG", 0, 0, canvas.width, canvas.height);
  pdf.save(fileName);
}

/** Repli : ouvre la boîte d'impression du navigateur (Enregistrer en PDF). */
export function printInvitation(): void {
  window.print();
}
