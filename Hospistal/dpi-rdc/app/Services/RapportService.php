<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Paiement;
use App\Models\ResultatExamen;
use App\Models\Service;
use App\Models\SortieAmbulance;
use App\Models\StockMedicament;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Les rapports d'activité, sur une période (le mois en cours par défaut) :
 * patients, pharmacie, laboratoire, imagerie, caisse, services, ambulances.
 *
 * Chaque rapport rend un tableau générique — colonnes, lignes, totaux — que
 * la même page affiche, imprime et exporte en CSV.
 */
class RapportService
{
    /** @return array{colonnes: array, lignes: Collection, totaux: ?array, titre: string} */
    public function patients(string $du, string $au, ?string $serviceId = null): array
    {
        $visites = Visit::with(['patient.methodesPaiement.methode', 'service', 'user', 'consultations'])
            ->whereBetween('date_entree', [$du.' 00:00:00', $au.' 23:59:59'])
            ->when($serviceId, fn ($q) => $q->where('service_id', $serviceId))
            ->orderBy('date_entree')
            ->get();

        $premieres = Visit::whereIn('patient_id', $visites->pluck('patient_id'))
            ->selectRaw('patient_id, MIN(date_entree) as premiere')->groupBy('patient_id')->pluck('premiere', 'patient_id');

        $lignes = $visites->map(function (Visit $v) use ($premieres) {
            $p = $v->patient;
            $diagnostics = $v->consultations->flatMap(fn ($c) => collect($c->diagnostics ?? [])->pluck('libelle'))->filter()->unique()->implode(' ; ');
            $nouveau = isset($premieres[$v->patient_id]) && Carbon::parse($premieres[$v->patient_id])->equalTo($v->date_entree);
            $sortie = $v->date_sortie;
            $methodes = $p?->methodesPaiement->map(fn ($l) => $l->methode?->libelle)->filter()->implode(', ');

            return [
                $p?->dossier_number, $p?->nom_complet,
                ['M' => 'M', 'F' => 'F'][$p?->sexe] ?? '?', $p?->libelleAge(),
                $nouveau ? 'Nouveau' : 'Ancien',
                $diagnostics ?: '—',
                $v->date_entree?->format('d/m/Y H:i'), $sortie?->format('d/m/Y H:i') ?? '—',
                $sortie ? max(0, (int) $v->date_entree->copy()->startOfDay()->diffInDays($sortie->copy()->startOfDay())) : '—',
                $methodes ?: ($p?->libellePriseEnCharge() ?? '—'),
                $v->service?->nom ?? ($v->type === 'urgence' ? 'Urgences' : 'Consultation'),
                $v->mode_sortie ? $v->libelleModeSortie() : ($v->statut === 'termine' ? 'Non précisé' : 'En cours'),
                $v->user?->nom_complet ?? '—',
            ];
        });

        return [
            'titre' => 'Rapport des patients',
            'colonnes' => ['N° dossier', 'Patient', 'Sexe', 'Âge', 'Cas', 'Diagnostics', 'Admission', 'Sortie', 'Séjour (j)', 'Paiement', 'Service', 'Issue', 'Médecin'],
            'lignes' => $lignes,
            'totaux' => ['Total : '.$lignes->count().' épisode(s)', '', 'M : '.$lignes->where(2, 'M')->count().' / F : '.$lignes->where(2, 'F')->count(), '',
                'Nouveaux : '.$lignes->where(4, 'Nouveau')->count(), '', '', '', '', '', '', '', ''],
        ];
    }

