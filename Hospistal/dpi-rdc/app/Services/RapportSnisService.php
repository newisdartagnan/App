<?php

namespace App\Services;

use App\Models\Accouchement;
use App\Models\Consultation;
use App\Models\ConsultationPrenatale;
use App\Models\ExamenLaboratoire;
use App\Models\NouveauNe;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\StockMedicament;
use App\Models\Transfusion;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Rapport mensuel du SNIS.
 *
 * Chaque établissement congolais remonte tous les mois à sa zone de santé un
 * canevas d'indicateurs. Il se remplit aujourd'hui à la main, registre après
 * registre, une journée entière — alors que chacun de ces chiffres est déjà
 * dans la base, à la ligne près.
 *
 * On compte ce qui est enregistré et rien d'autre. Une rubrique que
 * l'application ne suit pas — planification familiale, vaccination — n'est
 * pas inventée : elle est déclarée non suivie, à remplir depuis le registre
 * papier. Un rapport qui ment est pire qu'un rapport incomplet, parce que
 * personne ne sait plus lesquels de ses chiffres croire.
 */
class RapportSnisService
{
    /**
     * Tranches d'âge du canevas national, en mois révolus.
     *
     * Les bornes du SNIS ne sont pas celles du bon sens : le nourrisson va
     * jusqu'à onze mois, l'enfant jusqu'à cinquante-neuf. On les respecte
     * telles quelles, sinon les chiffres ne se comparent plus d'un mois sur
     * l'autre ni d'un établissement à l'autre.
     */
    public const TRANCHES = [
        'moins_1an' => ['libelle' => '0 – 11 mois', 'min' => 0, 'max' => 11],
        'moins_5ans' => ['libelle' => '12 – 59 mois', 'min' => 12, 'max' => 59],
        'scolaire' => ['libelle' => '5 – 14 ans', 'min' => 60, 'max' => 179],
        'adulte' => ['libelle' => '15 – 49 ans', 'min' => 180, 'max' => 599],
        'age_mur' => ['libelle' => '50 ans et plus', 'min' => 600, 'max' => null],
        'inconnu' => ['libelle' => 'Âge inconnu', 'min' => null, 'max' => null],
    ];

