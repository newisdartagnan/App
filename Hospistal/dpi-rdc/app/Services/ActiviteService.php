<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Le journal d'audit, lu comme une liste d'activités : « a enregistré un
 * patient », « a modifié une consultation ». Le journal reste brut et
 * inviolable en base ; cette classe ne fait que le traduire.
 */
class ActiviteService
{
    public const OBJETS = [
        'patients' => 'un patient',
        'visits' => 'un épisode de maladie',
        'consultations' => 'une consultation',
        'prescriptions' => 'une prescription',
        'examens_laboratoire' => 'un examen',
        'dispensations' => 'une distribution de produit',
        'signes_vitaux' => 'des signes vitaux',
        'documents_cliniques' => 'un document du dossier',
        'administrations_traitement' => 'une administration de produit',
        'vaccinations' => 'une vaccination',
        'rendez_vous' => 'un rendez-vous',
        'notes_evolution' => 'une observation de suivi',
        'actes_cliniques' => 'un acte',
        'transferts_service' => 'un transfert de service',
        'factures' => 'une facture',
        'lignes_facture' => 'une ligne de facture',
        'paiements' => 'un paiement',
        'avoirs' => 'un avoir',
        'users' => 'un compte utilisateur',
        'relations_familiales' => 'une relation familiale',
        'messages' => 'un message',
        'depenses' => 'une dépense',
        'ambulance_sorties' => 'une sortie d\'ambulance',
    ];

    public const VERBES = [
        'create' => 'a enregistré',
        'update' => 'a modifié',
        'delete' => 'a supprimé',
    ];

    public function journal(?User $user, ?string $du = null, ?string $au = null, int $parPage = 30): LengthAwarePaginator
    {
        return AuditLog::with('user')
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->when($du, fn ($q) => $q->where('created_at', '>=', $du.' 00:00:00'))
            ->when($au, fn ($q) => $q->where('created_at', '<=', $au.' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate($parPage)
            ->withQueryString();
    }

    public function libelle(AuditLog $log): string
    {
        if ($log->action === 'auth.connexion') {
            return 's\'est connecté(e)';
        }
        if ($log->action === 'auth.deconnexion_forcee') {
            return 'a déconnecté tous les utilisateurs';
        }
        if ($log->action === 'maintenance.sauvegarde') {
            return 'a lancé une sauvegarde de la base de données';
        }
        if ($log->action === 'maintenance.vidage') {
            return 'a vidé des tables : '.implode(', ', (array) ($log->new_values['tables'] ?? []));
        }

        $verbe = self::VERBES[str($log->action)->afterLast('.')->value()] ?? $log->action;
        $objet = self::OBJETS[$log->table_name] ?? ($log->table_name ? 'dans « '.$log->table_name.' »' : '');

        return trim($verbe.' '.$objet);
    }
}
