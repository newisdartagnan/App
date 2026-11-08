<?php

namespace App\Services\Dossier;

use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\Antecedent;
use App\Models\AvenantNote;
use App\Models\Constante;
use App\Models\DemandeRectification;
use App\Models\DossierPatient;
use App\Models\Medecin;
use App\Models\NoteConsultation;
use App\Models\Ordonnance;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\RendezVous\Affichage;
use App\Support\Montant;
use Carbon\CarbonImmutable;

/**
 * Lecture du dossier médical (DOS-01, DOS-03, DOS-04), pour le patient et
 * pour un médecin traitant ; le contrôle d'accès et le journal sont faits
 * par les appelants. Historique chronologique : consultations terminées,
 * notes SIGNÉES et leurs avenants (jamais un brouillon), ordonnances,
 * antécédents ajoutés ou retirés, et paiements pour le patient seulement.
 * Les notes et ordonnances (écrites par le lot de la note) se lisent
 * directement dans leurs tables.
 */
final class LectureDossier
{
    public function __construct(private readonly JournalAccesDossier $journal) {}

    /** Ce qui vaut aujourd'hui : groupe sanguin, éléments présents par catégorie, dernières constantes. */
    public function etat(User $patient): array
    {
        $dossier = DossierPatient::where('patient_id', $patient->id)->first();
        $actifs = Antecedent::where('patient_id', $patient->id)->whereNull('retire_le')->orderBy('created_at')->get();

        return [
            'groupeSanguin' => $dossier?->groupe_sanguin,
            'groupeSanguinParMedecin' => $dossier?->groupe_sanguin_par !== null && $dossier->groupe_sanguin_par !== $patient->id,
            'elements' => collect(DossierService::CATEGORIES)->map(fn (string $libelle, string $categorie) => [
                'categorie' => $categorie,
                'libelle' => $libelle,
                'elements' => $actifs->where('categorie', $categorie)->map(fn (Antecedent $a) => self::element($a))->values()->all(),
            ])->values()->all(),
            'constantes' => $this->dernieresConstantes($patient),
        ];
    }

    /** Pendant l'appel (TLC-05) : allergies d'abord, traitements, antécédents, dernières constantes, groupe sanguin. */
    public function resumePourAppel(User $patient): array
    {
        $etat = $this->etat($patient);
        $parCategorie = collect($etat['elements'])->keyBy('categorie');
        $libelles = fn (string $categorie) => collect($parCategorie[$categorie]['elements'])
            ->map(fn (array $e) => $e['precision'] ? "{$e['libelle']} ({$e['precision']})" : $e['libelle'])->all();

        return [
            'groupeSanguin' => $etat['groupeSanguin'],
            'allergies' => $libelles('allergie'),
            'traitements' => $libelles('traitement'),
            'antecedents' => [
                'medical' => $libelles('medical'),
                'chirurgical' => $libelles('chirurgical'),
                'familial' => $libelles('familial'),
            ],
            'constantes' => $etat['constantes'],
        ];
    }

    /** @return list<array{type: string, libelle: string, valeur: string, quand: string}> */
    public function dernieresConstantes(User $patient): array
    {
        $dernieres = Constante::where('patient_id', $patient->id)
            ->orderByDesc('mesure_le')->orderByDesc('created_at')
            ->get()->unique('type');

        return collect(DossierService::CONSTANTES)
            ->filter(fn ($regle, string $type) => $dernieres->firstWhere('type', $type) !== null)
            ->map(function (array $regle, string $type) use ($dernieres) {
                $c = $dernieres->firstWhere('type', $type);

                return [
                    'type' => $type,
                    'libelle' => $regle['libelle'],
                    'valeur' => self::valeurConstante($c, $regle['unite']),
                    'quand' => Affichage::dateHeure($c->mesure_le),
                ];
            })->values()->all();
    }