    /**
     * Pathologies suivies par le canevas, et ce qui les reconnaît.
     *
     * Le rapprochement se fait sur le libellé du diagnostic et sur le code
     * CIM-10 quand le médecin l'a posé. C'est imparfait — un diagnostic mal
     * orthographié échappe au compte — d'où la ligne « autres pathologies »
     * qui recueille le reste : le total des lignes égale toujours le total
     * des diagnostics, et l'écart se voit.
     */
    public const PATHOLOGIES = [
        'paludisme' => ['libelle' => 'Paludisme', 'mots' => ['paludisme', 'malaria'], 'cim' => ['B50', 'B51', 'B52', 'B53', 'B54'], 'cim11' => ['1F4']],
        'ira' => ['libelle' => 'Infections respiratoires aiguës', 'mots' => ['respiratoire', 'pneumonie', 'bronchite', 'rhinopharyngite', 'angine'], 'cim' => ['J00', 'J01', 'J02', 'J03', 'J04', 'J06', 'J12', 'J13', 'J14', 'J15', 'J18', 'J20', 'J21', 'J22'], 'cim11' => ['CA4', 'CA2', 'CA0']],
        'diarrhee' => ['libelle' => 'Maladies diarrhéiques', 'mots' => ['diarrh', 'gastro-ent', 'gastroent'], 'cim' => ['A00', 'A01', 'A02', 'A03', 'A04', 'A05', 'A06', 'A07', 'A08', 'A09'], 'cim11' => ['1A4', '1A2', 'ME05']],
        'fievre_typhoide' => ['libelle' => 'Fièvre typhoïde', 'mots' => ['typho'], 'cim' => ['A01'], 'cim11' => ['1A07']],
        'tuberculose' => ['libelle' => 'Tuberculose', 'mots' => ['tuberculose'], 'cim' => ['A15', 'A16', 'A17', 'A18', 'A19'], 'cim11' => ['1B1']],
        'vih' => ['libelle' => 'VIH / sida', 'mots' => ['vih', 'sida'], 'cim' => ['B20', 'B21', 'B22', 'B23', 'B24'], 'cim11' => ['1C6']],
        'malnutrition' => ['libelle' => 'Malnutrition', 'mots' => ['malnutrition', 'kwashiorkor', 'marasme'], 'cim' => ['E40', 'E41', 'E42', 'E43', 'E44', 'E45', 'E46'], 'cim11' => ['5B5']],
        'anemie' => ['libelle' => 'Anémie', 'mots' => ['anémie', 'anemie'], 'cim' => ['D50', 'D51', 'D52', 'D53', 'D64'], 'cim11' => ['3A']],
        'hta' => ['libelle' => 'Hypertension artérielle', 'mots' => ['hypertension', 'hta'], 'cim' => ['I10', 'I11', 'I12', 'I13', 'I15'], 'cim11' => ['BA0']],
        'diabete' => ['libelle' => 'Diabète', 'mots' => ['diab'], 'cim' => ['E10', 'E11', 'E12', 'E13', 'E14'], 'cim11' => ['5A1']],
        'ist' => ['libelle' => 'Infections sexuellement transmissibles', 'mots' => ['ist ', 'syphilis', 'gonococ', 'blennorrag'], 'cim' => ['A50', 'A51', 'A52', 'A53', 'A54', 'A55', 'A56', 'A57', 'A58', 'A59', 'A60', 'A63', 'A64'], 'cim11' => ['1A6', 'GA0']],
        'traumatismes' => ['libelle' => 'Traumatismes et accidents', 'mots' => ['fracture', 'plaie', 'traumat', 'brûlure', 'brulure', 'entorse', 'luxation'], 'cim' => ['S', 'T', 'V', 'W', 'X', 'Y'], 'cim11' => ['N', 'PA8']],
    ];

    /** Rubriques du canevas que l'application ne suit pas encore. */
    public const NON_SUIVI = [
        'Planification familiale (nouvelles acceptantes, méthodes)',
        'Vaccination — Programme élargi de vaccination',
        'Consultations préscolaires et suivi de croissance',
        'Activités communautaires et sensibilisation',
    ];

