<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Avoir;
use App\Models\Facture;
use App\Models\LigneFactureConvention;
use App\Services\AvoirService;
use App\Services\DeviseService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

/**
 * Avoirs et journal des pièces de caisse.
 *
 * Établir un avoir est réservé à ceux qui portent le droit facture.annuler :
 * le chef de facturation, la direction, l'administrateur. Le caissier voit
 * les avoirs — il doit rendre le crédit qu'ils laissent — mais n'en établit
 * pas : la main qui encaisse n'est pas celle qui annule.
 */
class AvoirController extends Controller
{
    public function __construct(private readonly AvoirService $avoirs) {}

    /** Registre des avoirs du mois, avec leurs motifs. */
    public function index(Request $request): View
    {
        $this->autoriserLecture();

        $mois = $this->mois($request->query('mois'));

        $avoirs = Avoir::with(['facture', 'patient', 'auteur'])
            ->whereBetween('date_avoir', [$mois->copy()->startOfMonth(), $mois->copy()->endOfMonth()])
            ->orderByDesc('date_avoir')
            ->get();

        $parMotif = $avoirs->groupBy('motif_categorie')
            ->map(fn ($groupe, $motif) => [
                'libelle' => Avoir::MOTIFS[$motif] ?? Avoir::MOTIFS_REMISE[$motif] ?? $motif,
                'nombre' => $groupe->count(),
                'montant' => (float) $groupe->sum('montant_total'),
            ])
            ->sortByDesc('montant');

        // Qui renonce à la recette, et pour combien : la direction relit ce
        // tableau chaque mois.
        $remisesParAuteur = $avoirs->where('type', 'remise')
            ->groupBy('emis_par')
            ->map(fn ($groupe) => [
                'auteur' => $groupe->first()->auteur?->nom_complet ?? '—',
                'nombre' => $groupe->count(),
                'montant' => (float) $groupe->sum('montant_total'),
            ])
            ->sortByDesc('montant');

        return view('avoirs.index', compact('avoirs', 'parMotif', 'mois', 'remisesParAuteur'));
    }

    public function create(Facture $facture): View|RedirectResponse
    {
        $this->autoriserAnnulation();

        if ($facture->estAnnulee()) {
            return redirect()->route('caisse.show', $facture)
                ->with('info', 'Cette facture est déjà annulée.');
        }

        $facture->load(['patient', 'lignes', 'avoirs', 'paiements', 'bonsSortie']);

        return view('avoirs.create', [
            'facture' => $facture,
            'lignes' => $this->avoirs->lignesCreditables($facture),
            // L'assureur a peut-être déjà reçu cette facture dans un
            // bordereau : l'avoir devra lui être signalé.
            'bordereau' => LigneFactureConvention::where('facture_id', $facture->id)
                ->whereHas('factureConvention', fn ($q) => $q->where('statut', '!=', 'annulee'))
                ->with('factureConvention')
                ->first()?->factureConvention,
            'retenu' => $facture->montantRetenu(),
            'bonsUtilises' => $facture->bonsSortie->where('statut', 'utilise'),
        ]);
    }

