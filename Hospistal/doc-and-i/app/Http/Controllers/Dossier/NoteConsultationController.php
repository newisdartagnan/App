<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Teleconsultation\ConsultationJsonController;
use App\Models\NoteConsultation;
use App\Services\Dossier\NoteService;
use App\Services\Teleconsultation\AccesRefuse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** DOS-02, RG-11 : note de consultation du médecin de CE rendez-vous (JSON, jamais en cache). */
class NoteConsultationController extends ConsultationJsonController
{
    public function __construct(private readonly NoteService $notes) {}

    public function etat(Request $request, string $rendezVous): Response
    {
        return $this->repondre(fn () => $this->notes->etat($request->user(), $this->rendezVous($rendezVous)));
    }

    public function enregistrer(Request $request, string $rendezVous): Response
    {
        return $this->repondre(fn () => $this->notes->enregistrer($request->user(), $this->rendezVous($rendezVous), $request->only(NoteConsultation::CHAMPS)));
    }

    public function signer(Request $request, string $rendezVous): Response
    {
        return $this->repondre(fn () => $this->notes->signer(
            $request->user(),
            $this->rendezVous($rendezVous),
            $request->has('champs') ? (array) $request->input('champs') : null,
        ) + ['message' => 'Note signée.']);
    }

    public function avenant(Request $request, string $rendezVous): Response
    {
        return $this->repondre(function () use ($request, $rendezVous) {
            $rdv = $this->rendezVous($rendezVous);
            $note = NoteConsultation::where('rendez_vous_id', $rdv->id)->first()
                ?? throw new AccesRefuse('Aucune note signée pour ce rendez-vous.');
            $contenu = $request->input('contenu');
            $cle = $request->input('cle');
            $this->notes->avenant($request->user(), $note, is_string($contenu) ? $contenu : '', is_string($cle) ? $cle : null);

            return $this->notes->etat($request->user(), $rdv) + ['message' => 'Avenant ajouté.'];
        }, 201);
    }
}
