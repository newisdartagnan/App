<?php

namespace App\Services;

use App\Models\DemandeSang;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\Parametre;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\Transfusion;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Banque de sang : le stock, les donneurs, et la délivrance.
 *
 * Toute la sécurité tient en trois refus : une poche non dépistée ne sort
 * pas, une poche positive ne sort pas, une poche incompatible ne sort pas.
 * Ils sont écrits ici et nulle part ailleurs.
 */
class BanqueSangService
{
    /**
     * Enregistre un don : le donneur, puis la poche prélevée.
     *
     * La poche naît en quarantaine. Elle n'en sortira qu'une fois les cinq
     * marqueurs rendus négatifs.
     */
    public function enregistrerDon(DonneurSang $donneur, array $donnees): PocheSang
    {
        return DB::transaction(function () use ($donneur, $donnees) {
            $prelevement = filled($donnees['date_prelevement'] ?? null)
                ? Carbon::parse($donnees['date_prelevement'])
                : now();

            $produit = $donnees['type_produit'] ?? 'sang_total';

            $poche = PocheSang::create([
                'establishment_id' => $donneur->establishment_id,
                'donneur_id' => $donneur->id,
                'numero' => $donnees['numero'] ?? $this->genererNumeroPoche($donneur->establishment_id),
                // Le groupe de la poche est celui du donneur : on ne le
                // ressaisit pas, c'est la première source d'erreur.
                'groupe_sanguin' => $donneur->groupe_sanguin,
                'type_produit' => $produit,
                'volume_ml' => $donnees['volume_ml'] ?? 450,
                'date_prelevement' => $prelevement,
                'date_peremption' => $prelevement->copy()
                    ->addDays(PocheSang::CONSERVATION_JOURS[$produit] ?? 35),
                'statut' => 'quarantaine',
                'emplacement' => $donnees['emplacement'] ?? null,
                'notes' => $donnees['notes'] ?? null,
            ]);

            $donneur->update([
                'dernier_don' => $prelevement->toDateString(),
                'nombre_dons' => $donneur->nombre_dons + 1,
            ]);

            return $poche;
        });
    }

    /**
     * Enregistre le dépistage d'une poche.
     *
     * Négatif partout : la poche passe en rayon. Un seul marqueur positif :
     * elle est détruite, et le donneur écarté — il doit être orienté vers un
     * soignant, ce n'est pas une simple ligne de stock.
     */
    public function enregistrerDepistage(PocheSang $poche, array $resultats): PocheSang
    {
        return DB::transaction(function () use ($poche, $resultats) {
            $poche->update([
                'depistage_vih' => (bool) ($resultats['depistage_vih'] ?? false),
                'depistage_hepatite_b' => (bool) ($resultats['depistage_hepatite_b'] ?? false),
                'depistage_hepatite_c' => (bool) ($resultats['depistage_hepatite_c'] ?? false),
                'depistage_syphilis' => (bool) ($resultats['depistage_syphilis'] ?? false),
                'depistage_paludisme' => (bool) ($resultats['depistage_paludisme'] ?? false),
                'date_depistage' => now()->toDateString(),
                'depiste_par' => auth()->id(),
            ]);

            $poche->refresh();
            $positifs = $poche->marqueursPositifs();

            if ($positifs !== []) {
                $poche->update([
                    'statut' => 'detruite',
                    'notes' => trim(($poche->notes ?? '').' Dépistage positif : '.implode(', ', $positifs).'.'),
                ]);

                $poche->donneur?->update([
                    'est_eligible' => false,
                    'motif_exclusion' => 'Dépistage positif ('.implode(', ', $positifs).')',
                ]);

                return $poche->fresh();
            }

            $poche->update(['statut' => 'disponible']);

            return $poche->fresh();
        });
    }

