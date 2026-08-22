<?php

namespace App\Http\Controllers;

use App\Models\DonneurSang;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * La recherche, depuis n'importe quel écran.
 *
 * On ne pouvait chercher un patient que depuis l'écran Patients : il fallait
 * donc quitter ce qu'on faisait, chercher, revenir. Une barre dans l'en-tête
 * fait gagner un geste à chaque fois, toute la journée.
 *
 * Elle cherche aussi ce qu'on a en main sans savoir à qui c'est : un numéro
 * de facture griffonné, un bon d'examen, une poche de sang. Quand le terme
 * désigne une seule chose de façon certaine, on y va directement plutôt que
 * d'afficher une liste d'un élément.
 */
class RechercheController extends Controller
{
    /** En deçà, tout ressemble à tout. */
    private const LONGUEUR_MINIMALE = 2;

    private const PAR_FAMILLE = 8;

    public function index(Request $request): View|RedirectResponse
    {
        $terme = trim((string) $request->query('q', ''));

        if (mb_strlen($terme) < self::LONGUEUR_MINIMALE) {
            return view('recherche.resultats', [
                'terme' => $terme,
                'familles' => collect(),
                'total' => 0,
                'tropCourt' => $terme !== '',
            ]);
        }

        // Un numéro reconnu sans ambiguïté mène droit à sa fiche.
        if ($direct = $this->raccourci($terme)) {
            return redirect($direct);
        }

        $familles = $this->chercher($terme);

        return view('recherche.resultats', [
            'terme' => $terme,
            'familles' => $familles->filter(fn ($f) => $f['resultats']->isNotEmpty()),
            'total' => $familles->sum(fn ($f) => $f['resultats']->count()),
            'tropCourt' => false,
        ]);
    }

    /**
     * Le terme désigne-t-il une seule chose, de façon certaine ?
     *
     * Seuls les numéros comptent : un nom peut être porté par deux personnes,
     * un numéro de dossier non.
     */
    private function raccourci(string $terme): ?string
    {
        $exact = mb_strtoupper($terme);

        if ($patient = Patient::whereRaw('UPPER(dossier_number) = ?', [$exact])->first()) {
            return route('patients.show', $patient);
        }

        if ($facture = Facture::whereRaw('UPPER(numero_facture) = ?', [$exact])->first()) {
            return route('caisse.show', $facture);
        }

        if ($examen = ExamenLaboratoire::whereRaw('UPPER(numero_bon) = ?', [$exact])->first()) {
            return route('labo.show', $examen);
        }

        return null;
    }

