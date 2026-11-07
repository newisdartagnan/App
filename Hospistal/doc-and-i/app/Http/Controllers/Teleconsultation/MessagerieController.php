<?php

namespace App\Http\Controllers\Teleconsultation;

use App\Services\Teleconsultation\Messagerie;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** TLC-03 : messagerie de la consultation, pour le patient et pour son médecin. */
class MessagerieController extends ConsultationJsonController
{
    public function __construct(private readonly Messagerie $messagerie) {}

    public function lister(Request $request, string $rendezVous): Response
    {
        $valides = $request->validate(['apres' => ['nullable', 'uuid']]);

        return $this->repondre(fn () => ['messages' => $this->messagerie->lister($request->user(), $this->rendezVous($rendezVous), $valides['apres'] ?? null)]);
    }

    public function envoyer(Request $request, string $rendezVous): Response
    {
        $kilo = intdiv((int) config('doc-and-i.teleconsultation.photo_octets_max'), 1024);
        $valides = $request->validate([
            'texte' => ['nullable', 'required_without:photo', 'string', 'max:'.Messagerie::TEXTE_MAX],
            // Type lu dans le contenu (finfo), revérifié par le service (image décodable).
            'photo' => ['nullable', 'required_without:texte', 'file', 'max:'.$kilo, 'mimetypes:'.implode(',', Messagerie::TYPES_PHOTO)],
        ], [
            'photo.mimetypes' => 'Seules les photos JPEG, PNG ou WebP sont acceptées.',
            'photo.max' => 'Photo trop lourde : '.intdiv($kilo, 1024).' Mo au plus.',
        ]);
        $rdv = $this->rendezVous($rendezVous);

        return $this->repondre(fn () => ['message' => isset($valides['photo'])
            ? $this->messagerie->envoyerPhoto($request->user(), $rdv, $valides['photo'])
            : $this->messagerie->envoyerTexte($request->user(), $rdv, $valides['texte'])], 201);
    }

    public function photo(Request $request, string $rendezVous, string $message): Response
    {
        return $this->repondre(fn () => $this->messagerie->photo($request->user(), $this->rendezVous($rendezVous), $message));
    }
}