    /**
     * Poches que la banque peut proposer pour une demande, les plus proches
     * de la péremption d'abord.
     */
    public function pochesPour(DemandeSang $demande)
    {
        return PocheSang::with('donneur')
            ->where('establishment_id', $demande->establishment_id)
            ->compatiblesAvec($demande->groupeReceveur(), $demande->type_produit)
            ->get();
    }

    /**
     * Délivre une poche et ouvre la feuille de transfusion.
     *
     * @return array{transfusion: ?Transfusion, erreur: ?string}
     */
    public function delivrer(DemandeSang $demande, PocheSang $poche, array $donnees = []): array
    {
        if (! $demande->estOuverte()) {
            return ['transfusion' => null, 'erreur' => 'Cette demande n\'est plus ouverte.'];
        }

        if ($motif = $poche->motifIndisponibilite()) {
            return ['transfusion' => null, 'erreur' => $motif];
        }

        $groupeReceveur = $demande->groupeReceveur();

        if (! $poche->estCompatibleAvec($groupeReceveur)) {
            return ['transfusion' => null, 'erreur' => sprintf(
                'Poche %s incompatible avec un receveur %s. Groupes acceptés : %s.',
                $poche->groupe_sanguin,
                $groupeReceveur ?: 'de groupe inconnu',
                implode(', ', PocheSang::groupesCompatiblesPour($groupeReceveur, $poche->type_produit))
            )];
        }

        $transfusion = DB::transaction(function () use ($demande, $poche, $donnees, $groupeReceveur) {
            $transfusion = Transfusion::create([
                'visit_id' => $demande->visit_id,
                'patient_id' => $demande->patient_id,
                'demande_id' => $demande->id,
                'poche_id' => $poche->id,
                'user_id' => auth()->id(),
                'produit' => PocheSang::PRODUIT_TRANSFUSION[$poche->type_produit] ?? 'sang_total',
                'groupe_donneur' => $poche->groupe_sanguin,
                'groupe_receveur' => $groupeReceveur,
                'numero_poche' => $poche->numero,
                'quantite' => $poche->volume_ml,
                'jour' => now()->toDateString(),
                'heure_debut' => $donnees['heure_debut'] ?? now()->format('H:i'),
                'controle_ultime' => (bool) ($donnees['controle_ultime'] ?? false),
                'hemoglobine_avant' => $donnees['hemoglobine_avant'] ?? $demande->hemoglobine,
                'incident' => 'aucun',
            ]);

            $poche->update(['statut' => 'transfusee']);

            // La demande se solde d'elle-même quand toutes ses poches sont
            // parties : le service n'a pas à venir la fermer à la main.
            $demande->update([
                'statut' => $demande->fresh()->pochesRestantes() <= 0 ? 'servie' : 'partiellement_servie',
            ]);

            $this->facturerPoche($transfusion, $demande, $poche);

            return $transfusion;
        });

        $this->prevenirLeDemandeur($demande, $poche);

        return ['transfusion' => $transfusion->fresh(), 'erreur' => null];
    }

    /**
     * Porte l'unité délivrée sur la facture du séjour.
     *
     * Sans séjour ouvert — la transfusion posée aux urgences avant toute
     * admission — il n'y a rien à facturer : le passage sera régularisé à
     * l'admission, et une facture sans visite n'aurait pas de destinataire.
     */
    private function facturerPoche(Transfusion $transfusion, DemandeSang $demande, PocheSang $poche): void
    {
        $tarif = $poche->tarif();

        if ($tarif <= 0 || ! $demande->visit_id) {
            return;
        }

        $visite = $demande->visit ?? Visit::find($demande->visit_id);

        if (! $visite) {
            return;
        }

        $facture = app(FacturationService::class)->creerFactureAmbulatoire(
            $demande->patient,
            $visite,
            'transfusion',
            $poche->libelleProduit().' — poche '.$poche->numero.' ('.$poche->groupe_sanguin.')',
            $tarif,
            'CDF',
            $transfusion->id
        );

        $transfusion->update(['facture_id' => $facture->id]);
    }