    public function store(Request $request, Facture $facture): RedirectResponse
    {
        $this->autoriserAnnulation();

        $donnees = $request->validate([
            'portee' => 'required|in:totale,partielle',
            'motif_categorie' => 'required|in:'.implode(',', array_keys(Avoir::MOTIFS)),
            'motif' => 'required|string|min:5|max:1000',
            'montants' => 'nullable|array',
            'montants.*' => 'nullable|numeric|min:0',
            'liberer' => 'nullable|boolean',
        ], [
            'motif.required' => 'Un avoir se motive : écrivez pourquoi la facture est reprise.',
            'motif.min' => 'Le motif doit dire ce qui s\'est passé, en quelques mots au moins.',
            'motif_categorie.required' => 'Choisissez la nature de la correction.',
        ]);

        try {
            $avoir = $this->avoirs->emettre(
                $facture,
                $donnees['motif_categorie'],
                $donnees['motif'],
                $donnees['portee'] === 'totale' ? null : array_filter($donnees['montants'] ?? [], fn ($m) => $m !== null && $m !== ''),
                (bool) ($donnees['liberer'] ?? false),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = "Avoir {$avoir->numero} établi pour {$avoir->formater((float) $avoir->montant_total)}.";

        if ((float) $avoir->credit_patient > 0) {
            $message .= ' Le patient dispose d\'un crédit de '.$avoir->formater((float) $avoir->credit_patient)
                .' : à imputer sur sa prochaine facture ou à lui rendre.';
        }

        if ($avoir->peutEtreRefacture()) {
            $message .= ' Les prestations sont libérées : elles peuvent être refacturées.';
        }

        return redirect()->route('avoirs.show', $avoir)->with('success', $message);
    }

    public function show(Avoir $avoir): View
    {
        $this->autoriserLecture(caisse: true);

        $avoir->load([
            'facture.patient', 'facture.lignes', 'lignes', 'auteur', 'credit.imputations.facture',
            'factureConvention', 'facturesDeRemplacement',
        ]);

        return view('avoirs.show', [
            'avoir' => $avoir,
            'peutAnnuler' => (bool) auth()->user()?->can('facture.annuler'),
            'peutRendre' => (bool) auth()->user()?->can('paiement.receive'),
        ]);
    }

    public function refacturer(Avoir $avoir): RedirectResponse
    {
        $this->autoriserAnnulation();

        try {
            $factures = $this->avoirs->refacturer($avoir);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($factures->isEmpty()) {
            return redirect()->route('avoirs.show', $avoir)
                ->with('info', 'Rien n\'était plus à refacturer : les prestations ont déjà été reprises.');
        }

        if ($factures->count() === 1) {
            return redirect()->route('caisse.show', $factures->first())
                ->with('success', 'Facture '.$factures->first()->numero_facture.' émise en remplacement.');
        }

        return redirect()->route('avoirs.show', $avoir)
            ->with('success', $factures->count().' factures émises en remplacement : '
                .$factures->pluck('numero_facture')->implode(', ').'.');
    }

    /**
     * Accorde une remise sur la part du patient — chef de facturation dans
     * la limite fixée par la direction, direction sans limite.
     */
    public function remise(Request $request, Facture $facture): RedirectResponse
    {
        abort_unless(auth()->user()?->can('facture.remise'), 403,
            'Une remise est une recette à laquelle l\'hôpital renonce : seuls le chef de facturation et la direction l\'accordent.');

        $donnees = $request->validate([
            'mode' => 'required|in:taux,montant',
            'valeur' => 'required|numeric|min:0.01',
            'motif_categorie' => 'required|in:'.implode(',', array_keys(Avoir::MOTIFS_REMISE)),
            'motif' => 'required|string|min:5|max:1000',
            'lignes' => 'nullable|array',
            'lignes.*' => 'uuid',
        ], [
            'valeur.required' => 'Indiquez le pourcentage ou le montant de la remise.',
            'valeur.min' => 'La remise doit être positive.',
            'motif.required' => 'Une remise se motive : écrivez pourquoi l\'hôpital y renonce.',
            'motif.min' => 'Le motif doit dire pourquoi, en quelques mots au moins.',
        ]);

        try {
            $avoir = $this->avoirs->accorderRemise(
                $facture,
                $donnees['motif_categorie'],
                $donnees['motif'],
                taux: $donnees['mode'] === 'taux' ? (float) $donnees['valeur'] : null,
                montant: $donnees['mode'] === 'montant' ? (float) $donnees['valeur'] : null,
                ligneIds: $donnees['lignes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $facture->refresh();
        $message = $avoir->libelleCourt().' accordée ('.$avoir->numero.') : '
            .$avoir->formater((float) $avoir->montant_total).' de moins pour le patient.';

        $message .= (float) $avoir->credit_patient > 0
            ? ' Il avait déjà payé : '.$avoir->formater((float) $avoir->credit_patient).' lui reviennent en crédit.'
            : ($facture->soldeRestant() > 0
                ? ' Reste à encaisser : '.$facture->formater($facture->soldeRestant()).'.'
                : ' La facture est soldée.');

        return redirect()->route('caisse.show', $facture)->with('success', $message);
    }

    /**
     * Le caissier qui juge une remise justifiée la demande : le chef de
     * facturation et la direction en sont prévenus et décident.
     */
    public function demanderRemise(Request $request, Facture $facture): RedirectResponse
    {
        $donnees = $request->validate([
            'demande' => 'required|string|min:5|max:500',
        ], [
            'demande.required' => 'Écrivez ce que le patient demande et pourquoi.',
            'demande.min' => 'Écrivez ce que le patient demande et pourquoi.',
        ]);

        $facture->loadMissing('patient');
        $notifications = app(NotificationService::class);

        foreach (['chef_facturation', 'directeur'] as $groupe) {
            $notifications->envoyer(
                service: 'caisse',
                type: 'demande_remise',
                titre: 'Remise demandée — '.$facture->numero_facture,
                message: ($facture->patient?->nom_complet ?? 'Patient').' : '.$donnees['demande']
                    .' (demande de '.auth()->user()->nom_complet.')',
                referenceType: 'facture',
                referenceId: $facture->id,
                codeReference: $facture->numero_facture,
                groupeDestinataire: $groupe,
                patientId: null,
            );
        }

        return back()->with('success', 'Demande transmise au chef de facturation et à la direction : ils décideront de la remise.');
    }

    /** Le caissier rend au patient, en espèces, le crédit laissé par l'avoir. */
    public function rembourser(Request $request, Avoir $avoir): RedirectResponse
    {
        abort_unless(auth()->user()?->can('paiement.receive'), 403,
            'Rendre de l\'argent est un geste de caisse.');

        $request->validate(['reference' => 'nullable|string|max:200']);

        try {
            $rendu = $this->avoirs->rembourserCredit($avoir, $request->input('reference'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('avoirs.show', $avoir)->with('success',
            app(DeviseService::class)->formater($rendu, $avoir->credit->devise).' rendus au patient.');
    }

    /**
     * Journal d'une facture : chaque écriture qui l'a touchée — émission,
     * tiers payant, acomptes, encaissements, bons, avoirs — avec son auteur,
     * son heure et son poste.
     */
    public function journal(Facture $facture): View
    {
        $this->autoriserLecture();

        $entrees = AuditLog::with('user')
            ->where('facture_id', $facture->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $log) => [
                'log' => $log,
                'quoi' => self::LIBELLES_TABLES[$log->table_name] ?? $log->table_name,
                'geste' => match (substr($log->action, strrpos($log->action, '.') + 1)) {
                    'create' => 'création',
                    'update' => 'modification',
                    'delete' => 'suppression',
                    default => $log->action,
                },
                'details' => $this->details($log),
            ]);

        return view('factures.journal', compact('facture', 'entrees'));
    }

    // ══════════════════════════════════════════════════════════════

    private const LIBELLES_TABLES = [
        'factures' => 'Facture',
        'lignes_facture' => 'Ligne de facture',
        'facture_tiers_payant' => 'Part assureur',
        'paiements' => 'Encaissement',
        'imputations_acompte' => 'Acompte imputé',
        'cautions' => 'Crédit patient',
        'avoirs' => 'Avoir',
        'lignes_avoir' => 'Ligne d\'avoir',
        'bons_sortie' => 'Bon de sortie',
        'lignes_facture_convention' => 'Bordereau assureur',
    ];

    /** Champs qui disent quelque chose au contrôleur de caisse. */
    private const CHAMPS_LISIBLES = [
        'numero_facture' => 'n°', 'numero' => 'n°', 'statut' => 'statut',
        'libelle' => 'libellé', 'quantite' => 'qté', 'total_ligne' => 'total',
        'total_ttc' => 'total', 'patient_part' => 'part patient', 'assurance_part' => 'part assurance',
        'acompte_impute' => 'acompte imputé', 'montant' => 'montant', 'devise' => 'devise',
        'mode_paiement' => 'mode', 'reference_paiement' => 'réf.', 'recu_numero' => 'reçu',
        'part_assurance' => 'part assurance', 'part_patient' => 'part patient', 'taux_applique' => 'taux',
        'montant_total' => 'montant', 'montant_patient' => 'part patient', 'montant_assurance' => 'part assurance',
        'credit_patient' => 'crédit patient', 'motif' => 'motif', 'type' => 'type',
        'montant_impute' => 'imputé', 'montant_rembourse' => 'rendu', 'avoir_origine_id' => 'remplace',
        'montant_impute_cdf' => 'imputé (contre-valeur CDF)', 'montant_rembourse_cdf' => 'rendu (contre-valeur CDF)',
        'caution_id' => 'crédit', 'facture_convention_id' => 'bordereau', 'refacture_le' => 'refacturé le',
    ];

    /** Les codes enregistrés, tels qu'on les dit au guichet. */
    private const VALEURS_LISIBLES = [
        'brouillon' => 'brouillon', 'emise' => 'émise', 'partiellement_payee' => 'partiellement payée',
        'payee' => 'payée', 'annulee' => 'annulée',
        'versee' => 'versé', 'soldee' => 'entièrement imputé', 'remboursee_partiel' => 'rendu en partie',
        'remboursee_total' => 'rendu',
        'emis' => 'émis', 'utilise' => 'utilisé', 'expire' => 'expiré', 'annule' => 'annulé',
        'especes' => 'espèces', 'mobile_money' => 'mobile money', 'virement' => 'virement', 'cheque' => 'chèque',
        'annulation' => 'annulation totale', 'partiel' => 'avoir partiel', 'remise' => 'remise',
    ];

    /** @return array<int, string> */
    private function details(AuditLog $log): array
    {
        $nouveau = $log->new_values ?? [];
        $ancien = $log->old_values ?? [];
        $lignes = [];

        foreach (self::CHAMPS_LISIBLES as $champ => $libelle) {
            if (! array_key_exists($champ, $nouveau)) {
                continue;
            }

            $valeur = $this->valeur($nouveau[$champ]);

            $lignes[] = array_key_exists($champ, $ancien)
                ? "{$libelle} : {$this->valeur($ancien[$champ])} → {$valeur}"
                : "{$libelle} : {$valeur}";
        }

        return $lignes;
    }

    private function valeur(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            is_bool($v) => $v ? 'oui' : 'non',
            is_numeric($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ','),
            is_array($v) => '…',
            // Un identifiant ne dit rien au lecteur : seul compte le lien posé.
            is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $v) === 1 => 'lié',
            default => self::VALEURS_LISIBLES[$v] ?? (string) $v,
        };
    }

    private function mois(?string $mois): Carbon
    {
        try {
            return $mois ? Carbon::createFromFormat('Y-m', $mois)->startOfMonth() : now()->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    private function autoriserAnnulation(): void
    {
        abort_unless(auth()->user()?->can('facture.annuler'), 403,
            'Établir un avoir est réservé au chef de facturation et à la direction : le caissier encaisse, il n\'annule pas.');
    }

    private function autoriserLecture(bool $caisse = false): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->can('facture.annuler')
            || $user?->can('rapport.financier.view')
            || ($caisse && $user?->can('paiement.receive')),
            403,
            'Le registre des avoirs et le journal des factures sont réservés à la facturation et à la direction.'
        );
    }
}
