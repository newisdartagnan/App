<?php

namespace App\Livewire\Consultations;

use App\Models\Visit;
use Livewire\Component;
use Livewire\WithPagination;

class ConsultationList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statut = '';

    public string $date = '';

    public string $specialite = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $base = fn () => Visit::with(['patient', 'user', 'consultations', 'typeConsultation'])
            ->when($this->search, function ($q) {
                $q->whereHas('patient', function ($q) {
                    $q->whereRaw('LOWER(nom) LIKE ?', ['%'.strtolower($this->search).'%'])
                        ->orWhereRaw('LOWER(prenom) LIKE ?', ['%'.strtolower($this->search).'%'])
                        ->orWhere('dossier_number', 'like', '%'.$this->search.'%');
                });
            })
            // Un patient reçu aux urgences suit la file des urgences, pas
            // celle des consultations : il n'a rien à faire dans les deux.
            ->where('type', 'consultation_externe');

        // File d'attente : visites payées (ou contrôles gratuits), pas encore
        // consultées — groupée par spécialité, celle du médecin connecté en tête.
        $utilisateur = auth()->user();
        $maSpecialite = $utilisateur->specialite;
        // Un médecin (non admin/directeur) ne voit que sa spécialité, ou la
        // médecine générale s'il est généraliste. Infirmiers et admin voient tout.
        $estMedecin = $utilisateur->hasRole('medecin')
            && ! $utilisateur->hasAnyRole(['super_admin', 'directeur']);

        $fileAttente = $base()
            ->where('statut', 'en_cours')
            ->whereDoesntHave('consultations')
            // Patient déjà entré au cabinet : il est avec un médecin, il ne
            // doit plus apparaître dans la file que les autres consultent.
            ->whereNull('consultation_debutee_at')
            ->orderBy('date_entree')
            ->get();

        if ($estMedecin) {
            $fileAttente = $fileAttente->filter(function ($v) use ($maSpecialite) {
                $specialite = $v->typeConsultation?->specialite ?: 'Médecine générale';

                return $maSpecialite ? $specialite === $maSpecialite : $specialite === 'Médecine générale';
            });
        }

        // Filtre explicite de spécialité, pour qu'un médecin puisse suivre
        // une file précise même quand il en voit plusieurs.
        if ($this->specialite !== '') {
            $fileAttente = $fileAttente->filter(
                fn ($v) => ($v->typeConsultation?->specialite ?: 'Médecine générale') === $this->specialite
            );
        }

        $specialitesEnFile = $fileAttente
            ->map(fn ($v) => $v->typeConsultation?->specialite ?: 'Médecine générale')
            ->unique()->sort()->values();

        $fileParSpecialite = $fileAttente
            ->groupBy(fn ($v) => $v->typeConsultation?->specialite ?: 'Médecine générale')
            ->sortBy(fn ($groupe, $cle) => $maSpecialite && $cle === $maSpecialite ? 0 : 1);

        // Patients actuellement au cabinet : visibles, mais hors file.
        $auCabinet = $base()
            ->where('statut', 'en_cours')
            ->whereDoesntHave('consultations')
            ->whereNotNull('consultation_debutee_at')
            ->with('medecinConsultant')
            ->orderBy('consultation_debutee_at')
            ->get();

        // Envoyés à la caisse, paiement non encore validé
        $enAttentePaiement = $base()
            ->where('statut', 'en_attente')
            ->with('factures')
            ->orderBy('date_entree')
            ->get();

        // Historique des consultations réalisées
        $visits = $base()
            ->whereHas('consultations')
            ->when($this->statut, fn ($q) => $q->where('statut', $this->statut))
            ->when($this->date, fn ($q) => $q->whereDate('date_entree', $this->date))
            ->orderByDesc('date_entree')
            ->paginate(20);

        return view('livewire.consultations.consultation-list', compact(
            'visits', 'fileAttente', 'fileParSpecialite', 'enAttentePaiement',
            'maSpecialite', 'auCabinet', 'specialitesEnFile'
        ));
    }
}