    /**
     * Prévient le service qui a demandé le sang.
     *
     * Le prescripteur n'a pas à guetter l'écran de la banque : c'est la
     * banque qui lui dit que sa poche est prête, comme le laboratoire lui
     * annonce ses résultats.
     */
    private function prevenirLeDemandeur(DemandeSang $demande, PocheSang $poche): void
    {
        if (! $demande->demandeur_id) {
            return;
        }

        app(NotificationService::class)->envoyer(
            service: 'banque_sang',
            type: 'poche_delivree',
            titre: 'Poche '.$poche->numero.' délivrée',
            message: sprintf(
                '%s (%s) pour %s. Posez-la, puis clôturez la transfusion : heure de fin, hémoglobine de contrôle et incident éventuel.',
                $poche->libelleProduit(),
                $poche->groupe_sanguin,
                $demande->patient?->nom_complet ?? 'le patient'
            ),
            referenceType: 'demande_sang',
            referenceId: $demande->id,
            codeReference: $demande->numero,
            destinataireId: $demande->demandeur_id,
            priorite: $demande->urgence ? 'urgente' : 'normale',
        );
    }

    /**
     * Clôture la feuille de transfusion : c'est là que se fait l'hémovigilance.
     *
     * Tant que personne n'écrit l'heure de fin, l'hémoglobine de contrôle et
     * l'incident éventuel, la poche n'est qu'une sortie de stock. Un incident
     * grave remonte immédiatement au prescripteur et au laboratoire : une
     * suspicion d'hémolyse ne s'archive pas, elle s'annonce.
     */
    public function cloturerTransfusion(Transfusion $transfusion, array $donnees): Transfusion
    {
        $transfusion->update([
            'heure_fin' => $donnees['heure_fin'] ?? now()->format('H:i'),
            'hemoglobine_apres' => $donnees['hemoglobine_apres'] ?? null,
            'incident' => $donnees['incident'] ?? 'aucun',
            'observation' => $donnees['observation'] ?? null,
            'cloturee_le' => now(),
            'cloturee_par' => auth()->id(),
        ]);

        $transfusion->refresh();

        if ($transfusion->avecIncident()) {
            $this->declarerIncident($transfusion);
        }

        return $transfusion;
    }

    /**
     * Fait remonter un incident transfusionnel.
     *
     * Il part au prescripteur, qui doit décider de la suite, et au
     * laboratoire, qui tient le registre et devra peut-être bloquer les
     * autres poches du même donneur.
     */
    private function declarerIncident(Transfusion $transfusion): void
    {
        $notifications = app(NotificationService::class);

        $message = sprintf(
            '%s pendant la transfusion de la poche %s (%s → %s) chez %s.%s',
            $transfusion->libelleIncident(),
            $transfusion->numero_poche,
            $transfusion->groupe_donneur,
            $transfusion->groupe_receveur,
            $transfusion->patient?->nom_complet ?? 'un patient',
            $transfusion->incidentEstGrave()
                ? ' Transfusion à arrêter immédiatement : prévenez le médecin.'
                : ''
        );

        $priorite = $transfusion->incidentEstGrave() ? 'urgente' : 'haute';

        foreach (array_filter([$transfusion->demande?->demandeur_id, $transfusion->user_id]) as $destinataire) {
            $notifications->envoyer(
                service: 'banque_sang',
                type: 'incident_transfusionnel',
                titre: 'Incident transfusionnel — '.$transfusion->numero_poche,
                message: $message,
                referenceType: 'transfusion',
                referenceId: $transfusion->id,
                codeReference: $transfusion->numero_poche,
                destinataireId: $destinataire,
                priorite: $priorite,
            );
        }

        $notifications->envoyer(
            service: 'banque_sang',
            type: 'incident_transfusionnel',
            titre: 'Incident transfusionnel — '.$transfusion->numero_poche,
            message: $message,
            referenceType: 'transfusion',
            referenceId: $transfusion->id,
            codeReference: $transfusion->numero_poche,
            groupeDestinataire: 'laborantin',
            priorite: $priorite,
        );
    }