    /**
     * Ce que le terme trouve, famille par famille.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function chercher(string $terme): Collection
    {
        $motif = '%'.mb_strtolower($terme).'%';
        $familles = collect();

        $familles->push([
            'titre' => 'Patients',
            'icone' => '👤',
            'resultats' => Patient::query()
                ->where(fn ($q) => $q
                    ->whereRaw('LOWER(nom) LIKE ?', [$motif])
                    ->orWhereRaw('LOWER(prenom) LIKE ?', [$motif])
                    ->orWhereRaw('LOWER(COALESCE(postnom, \'\')) LIKE ?', [$motif])
                    ->orWhereRaw('LOWER(dossier_number) LIKE ?', [$motif])
                    // Le téléphone est chiffré en base : il ne se compare
                    // que par son empreinte, et donc en entier.
                    ->when(Patient::empreinteTelephone($terme),
                        fn ($q, $empreinte) => $q->orWhere('telephone_index', $empreinte)))
                ->where(fn ($q) => $q->where('merge_status', '!=', 'merged')->orWhereNull('merge_status'))
                ->orderBy('nom')
                ->limit(self::PAR_FAMILLE)
                ->get()
                ->map(fn (Patient $p) => [
                    'titre' => $p->nom_complet,
                    'detail' => trim($p->dossier_number
                        .($p->date_naissance ? ' · né(e) le '.$p->date_naissance->format('d/m/Y') : '')
                        .($p->telephone ? ' · '.$p->telephone : '')),
                    'url' => route('patients.show', $p),
                ]),
        ]);

        $familles->push([
            'titre' => 'Séjours en cours',
            'icone' => '🛏️',
            'resultats' => Visit::query()
                ->with(['patient', 'service'])
                ->where('statut', 'en_cours')
                ->whereHas('patient', fn ($q) => $q
                    ->whereRaw('LOWER(nom) LIKE ?', [$motif])
                    ->orWhereRaw('LOWER(prenom) LIKE ?', [$motif])
                    ->orWhereRaw('LOWER(dossier_number) LIKE ?', [$motif]))
                ->orderByDesc('date_entree')
                ->limit(self::PAR_FAMILLE)
                ->get()
                ->map(fn (Visit $v) => [
                    'titre' => $v->patient?->nom_complet ?? 'Patient inconnu',
                    'detail' => ucfirst(str_replace('_', ' ', $v->type))
                        .($v->service ? ' · '.$v->service->nom : '')
                        .' · depuis le '.$v->date_entree->format('d/m/Y'),
                    'url' => route('visites.show', $v),
                ]),
        ]);

        $familles->push([
            'titre' => 'Factures',
            'icone' => '🧾',
            'resultats' => Facture::query()
                ->with('patient')
                ->where(fn ($q) => $q
                    ->whereRaw('LOWER(numero_facture) LIKE ?', [$motif])
                    ->orWhereHas('patient', fn ($p) => $p
                        ->whereRaw('LOWER(nom) LIKE ?', [$motif])
                        ->orWhereRaw('LOWER(dossier_number) LIKE ?', [$motif])))
                ->orderByDesc('date_facture')
                ->limit(self::PAR_FAMILLE)
                ->get()
                ->map(fn (Facture $f) => [
                    'titre' => $f->numero_facture,
                    'detail' => ($f->patient?->nom_complet ?? 'Patient inconnu')
                        .' · '.number_format((float) $f->total_ttc, 0, ',', ' ').' CDF'
                        .' · '.ucfirst(str_replace('_', ' ', $f->statut)),
                    'url' => route('caisse.show', $f),
                ]),
        ]);

        $familles->push([
            'titre' => 'Examens et imagerie',
            'icone' => '🔬',
            'resultats' => ExamenLaboratoire::query()
                ->with('patient')
                ->where(fn ($q) => $q
                    ->whereRaw('LOWER(COALESCE(numero_bon, \'\')) LIKE ?', [$motif])
                    ->orWhereHas('patient', fn ($p) => $p
                        ->whereRaw('LOWER(nom) LIKE ?', [$motif])
                        ->orWhereRaw('LOWER(dossier_number) LIKE ?', [$motif])))
                ->orderByDesc('date_prescription')
                ->limit(self::PAR_FAMILLE)
                ->get()
                ->map(fn (ExamenLaboratoire $e) => [
                    'titre' => $e->numero_bon ?? 'Bon sans numéro',
                    'detail' => ($e->patient?->nom_complet ?? 'Patient inconnu')
                        .' · '.($e->domaine === 'imagerie' ? 'Imagerie' : 'Laboratoire')
                        .' · '.ucfirst(str_replace('_', ' ', $e->statut))
                        .' · '.$e->date_prescription?->format('d/m/Y'),
                    'url' => route('labo.show', $e),
                ]),
        ]);

        // La banque de sang n'est ouverte qu'à ceux qui y travaillent : la
        // recherche ne doit pas devenir la porte de service.
        if (auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'laborantin', 'medecin', 'infirmier_chef'])) {
            $familles->push([
                'titre' => 'Banque de sang',
                'icone' => '🩸',
                'resultats' => PocheSang::query()
                    ->whereRaw('LOWER(numero) LIKE ?', [$motif])
                    ->limit(self::PAR_FAMILLE)
                    ->get()
                    ->map(fn (PocheSang $p) => [
                        'titre' => 'Poche '.$p->numero,
                        'detail' => $p->groupe_sanguin.' · '.$p->libelleProduit()
                            .' · '.(PocheSang::STATUTS[$p->statut] ?? $p->statut),
                        'url' => route('banque-sang.index', ['groupe' => $p->groupe_sanguin]),
                    ])
                    ->concat(DonneurSang::query()
                        ->where(fn ($q) => $q
                            ->whereRaw('LOWER(code) LIKE ?', [$motif])
                            ->orWhereRaw('LOWER(nom) LIKE ?', [$motif])
                            ->orWhereRaw('LOWER(COALESCE(telephone, \'\')) LIKE ?', [$motif]))
                        ->limit(self::PAR_FAMILLE)
                        ->get()
                        ->map(fn (DonneurSang $d) => [
                            'titre' => 'Donneur '.$d->nomComplet(),
                            'detail' => $d->code.' · '.$d->groupe_sanguin
                                .($d->telephone ? ' · '.$d->telephone : ''),
                            'url' => route('banque-sang.donneurs', ['recherche' => $d->code]),
                        ])),
            ]);
        }

        if (auth()->user()?->hasAnyRole(['super_admin', 'directeur'])) {
            $familles->push([
                'titre' => 'Comptes du personnel',
                'icone' => '🧑‍⚕️',
                'resultats' => User::query()
                    ->where(fn ($q) => $q
                        ->whereRaw('LOWER(nom) LIKE ?', [$motif])
                        ->orWhereRaw('LOWER(COALESCE(prenom, \'\')) LIKE ?', [$motif])
                        ->orWhereRaw('LOWER(COALESCE(matricule, \'\')) LIKE ?', [$motif]))
                    ->orderBy('nom')
                    ->limit(self::PAR_FAMILLE)
                    ->get()
                    ->map(fn (User $u) => [
                        'titre' => $u->nom_complet,
                        'detail' => trim(($u->matricule ? $u->matricule.' · ' : '').$u->libelleRoles()),
                        'url' => route('parcours.profil', $u),
                    ]),
            ]);
        }

        return $familles;
    }
}