    /**
     * Historique chronologique, du plus récent au plus ancien.
     *
     * @return list<array{type: string, date: string, titre: string, details: list<string>, id: ?string, avenants?: list<array>}>
     */
    public function historique(User $patient, bool $pourLePatient): array
    {
        $evenements = collect();
        $medecins = Medecin::with('user:id,prenom,nom')->get()->keyBy('id');
        $nomMedecin = fn (?string $id) => $id && isset($medecins[$id]) ? Affichage::medecin($medecins[$id]) : 'Médecin';

        RendezVous::with('typeConsultation:id,nom')->where('patient_id', $patient->id)
            ->where('statut', StatutRendezVous::Termine->value)->get()
            ->each(fn (RendezVous $r) => $evenements->push([
                'type' => 'consultation', 'instant' => $r->debut, 'id' => $r->id,
                'titre' => 'Consultation avec '.$nomMedecin($r->medecin_id),
                'details' => [$r->typeConsultation?->nom],
            ]));

        // Notes SIGNÉES seulement : un brouillon n'existe pas pour le dossier.
        NoteConsultation::with(['avenants.auteur:id,prenom,nom'])->where('patient_id', $patient->id)->whereNotNull('signee_le')->get()
            ->each(fn (NoteConsultation $n) => $evenements->push([
                'type' => 'note', 'instant' => $n->signee_le, 'id' => $n->id,
                'titre' => 'Note de consultation — '.$nomMedecin($n->medecin_id),
                'details' => array_values(array_filter([
                    $n->motif ? 'Motif : '.$n->motif : null,
                    $n->examen ? 'Examen : '.$n->examen : null,
                    $n->hypotheses ? 'Hypothèses : '.$n->hypotheses : null,
                    $n->diagnostic_libelle ? 'Diagnostic : '.trim(($n->diagnostic_code ? $n->diagnostic_code.' — ' : '').$n->diagnostic_libelle) : null,
                    $n->conduite ? 'Conduite à tenir : '.$n->conduite : null,
                ])),
                'avenants' => $n->avenants->map(fn (AvenantNote $a) => [
                    'date' => Affichage::dateHeure($a->signe_le),
                    'auteur' => trim('Dr '.$a->auteur?->prenom.' '.mb_strtoupper((string) $a->auteur?->nom)),
                    'contenu' => $a->contenu,
                ])->all(),
            ]));

        Ordonnance::with('lignes')->where('patient_id', $patient->id)->get()
            ->each(fn (Ordonnance $o) => $evenements->push([
                'type' => 'ordonnance', 'instant' => $o->signee_le, 'id' => $o->id,
                'titre' => 'Ordonnance '.$o->numero.' — '.$nomMedecin($o->medecin_id),
                'details' => $o->lignes->map(fn ($l) => "{$l->medicament} {$l->dosage}, {$l->voie} : {$l->posologie}, {$l->duree}".($l->consignes ? " ({$l->consignes})" : ''))
                    ->push($o->consignes ? 'Consignes : '.$o->consignes : null)->filter()->values()->all(),
            ]));

        $auteurs = User::whereIn('id', Antecedent::where('patient_id', $patient->id)->select('saisi_par')
            ->union(Antecedent::where('patient_id', $patient->id)->whereNotNull('retire_par')->select('retire_par')))
            ->get(['id', 'prenom', 'nom'])->keyBy('id');
        $qui = fn (?string $id) => $id === $patient->id ? 'vous-même' : ($id && isset($auteurs[$id]) ? trim('Dr '.$auteurs[$id]->prenom.' '.mb_strtoupper((string) $auteurs[$id]->nom)) : 'un médecin');
        $quiPourMedecin = fn (?string $id) => $id === $patient->id ? 'le patient' : $qui($id);
        $par = $pourLePatient ? $qui : $quiPourMedecin;

        Antecedent::where('patient_id', $patient->id)->get()->each(function (Antecedent $a) use ($evenements, $par) {
            $categorie = DossierService::CATEGORIES[$a->categorie];
            $evenements->push([
                'type' => 'antecedent_ajoute', 'instant' => CarbonImmutable::instance($a->created_at), 'id' => $a->id,
                'titre' => "{$categorie} ajouté(e) : {$a->libelle}",
                'details' => array_values(array_filter([$a->precision, 'Saisi par '.$par($a->saisi_par)])),
            ]);
            if ($a->retire_le) {
                $evenements->push([
                    'type' => 'antecedent_retire', 'instant' => $a->retire_le, 'id' => $a->id,
                    'titre' => "{$categorie} retiré(e) : {$a->libelle}",
                    'details' => ['Retiré par '.$par($a->retire_par)],
                ]);
            }
        });

        if ($pourLePatient) {
            Paiement::with('facture')->where('patient_id', $patient->id)->where('statut', StatutPaiement::Reussi->value)->get()
                ->each(fn (Paiement $p) => $evenements->push([
                    'type' => 'paiement', 'instant' => $p->confirme_le, 'id' => $p->id,
                    'titre' => 'Paiement de '.Montant::afficher((string) $p->montant, $p->devise),
                    'details' => array_values(array_filter([$p->moyen->libelle(), $p->facture ? 'Reçu '.$p->facture->numero : null])),
                ]));
        }

        return $evenements
            ->filter(fn (array $e) => $e['instant'] !== null)
            // À instant égal, le dernier écrit d'abord (un retrait après son ajout) : tri stable sur l'ordre inversé.
            ->reverse()
            ->sortByDesc(fn (array $e) => $e['instant']->getTimestamp())
            ->map(fn (array $e) => ['date' => Affichage::dateHeure($e['instant'])] + array_diff_key($e, ['instant' => true]))
            ->values()->all();
    }