    /**
     * Écarte un donneur du fichier, ou l'y remet.
     *
     * L'exclusion n'est pas toujours une sérologie : un poids insuffisant,
     * une grossesse en cours, un traitement, un refus. Elle doit pouvoir se
     * poser et se lever à la main, avec son motif.
     */
    public function reglerEligibilite(DonneurSang $donneur, bool $eligible, ?string $motif = null): DonneurSang
    {
        $donneur->update([
            'est_eligible' => $eligible,
            'motif_exclusion' => $eligible ? null : ($motif ?: 'Écarté sans motif précisé'),
        ]);

        return $donneur->fresh();
    }

    // ═══════════════════════════════════════════════════════════
    // Le réseau : où trouver du sang quand la maison n'en a plus
    // ═══════════════════════════════════════════════════════════

    /** Clé du réglage de partage, établissement par établissement. */
    public const CLE_PARTAGE = 'banque_sang.partage_reseau';

    /**
     * Établissements qui acceptent d'être visibles du réseau.
     *
     * Le partage est ouvert par défaut : une banque qui n'annonce rien ne
     * sert à personne, et c'est bien le but que ces registres se répondent.
     * Un établissement peut s'en retirer, et son stock disparaît alors des
     * écrans des autres.
     *
     * @return Collection<int, Establishment>
     */
    public function etablissementsPartageurs(?string $exclure = null): Collection
    {
        $refus = Parametre::where('cle', self::CLE_PARTAGE)
            ->get()
            ->reject(fn (Parametre $p) => $this->valeurVraie($p->valeur))
            ->pluck('establishment_id')
            ->all();

        return Establishment::query()
            ->where('is_active', true)
            ->when($exclure, fn ($q) => $q->where('id', '!=', $exclure))
            ->when($refus !== [], fn ($q) => $q->whereNotIn('id', $refus))
            ->orderBy('name')
            ->get();
    }

    /** Le réglage est-il à « oui » ? Il a pu être écrit en booléen ou en tableau. */
    private function valeurVraie(mixed $valeur): bool
    {
        if (is_array($valeur)) {
            $valeur = $valeur['actif'] ?? true;
        }

        return filter_var($valeur, FILTER_VALIDATE_BOOLEAN);
    }

    /** Cet établissement partage-t-il son stock avec le réseau ? */
    public function partageSonStock(?string $etablissementId): bool
    {
        if (! $etablissementId) {
            return false;
        }

        $reglage = Parametre::where('establishment_id', $etablissementId)
            ->where('cle', self::CLE_PARTAGE)
            ->first();

        // Rien de saisi : le partage est ouvert. C'est le but du registre.
        return $reglage === null || $this->valeurVraie($reglage->valeur);
    }

