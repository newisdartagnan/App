<?php

namespace App\Services\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Mise en forme pour les écrans du patient : dates en heure de Kinshasa et
 * en français, montants, noms des médecins. Le serveur fait ces calculs pour
 * que l'affichage ne dépende pas du fuseau du téléphone.
 */
final class Affichage
{
    public static function jour(DateTimeInterface $instant): string
    {
        $date = CreneauxService::local($instant);
        $aujourdhui = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $libelle = $date->locale('fr')->isoFormat('dddd D MMMM');

        return match ((int) round($aujourdhui->diffInDays($date->startOfDay(), false))) {
            0 => 'Aujourd\'hui, '.$libelle,
            1 => 'Demain, '.$libelle,
            default => $libelle,
        };
    }

    public static function heure(DateTimeInterface $instant): string
    {
        return CreneauxService::local($instant)->format('H:i');
    }

    public static function dateHeure(DateTimeInterface $instant): string
    {
        return self::jour($instant).' à '.self::heure($instant);
    }

    public static function montant(?string $prix, string $devise): ?string
    {
        if ($prix === null) {
            return null;
        }

        $decimales = $devise === 'CDF' ? 0 : 2;

        return number_format((float) $prix, $decimales, ',', ' ').' '.$devise;
    }

    public static function medecin(Medecin $medecin): string
    {
        return trim('Dr '.$medecin->user?->prenom.' '.mb_strtoupper((string) $medecin->user?->nom));
    }

    /**
     * Créneaux regroupés par jour, pour l'écran de choix.
     *
     * @param  iterable<array{debut: CarbonImmutable, medecin?: ?Medecin}>  $creneaux
     * @return list<array{date: string, libelle: string, creneaux: list<array{debut: string, heure: string, medecin_id: ?string, medecin: ?string}>}>
     */
    public static function parJour(iterable $creneaux): array
    {
        $jours = [];

        foreach ($creneaux as $creneau) {
            $debut = CreneauxService::local($creneau['debut']);
            $cle = $debut->toDateString();
            $jours[$cle] ??= ['date' => $cle, 'libelle' => self::jour($debut), 'creneaux' => []];
            $medecin = $creneau['medecin'] ?? null;
            $jours[$cle]['creneaux'][] = [
                'debut' => $debut->toIso8601String(),
                'heure' => $debut->format('H:i'),
                'medecin_id' => $medecin?->id,
                'medecin' => $medecin ? self::medecin($medecin) : null,
            ];
        }

        return array_values($jours);
    }

    /** Résumé d'un rendez-vous pour la liste « Mes rendez-vous ». */
    public static function resume(RendezVous $rdv): array
    {
        return [
            'id' => $rdv->id,
            'statut' => $rdv->statut->value,
            'statut_libelle' => $rdv->statut->libelle(),
            'quand' => self::dateHeure($rdv->debut),
            'medecin' => self::medecin($rdv->medecin),
            'type' => $rdv->typeConsultation->nom,
            'a_venir' => $rdv->statut->occupeLeCreneau() && $rdv->fin > CarbonImmutable::now(),
            'en_attente' => $rdv->statut === StatutRendezVous::EnAttentePaiement,
        ];
    }
}