    /** Fiche de consommation : entrées, sorties et stock physique de chaque produit. */
    public function pharmacie(string $du, string $au): array
    {
        $mouvements = MouvementStock::whereBetween('created_at', [$du.' 00:00:00', $au.' 23:59:59'])
            ->selectRaw("medicament_id,
                SUM(CASE WHEN type IN ('entree', 'transfert_entree') THEN ABS(quantite) ELSE 0 END) AS entrees,
                SUM(CASE WHEN type IN ('sortie_dispensation', 'sortie_peremption', 'transfert_sortie') THEN ABS(quantite) ELSE 0 END) AS sorties,
                SUM(CASE WHEN type = 'ajustement_inventaire' THEN (quantite_apres - quantite_avant) ELSE 0 END) AS ajustements")
            ->groupBy('medicament_id')->get()->keyBy('medicament_id');

        $stock = StockMedicament::selectRaw('medicament_id, SUM(quantite_disponible) AS physique')
            ->groupBy('medicament_id')->pluck('physique', 'medicament_id');

        $lignes = Medicament::orderBy('denomination_commune')->get()
            ->filter(fn ($m) => isset($mouvements[$m->id]) || (float) ($stock[$m->id] ?? 0) > 0)
            ->map(function (Medicament $m) use ($mouvements, $stock) {
                $mv = $mouvements[$m->id] ?? null;

                return [
                    $m->denomination_commune.($m->dosage ? ' '.$m->dosage : ''),
                    $m->forme, $m->unite_dispensation,
                    $this->nombre($mv?->entrees ?? 0), $this->nombre($mv?->sorties ?? 0),
                    $this->nombre($mv?->ajustements ?? 0), $this->nombre($stock[$m->id] ?? 0),
                ];
            })->values();

        return [
            'titre' => 'Fiche de consommation des produits pharmaceutiques et intrants',
            'colonnes' => ['Produit', 'Forme', 'Unité', 'Entrées', 'Sorties', 'Ajustements', 'Stock physique'],
            'lignes' => $lignes,
            'totaux' => null,
        ];
    }

    /** Examens demandés et réalisés par type (laboratoire ou imagerie). */
    public function examens(string $domaine, string $du, string $au): array
    {
        $resultats = ResultatExamen::with('typeExamen')
            ->whereHas('examen', fn ($q) => $q->where('domaine', $domaine)
                ->where('statut', '!=', 'annule')
                ->whereBetween('date_prescription', [$du.' 00:00:00', $au.' 23:59:59']))
            ->with('examen:id,statut')
            ->get();

        $lignes = $resultats->groupBy('type_examen_id')->map(function ($groupe) {
            $type = $groupe->first()->typeExamen;
            $realises = $groupe->filter(fn ($r) => filled($r->valeur_brute) || in_array($r->examen?->statut, ['resultat_disponible', 'valide'], true))->count();

            return [$type?->libelle ?? '—', \App\Models\TypeExamen::UNITES[$type?->categorie] ?? $type?->libelleModalite(), $groupe->count(), $realises, $groupe->count() - $realises];
        })->sortBy(0)->values();

        return [
            'titre' => $domaine === 'imagerie' ? 'Rapport d\'imagerie' : 'Rapport du laboratoire',
            'colonnes' => [$domaine === 'imagerie' ? 'Technique' : 'Examen', $domaine === 'imagerie' ? 'Catégorie' : 'Unité', 'Demandés', 'Réalisés', 'En attente'],
            'lignes' => $lignes,
            'totaux' => ['Total', '', $lignes->sum(2), $lignes->sum(3), $lignes->sum(4)],
        ];
    }

    /** Caisse : par jour, factures créées / non payées / payées et recette. */
    public function caisse(string $du, string $au): array
    {
        $factures = Facture::whereBetween('date_facture', [$du.' 00:00:00', $au.' 23:59:59'])
            ->where('statut', '!=', 'annulee')
            ->get(['id', 'date_facture', 'statut']);
        $recettes = Paiement::whereBetween('date_paiement', [$du.' 00:00:00', $au.' 23:59:59'])
            ->selectRaw('DATE(date_paiement) as jour, SUM(montant_cdf) as total')
            ->groupBy('jour')->pluck('total', 'jour');

        $jours = $factures->groupBy(fn ($f) => $f->date_facture->toDateString())->keys()
            ->merge($recettes->keys())->unique()->sort()->values();
        $devises = app(DeviseService::class);

        $lignes = $jours->map(function ($jour, $i) use ($factures, $recettes, $devises) {
            $du = $factures->filter(fn ($f) => $f->date_facture->toDateString() === $jour);

            return [
                'jour' => $jour,
                'cellules' => [
                    $i + 1, Carbon::parse($jour)->format('d/m/Y'), $du->count(),
                    $du->whereIn('statut', ['brouillon', 'emise', 'partiellement_payee'])->count(),
                    $du->where('statut', 'payee')->count(),
                    $devises->formater((float) ($recettes[$jour] ?? 0), 'CDF'),
                ],
                'recette' => (float) ($recettes[$jour] ?? 0),
            ];
        });

        return [
            'titre' => 'Rapports : Caisse / Facturation',
            'colonnes' => ['#', 'Date', 'Factures créées', 'Factures non payées', 'Factures payées', 'Recette totale'],
            'lignes' => $lignes->pluck('cellules'),
            'jours' => $lignes->pluck('jour'),
            'totaux' => ['Total général', '', $lignes->sum(fn ($l) => $l['cellules'][2]), $lignes->sum(fn ($l) => $l['cellules'][3]),
                $lignes->sum(fn ($l) => $l['cellules'][4]), $devises->formater($lignes->sum('recette'), 'CDF')],
        ];
    }

    /** Détails d'une journée de caisse : chaque facture et ce qui en a été encaissé. */
    public function caisseJour(string $jour): Collection
    {
        return Facture::with(['patient', 'paiements'])
            ->whereDate('date_facture', $jour)
            ->orderBy('date_facture')
            ->get();
    }

    /** Cas sortants par service et par issue (guéris, transférés, décédés…). */
    public function services(string $du, string $au): array
    {
        $sorties = Visit::with('service')
            ->whereNotNull('date_sortie')
            ->whereBetween('date_sortie', [$du.' 00:00:00', $au.' 23:59:59'])
            ->get(['id', 'service_id', 'type', 'mode_sortie', 'date_sortie']);

        $modes = Visit::MODES_SORTIE;
        $lignes = $sorties->groupBy(fn ($v) => $v->service?->nom ?? ($v->type === 'urgence' ? 'Urgences' : 'Consultation externe'))
            ->map(function ($groupe, $service) use ($modes) {
                $ligne = [$service];
                foreach (array_keys($modes) as $mode) {
                    $ligne[] = $groupe->filter(fn ($v) => ($v->mode_sortie ?? 'inconnu') === $mode)->count();
                }
                $ligne[] = $groupe->count();

                return $ligne;
            })->sortBy(0)->values();

        $totaux = ['Total'];
        foreach (range(1, count($modes) + 1) as $i) {
            $totaux[] = $lignes->sum($i);
        }

        return [
            'titre' => 'Cas sortants par service',
            'colonnes' => array_merge(['Service'], array_values($modes), ['Total']),
            'lignes' => $lignes,
            'totaux' => $totaux,
        ];
    }

    public function ambulances(string $du, string $au): array
    {
        $sorties = SortieAmbulance::with('ambulance')
            ->whereBetween('depart_at', [$du.' 00:00:00', $au.' 23:59:59'])
            ->orderBy('depart_at')->get();

        $lignes = $sorties->map(fn (SortieAmbulance $s) => [
            $s->depart_at->format('d/m/Y'), $s->ambulance?->libelle, $s->motif, $s->destination ?? '—',
            $s->depart_at->format('H:i'), $s->arrivee_at?->format('H:i') ?? 'en mission',
            $s->dureeMinutes() !== null ? intdiv($s->dureeMinutes(), 60).' h '.str_pad((string) ($s->dureeMinutes() % 60), 2, '0', STR_PAD_LEFT) : '—',
            $s->distanceKm() !== null ? $this->nombre($s->distanceKm()) : '—',
        ]);
        $minutes = (int) $sorties->sum(fn ($s) => $s->dureeMinutes() ?? 0);

        return [
            'titre' => 'Rapport des ambulances',
            'colonnes' => ['Date', 'Ambulance', 'Motif', 'Destination', 'Départ', 'Arrivée', 'Durée', 'Distance (km)'],
            'lignes' => $lignes,
            'totaux' => [$sorties->count().' intervention(s)', '', '', '', '', '', intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT),
                $this->nombre($sorties->sum(fn ($s) => $s->distanceKm() ?? 0))],
        ];
    }

    private function nombre(float|int|string|null $n): string
    {
        $n = (float) $n;

        return rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    }
}