    /**
     * Le rapport complet d'un mois.
     *
     * @return array<string, mixed>
     */
    public function rapport(int $annee, int $mois, ?string $etablissementId = null): array
    {
        $debut = Carbon::create($annee, $mois, 1)->startOfMonth();
        $fin = $debut->copy()->endOfMonth();

        return [
            'periode' => [
                'annee' => $annee,
                'mois' => $mois,
                'libelle' => $debut->translatedFormat('F Y'),
                'debut' => $debut,
                'fin' => $fin,
            ],
            'consultations' => $this->consultations($debut, $fin, $etablissementId),
            'morbidite' => $this->morbidite($debut, $fin, $etablissementId),
            'hospitalisation' => $this->hospitalisation($debut, $fin, $etablissementId),
            'maternite' => $this->maternite($debut, $fin, $etablissementId),
            'laboratoire' => $this->laboratoire($debut, $fin, $etablissementId),
            'sang' => $this->sang($debut, $fin, $etablissementId),
            'pharmacie' => $this->pharmacie($etablissementId),
            'deces' => $this->deces($debut, $fin, $etablissementId),
            'non_suivi' => self::NON_SUIVI,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Consultations curatives
    // ═══════════════════════════════════════════════════════════

    /**
     * Consultations par tranche d'âge et par sexe.
     *
     * Le canevas distingue le nouveau cas de l'ancien : est nouveau le
     * premier passage du patient dans l'établissement, ancien tout retour.
     *
     * @return array<string, mixed>
     */
    private function consultations(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $visites = Visit::query()
            ->with('patient')
            ->whereIn('type', ['consultation_externe', 'urgence'])
            ->whereBetween('date_entree', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        // Premier passage jamais enregistré : c'est lui qui fait le nouveau cas.
        $premiers = Visit::query()
            ->whereIn('patient_id', $visites->pluck('patient_id')->unique())
            ->selectRaw('patient_id, min(date_entree) as premiere')
            ->groupBy('patient_id')
            ->pluck('premiere', 'patient_id');

        $lignes = [];

        foreach (self::TRANCHES as $cle => $tranche) {
            $lignes[$cle] = [
                'libelle' => $tranche['libelle'],
                'nouveaux_m' => 0, 'nouveaux_f' => 0,
                'anciens_m' => 0, 'anciens_f' => 0,
                'total' => 0,
            ];
        }

        foreach ($visites as $visite) {
            $cle = $this->trancheDe($visite->patient, $visite->date_entree);
            $sexe = $visite->patient?->sexe === 'F' ? 'f' : 'm';

            $premiere = $premiers[$visite->patient_id] ?? null;
            $nouveau = $premiere !== null
                && Carbon::parse($premiere)->equalTo($visite->date_entree);

            $lignes[$cle][($nouveau ? 'nouveaux_' : 'anciens_').$sexe]++;
            $lignes[$cle]['total']++;
        }

        return [
            'lignes' => $lignes,
            'total' => $visites->count(),
            'nouveaux' => collect($lignes)->sum(fn ($l) => $l['nouveaux_m'] + $l['nouveaux_f']),
            'anciens' => collect($lignes)->sum(fn ($l) => $l['anciens_m'] + $l['anciens_f']),
            'urgences' => $visites->where('type', 'urgence')->count(),
        ];
    }

    /** Tranche du canevas à laquelle appartient un patient, ce jour-là. */
    private function trancheDe(?Patient $patient, Carbon $jour): string
    {
        if (! $patient?->date_naissance) {
            return 'inconnu';
        }

        $mois = $patient->date_naissance->diffInMonths($jour);

        foreach (self::TRANCHES as $cle => $tranche) {
            if ($tranche['min'] === null) {
                continue;
            }

            if ($mois >= $tranche['min'] && ($tranche['max'] === null || $mois <= $tranche['max'])) {
                return $cle;
            }
        }

        return 'inconnu';
    }

    // ═══════════════════════════════════════════════════════════
    // Morbidité
    // ═══════════════════════════════════════════════════════════

    /**
     * Principales pathologies diagnostiquées dans le mois.
     *
     * @return array<string, mixed>
     */
    private function morbidite(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $consultations = Consultation::query()
            ->with('visit.patient')
            ->whereBetween('date_consultation', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->whereHas(
                'visit', fn ($v) => $v->where('establishment_id', $etablissementId)
            ))
            ->get();

        $lignes = collect(self::PATHOLOGIES)
            ->map(fn ($p) => ['libelle' => $p['libelle'], 'moins_5ans' => 0, 'plus_5ans' => 0, 'total' => 0])
            ->all();

        $lignes['autres'] = ['libelle' => 'Autres pathologies', 'moins_5ans' => 0, 'plus_5ans' => 0, 'total' => 0];
        $totalDiagnostics = 0;

        foreach ($consultations as $consultation) {
            $tranche = $this->trancheDe($consultation->visit?->patient, $consultation->date_consultation);
            $petit = in_array($tranche, ['moins_1an', 'moins_5ans'], true) ? 'moins_5ans' : 'plus_5ans';

            foreach ($consultation->diagnostics ?? [] as $diagnostic) {
                $libelle = trim((string) ($diagnostic['libelle'] ?? ''));

                if ($libelle === '') {
                    continue;
                }

                $totalDiagnostics++;
                // Les dossiers ouverts avant le passage à la CIM-11 portent
                // encore leur code CIM-10 : le rapport lit les deux, sinon
                // l'historique se viderait du jour au lendemain.
                $code = $diagnostic['code_cim11'] ?? $diagnostic['code_cim10'] ?? null;
                $cle = $this->pathologieDe($libelle, $code) ?? 'autres';

                $lignes[$cle][$petit]++;
                $lignes[$cle]['total']++;
            }
        }

        return [
            'lignes' => collect($lignes)->filter(fn ($l) => $l['total'] > 0)->all(),
            'toutes_lignes' => $lignes,
            'total_diagnostics' => $totalDiagnostics,
            'consultations' => $consultations->count(),
        ];
    }

    /** À quelle pathologie du canevas rattacher ce diagnostic ? */
    private function pathologieDe(string $libelle, ?string $codeCim): ?string
    {
        $normalise = mb_strtolower($libelle);
        $code = strtoupper(trim((string) $codeCim));

        foreach (self::PATHOLOGIES as $cle => $pathologie) {
            foreach ($pathologie['mots'] as $mot) {
                if (str_contains($normalise, $mot)) {
                    return $cle;
                }
            }

            if ($code === '') {
                continue;
            }

            // Les deux nomenclatures cohabitent : les dossiers ouverts
            // avant le passage à la CIM-11 gardent leur code CIM-10, et le
            // rapport doit continuer de les classer.
            foreach (array_merge($pathologie['cim'], $pathologie['cim11'] ?? []) as $prefixe) {
                if (str_starts_with($code, $prefixe)) {
                    return $cle;
                }
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════
    // Hospitalisation
    // ═══════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function hospitalisation(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $base = fn () => Visit::query()
            ->where('type', 'hospitalisation')
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId));

        $admissions = $base()->whereBetween('date_entree', [$debut, $fin])->get();
        $sorties = $base()->whereBetween('date_sortie', [$debut, $fin])->get();

        // Journées d'hospitalisation : chaque séjour compte les nuits qu'il a
        // passées dans le mois, y compris s'il l'enjambe.
        $journees = $base()
            ->where('date_entree', '<=', $fin)
            ->where(fn ($q) => $q->whereNull('date_sortie')->orWhere('date_sortie', '>=', $debut))
            ->get()
            ->sum(function (Visit $visite) use ($debut, $fin) {
                $entree = $visite->date_entree->greaterThan($debut) ? $visite->date_entree : $debut;
                $sortie = $visite->date_sortie && $visite->date_sortie->lessThan($fin)
                    ? $visite->date_sortie
                    : $fin;

                return max(1, (int) $entree->startOfDay()->diffInDays($sortie->startOfDay()));
            });

        return [
            'admissions' => $admissions->count(),
            'sorties' => $sorties->count(),
            'journees' => $journees,
            'duree_moyenne' => $sorties->count() > 0
                ? round($sorties->avg(fn ($v) => $v->duree_sejour_jours ?? 0), 1)
                : 0,
            'par_issue' => collect(Visit::MODES_SORTIE)
                ->mapWithKeys(fn ($libelle, $cle) => [$libelle => $sorties->where('mode_sortie', $cle)->count()])
                ->filter(),
            'par_service' => $admissions->groupBy(fn ($v) => $v->service?->nom ?? 'Service non précisé')
                ->map->count()->sortDesc(),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Santé de la mère et du nouveau-né
    // ═══════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function maternite(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $cpn = ConsultationPrenatale::query()
            ->whereBetween('date_consultation', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->whereHas(
                'grossesse', fn ($g) => $g->where('establishment_id', $etablissementId)
            ))
            ->get();

        $accouchements = Accouchement::query()
            ->with('grossesse')
            ->whereBetween('date_accouchement', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->whereHas(
                'grossesse', fn ($g) => $g->where('establishment_id', $etablissementId)
            ))
            ->get();

        $nouveauNes = NouveauNe::whereIn('accouchement_id', $accouchements->pluck('id'))->get();

        return [
            'cpn_total' => $cpn->count(),
            'cpn_par_rang' => collect([1, 2, 3, 4])
                ->mapWithKeys(fn ($rang) => [
                    'CPN '.$rang.($rang === 4 ? ' et plus' : '') => $rang === 4
                        ? $cpn->where('numero', '>=', 4)->count()
                        : $cpn->where('numero', $rang)->count(),
                ]),
            'vat_administres' => $cpn->whereNotNull('vat_dose')->count(),
            'sp_administres' => $cpn->where('sulfadoxine_pyrimethamine', true)->count(),
            'fer_folates' => $cpn->where('fer_folates', true)->count(),
            'moustiquaires' => $cpn->where('moustiquaire_remise', true)->count(),
            'accouchements' => $accouchements->count(),
            'par_mode' => collect(Accouchement::MODES)
                ->mapWithKeys(fn ($libelle, $cle) => [$libelle => $accouchements->where('mode', $cle)->count()])
                ->filter(),
            'cesariennes' => $accouchements->where('mode', 'cesarienne')->count(),
            'hemorragies' => $accouchements->filter->estHemorragique()->count(),
            'naissances_vivantes' => $nouveauNes->where('statut', 'vivant')->count(),
            'mort_nes' => $nouveauNes->where('statut', 'mort_ne')->count(),
            'deces_neonatals' => $nouveauNes->where('statut', 'decede')->count(),
            'petits_poids' => $nouveauNes->filter->estPetitPoids()->count(),
            // Un décès maternel est un décès survenu pendant le séjour
            // d'accouchement : le canevas le compte à part, il pèse lourd.
            'deces_maternels' => $accouchements
                ->filter(fn (Accouchement $a) => $a->visit_id
                    && Visit::whereKey($a->visit_id)->where('mode_sortie', 'deces')->exists())
                ->count(),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Plateaux techniques et pharmacie
    // ═══════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function laboratoire(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $examens = ExamenLaboratoire::query()
            ->with('resultats.typeExamen')
            ->whereBetween('date_prescription', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->whereHas(
                'patient', fn ($p) => $p->where('establishment_id', $etablissementId)
            ))
            ->get();

        $labo = $examens->where('domaine', '!=', 'imagerie');

        return [
            'demandes_labo' => $labo->count(),
            'demandes_imagerie' => $examens->where('domaine', 'imagerie')->count(),
            'validees' => $examens->where('statut', 'valide')->count(),
            'par_examen' => $labo->flatMap->resultats
                ->groupBy(fn ($r) => $r->typeExamen?->libelle ?? 'Examen non précisé')
                ->map->count()
                ->sortDesc()
                ->take(15),
        ];
    }

    /** @return array<string, mixed> */
    private function sang(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $poches = PocheSang::query()
            ->whereBetween('date_prelevement', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        $transfusions = Transfusion::whereBetween('jour', [$debut, $fin])->get();

        return [
            'poches_collectees' => $poches->count(),
            'poches_detruites' => $poches->where('statut', 'detruite')->count(),
            'poches_perimees' => $poches->where('statut', 'perimee')->count(),
            'transfusions' => $transfusions->count(),
            'incidents' => $transfusions->filter->avecIncident()->count(),
            'par_groupe' => collect(PocheSang::GROUPES)
                ->mapWithKeys(fn ($g) => [$g => $poches->where('groupe_sanguin', $g)->count()])
                ->filter(),
        ];
    }

    /**
     * Ruptures de stock : l'indicateur que la zone de santé regarde en premier.
     *
     * @return array<string, mixed>
     */
    private function pharmacie(?string $etablissementId): array
    {
        $stocks = StockMedicament::query()
            ->with('medicament')
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get()
            ->filter(fn ($s) => $s->medicament?->est_actif);

        $ruptures = $stocks->filter(fn ($s) => (float) $s->quantite_disponible <= 0);

        return [
            'references' => $stocks->unique('medicament_id')->count(),
            'ruptures' => $ruptures->unique('medicament_id')->count(),
            'sous_alerte' => $stocks->filter(fn ($s) => (float) $s->quantite_disponible > 0
                && (float) $s->quantite_disponible <= (float) $s->quantite_alerte)
                ->unique('medicament_id')->count(),
            'produits_en_rupture' => $ruptures->map(fn ($s) => $s->medicament?->designation())
                ->filter()->unique()->sort()->values()->take(20),
        ];
    }

    /** @return array<string, mixed> */
    private function deces(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $visites = Visit::query()
            ->with('patient')
            ->where('mode_sortie', 'deces')
            ->whereBetween('date_sortie', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        $parTranche = [];

        foreach (self::TRANCHES as $cle => $tranche) {
            $parTranche[$tranche['libelle']] = 0;
        }

        foreach ($visites as $visite) {
            $tranche = self::TRANCHES[$this->trancheDe($visite->patient, $visite->date_sortie ?? $fin)]['libelle'];
            $parTranche[$tranche]++;
        }

        return [
            'total' => $visites->count(),
            'par_tranche' => collect($parTranche)->filter(),
            'moins_48h' => $visites->filter(fn (Visit $v) => $v->date_sortie
                && $v->date_entree->diffInHours($v->date_sortie) < 48)->count(),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Export
    // ═══════════════════════════════════════════════════════════

    /**
     * Le rapport en tableur, pour le remonter à la zone de santé.
     *
     * Point-virgule et BOM : c'est ce qu'Excel francophone attend, et une
     * colonne unique pleine de virgules à l'ouverture décourage plus sûrement
     * qu'une absence d'export.
     */
    public function versCsv(array $rapport, string $etablissement): string
    {
        $lignes = new Collection;

        $lignes->push(['RAPPORT MENSUEL SNIS']);
        $lignes->push(['Établissement', $etablissement]);
        $lignes->push(['Période', $rapport['periode']['libelle']]);
        $lignes->push(['Édité le', now()->format('d/m/Y H:i')]);
        $lignes->push([]);

        $lignes->push(['1. CONSULTATIONS CURATIVES']);
        $lignes->push(['Tranche d\'âge', 'Nouveaux cas H', 'Nouveaux cas F', 'Anciens cas H', 'Anciens cas F', 'Total']);
        foreach ($rapport['consultations']['lignes'] as $ligne) {
            $lignes->push([$ligne['libelle'], $ligne['nouveaux_m'], $ligne['nouveaux_f'],
                $ligne['anciens_m'], $ligne['anciens_f'], $ligne['total']]);
        }
        $lignes->push(['TOTAL', '', '', '', '', $rapport['consultations']['total']]);
        $lignes->push(['dont passages aux urgences', $rapport['consultations']['urgences']]);
        $lignes->push([]);

        $lignes->push(['2. MORBIDITÉ']);
        $lignes->push(['Pathologie', 'Moins de 5 ans', '5 ans et plus', 'Total']);
        foreach ($rapport['morbidite']['toutes_lignes'] as $ligne) {
            $lignes->push([$ligne['libelle'], $ligne['moins_5ans'], $ligne['plus_5ans'], $ligne['total']]);
        }
        $lignes->push(['TOTAL DIAGNOSTICS', '', '', $rapport['morbidite']['total_diagnostics']]);
        $lignes->push([]);

        $lignes->push(['3. HOSPITALISATION']);
        $lignes->push(['Admissions', $rapport['hospitalisation']['admissions']]);
        $lignes->push(['Sorties', $rapport['hospitalisation']['sorties']]);
        $lignes->push(['Journées d\'hospitalisation', $rapport['hospitalisation']['journees']]);
        $lignes->push(['Durée moyenne de séjour (jours)', $rapport['hospitalisation']['duree_moyenne']]);
        foreach ($rapport['hospitalisation']['par_issue'] as $issue => $nombre) {
            $lignes->push(['Sorties — '.$issue, $nombre]);
        }
        $lignes->push([]);

        $lignes->push(['4. SANTÉ DE LA MÈRE ET DU NOUVEAU-NÉ']);
        foreach ($rapport['maternite']['cpn_par_rang'] as $rang => $nombre) {
            $lignes->push([$rang, $nombre]);
        }
        foreach ([
            'Vaccin antitétanique administré' => 'vat_administres',
            'SP (traitement préventif du paludisme)' => 'sp_administres',
            'Fer et acide folique' => 'fer_folates',
            'Moustiquaires imprégnées remises' => 'moustiquaires',
            'Accouchements' => 'accouchements',
            'dont césariennes' => 'cesariennes',
            'dont hémorragies de la délivrance' => 'hemorragies',
            'Naissances vivantes' => 'naissances_vivantes',
            'Mort-nés' => 'mort_nes',
            'Décès néonatals' => 'deces_neonatals',
            'Nouveau-nés de petit poids (< 2500 g)' => 'petits_poids',
            'Décès maternels' => 'deces_maternels',
        ] as $libelle => $cle) {
            $lignes->push([$libelle, $rapport['maternite'][$cle]]);
        }
        $lignes->push([]);

        $lignes->push(['5. LABORATOIRE ET IMAGERIE']);
        $lignes->push(['Demandes de laboratoire', $rapport['laboratoire']['demandes_labo']]);
        $lignes->push(['Demandes d\'imagerie', $rapport['laboratoire']['demandes_imagerie']]);
        $lignes->push(['Bilans validés', $rapport['laboratoire']['validees']]);
        foreach ($rapport['laboratoire']['par_examen'] as $examen => $nombre) {
            $lignes->push([$examen, $nombre]);
        }
        $lignes->push([]);

        $lignes->push(['6. TRANSFUSION SANGUINE']);
        $lignes->push(['Poches collectées', $rapport['sang']['poches_collectees']]);
        $lignes->push(['Poches détruites au dépistage', $rapport['sang']['poches_detruites']]);
        $lignes->push(['Poches périmées', $rapport['sang']['poches_perimees']]);
        $lignes->push(['Transfusions réalisées', $rapport['sang']['transfusions']]);
        $lignes->push(['Incidents transfusionnels', $rapport['sang']['incidents']]);
        $lignes->push([]);

        $lignes->push(['7. PHARMACIE']);
        $lignes->push(['Références au catalogue', $rapport['pharmacie']['references']]);
        $lignes->push(['Produits en rupture', $rapport['pharmacie']['ruptures']]);
        $lignes->push(['Produits sous seuil d\'alerte', $rapport['pharmacie']['sous_alerte']]);
        $lignes->push([]);

        $lignes->push(['8. DÉCÈS']);
        $lignes->push(['Total', $rapport['deces']['total']]);
        $lignes->push(['dont survenus dans les 48 premières heures', $rapport['deces']['moins_48h']]);
        foreach ($rapport['deces']['par_tranche'] as $tranche => $nombre) {
            $lignes->push([$tranche, $nombre]);
        }
        $lignes->push([]);

        $lignes->push(['RUBRIQUES NON SUIVIES PAR L\'APPLICATION — à reprendre du registre papier']);
        foreach ($rapport['non_suivi'] as $rubrique) {
            $lignes->push([$rubrique]);
        }

        $sortie = fopen('php://temp', 'r+');

        foreach ($lignes as $ligne) {
            fputcsv($sortie, $ligne, ';', '"', '\\');
        }

        rewind($sortie);
        $contenu = stream_get_contents($sortie);
        fclose($sortie);

        // BOM : sans lui, Excel francophone mange les accents.
        return "\u{FEFF}".$contenu;
    }
}