    /** @return list<array{id: string, demande: string, statut: string, reponse: ?string, noteId: ?string, quand: string, traiteeLe: ?string}> */
    public function rectifications(User $patient): array
    {
        return DemandeRectification::where('patient_id', $patient->id)->orderByDesc('created_at')->get()
            ->map(fn (DemandeRectification $d) => [
                'id' => $d->id,
                'demande' => $d->demande,
                'statut' => $d->statut,
                'statutLibelle' => ['ouverte' => 'En attente de réponse', 'acceptee' => 'Acceptée', 'refusee' => 'Refusée'][$d->statut],
                'reponse' => $d->reponse,
                'noteId' => $d->note_id,
                'quand' => Affichage::dateHeure($d->created_at),
                'traiteeLe' => $d->traitee_le ? Affichage::dateHeure($d->traitee_le) : null,
            ])->all();
    }

    /** Notes signées du patient, pour lier une réponse de rectification à la note corrigée par avenant. */
    public function notesSignees(User $patient): array
    {
        return NoteConsultation::where('patient_id', $patient->id)->whereNotNull('signee_le')->orderByDesc('signee_le')->get()
            ->map(fn (NoteConsultation $n) => [
                'id' => $n->id,
                'rendezVousId' => $n->rendez_vous_id,
                'medecinId' => $n->medecin_id,
                'libelle' => 'Note du '.Affichage::dateHeure($n->signee_le),
            ])->all();
    }

    public function accesAuDossier(User $patient): array
    {
        return $this->journal->pourLePatient($patient)
            ->map(fn (array $e) => ['qui' => $e['qui'], 'quoi' => $e['quoi'], 'quand' => Affichage::dateHeure($e['quand'])])
            ->all();
    }

    private static function element(Antecedent $a): array
    {
        return [
            'id' => $a->id,
            'libelle' => $a->libelle,
            'precision' => $a->precision,
            'source' => $a->source,
            'ajouteLe' => Affichage::jour($a->created_at),
        ];
    }

    public static function valeurConstante(Constante $c, string $unite): string
    {
        $nombre = fn (string $v) => rtrim(rtrim(str_replace('.', ',', $v), '0'), ',');

        return $c->type === 'tension'
            ? $nombre((string) $c->valeur).'/'.$nombre((string) $c->valeur2).' '.$unite
            : $nombre((string) $c->valeur).' '.$unite;
    }
}
