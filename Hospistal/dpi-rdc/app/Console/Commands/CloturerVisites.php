<?php

namespace App\Console\Commands;

use App\Models\Visit;
use Illuminate\Console\Command;

/**
 * Clôture de fin de journée des passages ambulatoires et des urgences.
 *
 * Un patient venu en consultation externe ou aux urgences repart le jour même,
 * quel que soit le nombre de consultations qu'il a eues : dès l'entame du jour
 * suivant, son séjour est déclaré clos. Seule l'hospitalisation prolonge le
 * séjour — et dans ce cas la visite a changé de type au moment de l'admission,
 * elle sort donc d'elle-même du champ de cette commande.
 *
 * La clôture ne touche pas à l'argent : les factures déjà émises restent
 * payables à la caisse, et les prestations non facturées le restent aussi.
 */
class CloturerVisites extends Command
{
    /** Passages qui se terminent avec la journée. */
    private const TYPES_JOURNALIERS = ['consultation_externe', 'urgence'];

    protected $signature = 'dpi:cloturer-visites';

    protected $description = 'Clôture les passages ambulatoires et urgences des journées écoulées';

    public function handle(): int
    {
        // Tout ce qui a commencé avant aujourd'hui : la journée du patient est
        // finie. Une visite ouverte aujourd'hui est laissée telle quelle.
        $debutDuJour = now()->startOfDay();

        $visites = Visit::whereIn('type', self::TYPES_JOURNALIERS)
            ->whereIn('statut', ['en_attente', 'en_cours'])
            ->where('date_entree', '<', $debutDuJour)
            ->get();

        foreach ($visites as $visite) {
            // La sortie est datée de la fin de la journée d'arrivée, et non de
            // l'instant où la commande tourne : le séjour garde sa durée réelle.
            $visite->update([
                'statut' => 'termine',
                'date_sortie' => $visite->date_entree->copy()->endOfDay(),
                'mode_sortie' => $visite->mode_sortie ?: 'inconnu',
            ]);
        }

        $this->info('Passages clôturés en fin de journée : '.$visites->count());

        return self::SUCCESS;
    }
}