    /**
     * Ce que les autres établissements ont sous la main.
     *
     * C'est la demande d'origine : ne plus téléphoner à l'aveugle à trois
     * heures du matin. Pour chaque maison du réseau, ce qu'elle a de
     * délivrable — et, à défaut de poche, qui elle peut appeler.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function reseau(?string $etablissementCourant, ?string $groupeReceveur = null, string $typeProduit = 'sang_total'): Collection
    {
        $maisons = $this->etablissementsPartageurs($etablissementCourant);

        if ($maisons->isEmpty()) {
            return collect();
        }

        $ids = $maisons->pluck('id')->all();
        $groupesUtiles = $groupeReceveur
            ? PocheSang::groupesCompatiblesPour($groupeReceveur, $typeProduit)
            : PocheSang::GROUPES;

        $poches = PocheSang::whereIn('establishment_id', $ids)
            ->where('statut', 'disponible')
            ->get()
            ->filter->estDelivrable()
            ->groupBy('establishment_id');

        $donneurs = DonneurSang::whereIn('establishment_id', $ids)
            ->where('est_eligible', true)
            ->get()
            ->filter->peutDonnerMaintenant()
            ->groupBy('establishment_id');

        return $maisons->map(function (Establishment $maison) use ($poches, $donneurs, $groupesUtiles, $groupeReceveur) {
            $stock = $poches->get($maison->id, collect());
            $fichier = $donneurs->get($maison->id, collect());

            $compatibles = $stock->whereIn('groupe_sanguin', $groupesUtiles);
            $donneursCompatibles = $fichier->whereIn('groupe_sanguin', $groupesUtiles);

            return [
                'id' => $maison->id,
                'nom' => $maison->name,
                'ville' => $maison->ville,
                'telephone' => $maison->telephone,
                'total' => $stock->count(),
                'compatibles' => $compatibles->count(),
                'par_groupe' => collect(PocheSang::GROUPES)
                    ->mapWithKeys(fn ($g) => [$g => $stock->where('groupe_sanguin', $g)->count()])
                    ->filter(),
                'donneurs' => $fichier->count(),
                'donneurs_compatibles' => $donneursCompatibles->count(),
                // Les téléphones ne sortent que quand on cherche un groupe
                // précis : le réseau sert à trouver du sang, pas à recopier
                // le fichier des donneurs de la maison d'à côté.
                'a_appeler' => $groupeReceveur
                    ? $donneursCompatibles->sortBy(fn ($d) => $d->dernier_don?->timestamp ?? 0)->take(5)->values()
                    : collect(),
            ];
        })->sortByDesc('compatibles')->values();
    }

    /**
     * Registre transfusionnel : la trace de ce qui a été posé.
     *
     * @return Collection<int, Transfusion>
     */
    public function registre(?string $etablissementId, array $filtres = []): Collection
    {
        return Transfusion::query()
            ->with(['patient', 'poche.donneur', 'demande', 'auteur', 'cloturePar', 'facture'])
            ->when($etablissementId, fn ($q) => $q->whereHas(
                'patient',
                fn ($sub) => $sub->where('establishment_id', $etablissementId)
            ))
            ->when($filtres['debut'] ?? null, fn ($q, $debut) => $q->whereDate('jour', '>=', $debut))
            ->when($filtres['fin'] ?? null, fn ($q, $fin) => $q->whereDate('jour', '<=', $fin))
            ->when($filtres['groupe'] ?? null, fn ($q, $groupe) => $q->where('groupe_receveur', $groupe))
            ->when(($filtres['etat'] ?? null) === 'en_cours', fn ($q) => $q->whereNull('cloturee_le'))
            ->when(($filtres['etat'] ?? null) === 'incident', fn ($q) => $q->where('incident', '!=', 'aucun'))
            ->orderByDesc('jour')
            ->orderByDesc('heure_debut')
            ->get();
    }

    /**
     * Passe en « périmée » tout ce qui a dépassé sa date.
     *
     * Une poche périmée qui reste marquée disponible finit par être posée :
     * le ménage se fait tous les jours, pas au moment de servir.
     */
    public function retirerPochesPerimees(): int
    {
        return PocheSang::whereIn('statut', ['quarantaine', 'disponible', 'reservee'])
            ->whereDate('date_peremption', '<', now()->toDateString())
            ->update(['statut' => 'perimee']);
    }

