<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ReferentielMedical;
use App\Models\Visit;
use Illuminate\Support\Collection;

/**
 * Le diagnostic, posé une fois et repris partout.
 *
 * Le médecin écrivait « paludisme grave » en consultation, puis le
 * réécrivait dans l'indication du bon d'examen, puis dans celle de la
 * transfusion, puis dans le bulletin de sortie. Quatre saisies pour une
 * seule idée, et quatre occasions de diverger — c'est ainsi qu'un dossier
 * finit par porter trois diagnostics différents pour un même épisode, et
 * qu'on ne sait plus lequel croire.
 *
 * Le diagnostic de l'épisode se lit désormais là où on en a besoin. Il est
 * proposé, jamais imposé : un examen peut être demandé pour une raison qui
 * n'est pas le diagnostic principal, et le champ reste libre.
 */
class DiagnosticService
{
    /**
     * Le référentiel proposé à la saisie, prêt pour une liste déroulante.
     *
     * La valeur porte le code entre parenthèses — « Paludisme grave (1F45) »
     * — parce qu'un champ de saisie libre avec liste de suggestions ne peut
     * pas remplir un second champ tout seul sans script. Le serveur sépare
     * ensuite le libellé du code.
     *
     * @return Collection<int, ReferentielMedical>
     */
    public function referentiel(): Collection
    {
        return ReferentielMedical::where('type', 'diagnostic')
            ->where('est_actif', true)
            ->orderBy('categorie')
            ->orderBy('libelle')
            ->get();
    }

    /** Ce qu'on écrit dans la liste : le libellé, puis le code. */
    public function proposition(ReferentielMedical $entree): string
    {
        return $entree->code
            ? $entree->libelle.' ('.$entree->code.')'
            : $entree->libelle;
    }

    /**
     * Sépare ce que le médecin a écrit en libellé et code CIM-11.
     *
     * Trois cas : il a choisi dans la liste (« Paludisme grave (1F45) »), il
     * a tapé un libellé libre (« suspicion de dengue »), ou il a tapé un code
     * seul. Aucun ne doit être perdu : un catalogue incomplet ne doit jamais
     * empêcher de poser un diagnostic.
     *
     * @return array{libelle: string, code_cim11: ?string}
     */
    public function decomposer(?string $saisie): array
    {
        $saisie = trim((string) $saisie);

        if ($saisie === '') {
            return ['libelle' => '', 'code_cim11' => null];
        }

        // « Paludisme grave (1F45) » — le code se lit entre les parenthèses
        // finales, et seulement là : « Paludisme (grave) » n'est pas un code.
        if (preg_match('/^(.*?)\s*\(([A-Z0-9][A-Z0-9.]{1,9})\)$/u', $saisie, $trouve)) {
            $libelle = trim($trouve[1]);
            $code = mb_strtoupper($trouve[2]);

            return $libelle !== ''
                ? ['libelle' => $libelle, 'code_cim11' => $code]
                : ['libelle' => $code, 'code_cim11' => $code];
        }

        // Un code seul : on lui rend son libellé officiel s'il est connu.
        if (preg_match('/^[A-Z0-9][A-Z0-9.]{2,9}$/u', $saisie)) {
            $connu = ReferentielMedical::where('type', 'diagnostic')
                ->whereRaw('UPPER(code) = ?', [mb_strtoupper($saisie)])
                ->first();

            if ($connu) {
                return ['libelle' => $connu->libelle, 'code_cim11' => $connu->code];
            }
        }

        // Saisie libre : le libellé fait foi, sans code. C'est le cas d'un
        // diagnostic rare, d'une suspicion, ou d'une formulation propre au
        // service — tous parfaitement légitimes.
        return ['libelle' => $saisie, 'code_cim11' => null];
    }

    /**
     * Les diagnostics de l'épisode en cours, du plus récent au plus ancien.
     *
     * Un séjour peut porter plusieurs consultations : c'est la dernière qui
     * dit où en est le raisonnement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function diagnosticsDuSejour(?Visit $visit): array
    {
        if (! $visit) {
            return [];
        }

        $consultation = $visit->consultations()
            ->orderByDesc('date_consultation')
            ->first();

        return $this->diagnosticsDe($consultation);
    }

    /** @return array<int, array<string, mixed>> */
    public function diagnosticsDe(?Consultation $consultation): array
    {
        $diagnostics = $consultation?->diagnostics;

        if (! is_array($diagnostics)) {
            return [];
        }

        // Le principal d'abord : c'est celui qu'on reprend quand on n'en
        // reprend qu'un.
        return collect($diagnostics)
            ->filter(fn ($d) => filled($d['libelle'] ?? null))
            ->sortBy(fn ($d) => ($d['type'] ?? 'principal') === 'principal' ? 0 : 1)
            ->values()
            ->all();
    }

    /**
     * Le diagnostic à reprendre dans une indication, en une ligne.
     *
     * C'est ce qui remplit d'avance le motif d'un bon d'examen, d'une
     * demande de sang ou d'un acte : le médecin le corrige s'il ne
     * correspond pas, mais il ne le retape pas.
     */
    public function pourIndication(?Visit $visit): ?string
    {
        $diagnostics = $this->diagnosticsDuSejour($visit);

        if ($diagnostics === []) {
            return null;
        }

        return collect($diagnostics)
            ->take(2)
            ->map(fn ($d) => trim($d['libelle'].(filled($d['code_cim11'] ?? null) ? ' ('.$d['code_cim11'].')' : '')))
            ->implode(' · ');
    }

    /** Le diagnostic principal seul, sans son code. */
    public function principal(?Visit $visit): ?string
    {
        return $this->diagnosticsDuSejour($visit)[0]['libelle'] ?? null;
    }

    /**
     * Le code d'un diagnostic, quel que soit le champ qui le porte.
     *
     * Les dossiers ouverts avant le passage à la CIM-11 gardent leur code
     * CIM-10 : un dossier ne se réécrit pas, et l'ancien code reste
     * exploitable pour les rapports.
     *
     * @param  array<string, mixed>  $diagnostic
     */
    public function code(array $diagnostic): ?string
    {
        return $diagnostic['code_cim11'] ?? $diagnostic['code_cim10'] ?? null;
    }
}
