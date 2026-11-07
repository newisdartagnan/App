<?php

namespace App\Services\Dossier;

use App\Models\Ordonnance;
use App\Services\RendezVous\Affichage;
use App\Support\CodeQr;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'ordonnance hors de l'application (PRE-03) : son PDF, et la page publique
 * de vérification vers laquelle pointe son QR code.
 *
 * PDF : aucune ressource externe (dompdf sans accès distant) ; le QR code
 * est un SVG intégré en « data: », que dompdf dessine en vecteurs, sans GD.
 *
 * Vérification : numéro, date, médecin et lignes ; le patient par ses
 * initiales seulement. « Authentique » si l'empreinte recalculée sur le
 * contenu en base correspond à celle de la signature, sinon « altérée ».
 * Un code inconnu (ou mal formé) a une réponse neutre, la même qu'il ait
 * existé ou non.
 */
final class DocumentOrdonnance
{
    public const FORMAT_CODE = '/^[A-Za-z0-9]{32}$/';

    public function pdf(Ordonnance $ordonnance): Response
    {
        return Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->loadView('pdf.ordonnance', $this->donneesPdf($ordonnance))
            ->download($ordonnance->numero.'.pdf')
            ->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }

    /** Ce que montre le PDF : MEDIHOME, numéro, date, médecin (nom, n° d'Ordre), patient (nom, âge), lignes, QR code. */
    public function donneesPdf(Ordonnance $ordonnance): array
    {
        $ordonnance->loadMissing(['lignes', 'patient', 'medecin.user', 'medecin.specialite']);
        $patient = $ordonnance->patient;
        $signee = $ordonnance->signee_le->setTimezone('Africa/Kinshasa');
        $url = self::adresseVerification($ordonnance);

        return [
            'ordonnance' => $ordonnance,
            'date' => $signee->format('d/m/Y'),
            'heure' => $signee->format('H:i'),
            'medecin' => Affichage::medecin($ordonnance->medecin),
            'numeroOrdre' => $ordonnance->medecin->numero_ordre,
            'specialite' => $ordonnance->medecin->specialite?->nom,
            'patient' => trim($patient->prenom.' '.mb_strtoupper((string) $patient->nom)),
            'age' => $patient->date_naissance ? (int) $patient->date_naissance->diffInYears($signee) : null,
            'qr' => CodeQr::svgEnDataUri($url, 160),
            'adresse' => $url,
        ];
    }

    /** null : code inconnu ou mal formé (réponse neutre). */
    public function verifier(string $code): ?array
    {
        if (preg_match(self::FORMAT_CODE, $code) !== 1) {
            return null;
        }
        $ordonnance = Ordonnance::with(['lignes', 'patient:id,nom,prenom', 'medecin.user:id,nom,prenom'])
            ->where('code_verification', $code)->first();
        if ($ordonnance === null) {
            return null;
        }

        return [
            'statut' => OrdonnanceService::estAuthentique($ordonnance) ? 'authentique' : 'alteree',
            'numero' => $ordonnance->numero,
            'date' => $ordonnance->signee_le->setTimezone('Africa/Kinshasa')->format('d/m/Y'),
            'medecin' => Affichage::medecin($ordonnance->medecin),
            'numeroOrdre' => $ordonnance->medecin->numero_ordre,
            'patient' => self::initiales($ordonnance->patient->prenom, $ordonnance->patient->nom),
            'consignes' => $ordonnance->consignes,
            'lignes' => $ordonnance->lignes->map->only(['rang', 'medicament', 'dosage', 'voie', 'posologie', 'duree', 'consignes'])->values()->all(),
        ];
    }

    public static function adresseVerification(Ordonnance $ordonnance): string
    {
        return route('ordonnance.verification', ['code' => $ordonnance->code_verification]);
    }

    /** « Marie KABILA » → « M. K. » */
    public static function initiales(?string ...$noms): string
    {
        return collect($noms)->filter(fn ($n) => filled($n))
            ->map(fn (string $n) => mb_strtoupper(mb_substr(trim($n), 0, 1)).'.')
            ->implode(' ');
    }
}