    /**
     * État du stock, groupe par groupe, et donneurs joignables.
     *
     * @return array<string, mixed>
     */
    public function etatDuStock(?string $etablissementId = null): array
    {
        $poches = PocheSang::query()
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        $delivrables = $poches->filter->estDelivrable();

        $donneurs = DonneurSang::query()
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        return [
            'total' => $poches->count(),
            'delivrables' => $delivrables->count(),
            'quarantaine' => $poches->where('statut', 'quarantaine')->count(),
            'perime_bientot' => $delivrables->filter(fn ($p) => $p->joursAvantPeremption() <= 7)->count(),
            'par_groupe' => collect(PocheSang::GROUPES)
                ->mapWithKeys(fn ($groupe) => [
                    $groupe => $delivrables->where('groupe_sanguin', $groupe)->count(),
                ]),
            'par_produit' => $delivrables->groupBy(fn ($p) => $p->libelleProduit())->map->count()->sortDesc(),
            'donneurs_joignables' => collect(PocheSang::GROUPES)
                ->mapWithKeys(fn ($groupe) => [
                    $groupe => $donneurs->where('groupe_sanguin', $groupe)
                        ->filter->peutDonnerMaintenant()->count(),
                ]),
        ];
    }

    /**
     * Donneurs à appeler pour un receveur donné : compatibles, éligibles,
     * délai écoulé. C'est la réponse à « où trouver du sang, cette nuit ».
     */
    public function donneursAAppeler(?string $groupeReceveur, ?string $etablissementId = null)
    {
        return DonneurSang::query()
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->compatiblesAvec($groupeReceveur)
            ->get()
            ->filter->peutDonnerMaintenant()
            // Le donneur le plus reposé en premier : celui qui n'a jamais
            // donné, puis le plus ancien don.
            ->sortBy(fn (DonneurSang $donneur) => $donneur->dernier_don?->timestamp ?? 0)
            ->values();
    }

    /** Numéro de poche : PS-2026-000001. */
    public function genererNumeroPoche(string $etablissementId): string
    {
        $prefixe = 'PS-'.now()->year.'-';

        return DB::transaction(function () use ($prefixe, $etablissementId) {
            $dernier = PocheSang::where('establishment_id', $etablissementId)
                ->where('numero', 'like', $prefixe.'%')
                ->orderByDesc('numero')
                ->lockForUpdate()
                ->value('numero');

            $sequence = $dernier && preg_match('/-(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

            return $prefixe.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }

    /** Numéro de demande : DS-2026-000001. */
    public function genererNumeroDemande(string $etablissementId): string
    {
        $prefixe = 'DS-'.now()->year.'-';

        return DB::transaction(function () use ($prefixe, $etablissementId) {
            $dernier = DemandeSang::where('establishment_id', $etablissementId)
                ->where('numero', 'like', $prefixe.'%')
                ->orderByDesc('numero')
                ->lockForUpdate()
                ->value('numero');

            $sequence = $dernier && preg_match('/-(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

            return $prefixe.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }

    /** Code donneur : DON-000001. */
    public function genererCodeDonneur(string $etablissementId): string
    {
        $dernier = DonneurSang::where('establishment_id', $etablissementId)
            ->where('code', 'like', 'DON-%')
            ->orderByDesc('code')
            ->value('code');

        $sequence = $dernier && preg_match('/-(\d+)$/', $dernier, $m) ? (int) $m[1] + 1 : 1;

        return 'DON-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /** Ouvre une demande de sang pour un patient. */
    public function creerDemande(Patient $patient, array $donnees): DemandeSang
    {
        return DemandeSang::create([
            'establishment_id' => $patient->establishment_id,
            'patient_id' => $patient->id,
            'visit_id' => $donnees['visit_id'] ?? null,
            'demandeur_id' => auth()->id(),
            'numero' => $this->genererNumeroDemande($patient->establishment_id),
            'groupe_demande' => ($donnees['groupe_demande'] ?? null) ?: $patient->groupe_sanguin,
            'type_produit' => $donnees['type_produit'] ?? 'sang_total',
            'nombre_poches' => $donnees['nombre_poches'] ?? 1,
            'urgence' => (bool) ($donnees['urgence'] ?? false),
            'indication' => $donnees['indication'] ?? null,
            'hemoglobine' => $donnees['hemoglobine'] ?? null,
            'statut' => 'en_attente',
        ]);
    }
}
