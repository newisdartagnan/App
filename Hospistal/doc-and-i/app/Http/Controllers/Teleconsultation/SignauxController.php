<?php

namespace App\Http\Controllers\Teleconsultation;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\RendezVous;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\AccesRefuse;
use App\Services\Teleconsultation\Signalisation;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signalisation de l'appel (TLC-01), commune au patient et au médecin : le
 * rôle de l'utilisateur dans CE rendez-vous est recalculé à chaque requête
 * (Signalisation → AccesConsultation). Un tiers reçoit 403, sans rien lire.
 */
class SignauxController extends Controller
{
    public function __construct(private readonly Signalisation $signalisation) {}

    public function index(Request $request, RendezVous $rendezVous): JsonResponse
    {
        $apres = filter_var($request->query('apres', 0), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $this->accesVerifie(function (Consultation $c) use ($request, $rendezVous, $apres) {
            // Salle close normalement (fin, absence) : un participant l'apprend par une
            // réponse ordinaire et raccroche, sans requête refusée. Un tiers reste refusé.
            if (! $c->statut->estOuverte() && app(AccesConsultation::class)->role($request->user(), $rendezVous) !== null) {
                return response()->json(['signaux' => [], 'close' => 'Consultation close ('.mb_strtolower($c->statut->libelle()).').']);
            }

            return response()->json($this->signalisation->recevoir($c, $request->user(), $apres === false ? 0 : $apres));
        }, $rendezVous);
    }

    public function store(Request $request, RendezVous $rendezVous): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', 'string', 'max:20'],
            'charge' => ['present', 'nullable'],
            'cle' => ['nullable', 'string', 'max:36'],
        ]);

        return $this->accesVerifie(function (Consultation $c) use ($request, $donnees) {
            $signal = $this->signalisation->envoyer($c, $request->user(), $donnees['type'], $donnees['charge'], $donnees['cle'] ?? null);

            return response()->json(['id' => $signal->id], $signal->wasRecentlyCreated ? 201 : 200);
        }, $rendezVous);
    }

    /** Accès vérifié ; réponse jamais mise en cache (revue de sécurité), refus compris. */
    private function accesVerifie(\Closure $action, RendezVous $rendezVous): JsonResponse
    {
        try {
            $consultation = Consultation::where('rendez_vous_id', $rendezVous->id)->first()
                ?? throw new AccesRefuse('La salle de consultation n\'est pas encore ouverte.');

            $reponse = $action($consultation);
        } catch (AccesRefuse $refus) {
            $reponse = response()->json(['message' => $refus->getMessage()], 403);
        } catch (ThrottleRequestsException $trop) {
            // Bornes par type et par consultation (Signalisation::verifierBornes).
            $reponse = response()->json(['message' => $trop->getMessage()], 429);
        }

        return self::sansCache($reponse);
    }

    public static function sansCache(JsonResponse $reponse): JsonResponse
    {
        $reponse->headers->set('Cache-Control', 'no-store, private, max-age=0');

        return $reponse;
    }
}
