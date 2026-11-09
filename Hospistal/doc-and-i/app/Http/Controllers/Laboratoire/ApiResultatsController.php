<?php

namespace App\Http\Controllers\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Http\Controllers\Controller;
use App\Http\Middleware\JetonLaboratoireRequis;
use App\Services\Laboratoire\ActeurLaboratoire;
use App\Services\Laboratoire\DepotResultats;
use App\Services\Laboratoire\LaboratoireRefuse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/laboratoire/v1/resultats (PRE-06) : le logiciel du laboratoire
 * dépose des résultats, par le MÊME service que le portail. Numéro du bon
 * et code de retrait (le patient est alors reçu), ou numéro seul pour un bon
 * déjà « en cours ». Idempotent par la clé « cle ». Réponses JSON sobres,
 * sans donnée de santé.
 */
class ApiResultatsController extends Controller
{
    public function __construct(private readonly DepotResultats $depots) {}

    public function deposer(Request $request): JsonResponse
    {
        $acteur = ActeurLaboratoire::jeton($request->attributes->get(JetonLaboratoireRequis::ATTRIBUT));

        if (! $request->isJson()) {
            return JetonLaboratoireRequis::json(['erreur' => 'format', 'message' => 'Envoyez du JSON (Content-Type: application/json).'], 415);
        }

        $numero = $request->input('numero_bon');
        $code = $request->input('code_retrait');
        $pdfBase64 = $request->input('pdf_base64');
        if (! is_string($numero) || ! preg_match('/^BE-\d{4}-\d{6}$/', $numero)) {
            return $this->invalide(['numero_bon' => ['Numéro de bon attendu, par exemple BE-2026-000123.']]);
        }
        if ($code !== null && (! is_string($code) || strlen($code) > 20)) {
            return $this->invalide(['code_retrait' => ['Code de retrait : une chaîne de chiffres.']]);
        }

        $pdf = null;
        if ($pdfBase64 !== null) {
            $pdf = is_string($pdfBase64) ? base64_decode($pdfBase64, true) : false;
            if ($pdf === false || $pdf === '') {
                return $this->invalide(['pdf_base64' => ['Compte rendu : un PDF encodé en base64.']]);
            }
        }

        try {
            $bon = $this->depots->bonDuLaboratoire($acteur, $numero, parNumero: true);
            if ($code !== null) {
                $bon = $this->depots->recevoir($acteur, $bon, $code);
            } elseif ($bon->statut === StatutBonExamen::Paye) {
                throw new LaboratoireRefuse('Ce bon n\'est pas encore « en cours » : joignez le code de retrait du patient.', LaboratoireRefuse::CODE_RETRAIT);
            }
            $resultat = $this->depots->deposer($acteur, $bon, $request->only(['valeurs', 'critique', 'cle', 'ligne_id']), $pdf);
        } catch (ValidationException $e) {
            return $this->invalide($e->errors());
        } catch (LaboratoireRefuse $refus) {
            $statut = match ($refus->raison) {
                LaboratoireRefuse::BON_INTROUVABLE => 404,
                LaboratoireRefuse::CODE_RETRAIT => 403,
                LaboratoireRefuse::TROP_D_ESSAIS => 429,
                default => 409,
            };

            return JetonLaboratoireRequis::json(['erreur' => $refus->raison, 'message' => $refus->getMessage()], $statut);
        }

        return JetonLaboratoireRequis::json([
            'statut' => $resultat->wasRecentlyCreated ? 'depose' : 'deja_recu',
            'resultat_id' => $resultat->id,
            'bon' => ['numero' => $bon->numero, 'statut' => $bon->fresh()->statut->value],
        ], $resultat->wasRecentlyCreated ? 201 : 200);
    }

    private function invalide(array $champs): JsonResponse
    {
        return JetonLaboratoireRequis::json(['erreur' => 'donnees_invalides', 'message' => 'Données invalides.', 'champs' => $champs], 422);
    }
}
