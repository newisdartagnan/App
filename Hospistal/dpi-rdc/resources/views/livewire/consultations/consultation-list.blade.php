<div>
    <div class="bg-white rounded-xl shadow p-4 mb-4">
        <div class="flex gap-3">
            <input wire:model.live.debounce.300ms="search" type="text"
                placeholder="Nom patient, n° dossier..."
                class="flex-1 min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            <select wire:model.live="statut"
                class="min-h-[44px] rounded-lg border border-gray-300 px-3 focus:border-blue-500">
                <option value="">Tous statuts</option>
                <option value="en_attente">En attente</option>
                <option value="en_cours">En cours</option>
                <option value="termine">Terminé</option>
            </select>
            <input wire:model.live="date" type="date"
                class="min-h-[44px] rounded-lg border border-gray-300 px-3 focus:border-blue-500">
        </div>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Patient</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Type</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Motif</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($visits as $visit)
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $visit->patient->nom_complet }}</p>
                        <p class="text-xs text-gray-400">{{ $visit->patient->dossier_number }}</p>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $visit->date_entree->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs
                            {{ $visit->type === 'urgence' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' }}">
                            {{ $visit->type === 'urgence' ? '🚨 Urgence' : 'Consultation' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600 max-w-xs truncate">{{ $visit->motif_consultation }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs
                            {{ $visit->statut === 'termine' ? 'bg-green-100 text-green-700' :
                               ($visit->statut === 'en_cours' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600') }}">
                            {{ ucfirst(str_replace('_', ' ', $visit->statut)) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @if($visit->consultations->first())
                        <a href="{{ route('consultations.show', $visit->consultations->first()) }}"
                           class="text-blue-700 hover:underline text-xs font-medium">Voir</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucune consultation trouvée</td>
                </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $visits->links() }}</div>
    </div>
</div>