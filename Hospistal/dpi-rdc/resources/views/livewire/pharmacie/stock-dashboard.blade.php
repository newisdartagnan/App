<div>
    {{-- Stats --}}
    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-blue-700">{{ $stats['total'] }}</p>
            <p class="text-xs text-gray-500 mt-1">Médicaments actifs</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center cursor-pointer {{ $filtre === 'alerte' ? 'ring-2 ring-amber-400' : '' }}"
             wire:click="$set('filtre', '{{ $filtre === 'alerte' ? 'tous' : 'alerte' }}')">
            <p class="text-2xl font-bold text-amber-600">{{ $stats['alerte'] }}</p>
            <p class="text-xs text-gray-500 mt-1">Stock bas</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center cursor-pointer {{ $filtre === 'rupture' ? 'ring-2 ring-red-400' : '' }}"
             wire:click="$set('filtre', '{{ $filtre === 'rupture' ? 'tous' : 'rupture' }}')">
            <p class="text-2xl font-bold text-red-600">{{ $stats['rupture'] }}</p>
            <p class="text-xs text-gray-500 mt-1">Rupture de stock</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center cursor-pointer {{ $filtre === 'peremption' ? 'ring-2 ring-orange-400' : '' }}"
             wire:click="$set('filtre', '{{ $filtre === 'peremption' ? 'tous' : 'peremption' }}')">
            <p class="text-2xl font-bold text-orange-600">{{ $stats['peremption'] }}</p>
            <p class="text-xs text-gray-500 mt-1">Expirent dans 30j</p>
        </div>
    </div>

    {{-- Recherche --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4">
        <input wire:model.live.debounce.300ms="search" type="text"
            placeholder="Rechercher un médicament (DCI, nom commercial)..."
            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
    </div>

    {{-- Tableau --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">DCI / Nom commercial</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Forme</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Stock</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Prix unitaire</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Péremption</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($medicaments as $med)
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $med->denomination_commune }}</p>
                        @if($med->nom_commercial)
                        <p class="text-xs text-gray-400">{{ $med->nom_commercial }}</p>
                        @endif
                        <p class="text-xs text-gray-400">{{ $med->dosage }}</p>
                    </td>
                    <td class="px-4 py-3 text-gray-600 capitalize">{{ $med->forme }}</td>
                    <td class="px-4 py-3">
                        @if($med->stock)
                        <span class="font-bold {{ $med->stock->quantite_disponible <= 0 ? 'text-red-600' : ($med->stock->estEnAlerte() ? 'text-amber-600' : 'text-green-600') }}">
                            {{ $med->stock->quantite_disponible }}
                        </span>
                        <span class="text-xs text-gray-400"> {{ $med->unite_dispensation }}</span>
                        @else
                        <span class="text-gray-400 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        {{ $med->stock?->prix_unitaire_vente ? number_format($med->stock->prix_unitaire_vente, 0, ',', '.') . ' CDF' : '—' }}
                    </td>
                    <td class="px-4 py-3">
                        @if($med->stock?->date_peremption)
                        <span class="{{ $med->stock->estPerime() ? 'text-red-600 font-medium' : ($med->stock->expireBientot() ? 'text-orange-600' : 'text-gray-600') }}">
                            {{ $med->stock->date_peremption->format('d/m/Y') }}
                        </span>
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if(!$med->stock || $med->stock->quantite_disponible <= 0)
                        <span class="px-2 py-1 rounded-full text-xs bg-red-100 text-red-700">Rupture</span>
                        @elseif($med->stock->estEnAlerte())
                        <span class="px-2 py-1 rounded-full text-xs bg-amber-100 text-amber-700">Stock bas</span>
                        @else
                        <span class="px-2 py-1 rounded-full text-xs bg-green-100 text-green-700">Disponible</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                        {{ $search ? 'Aucun médicament trouvé' : 'Stock vide — ajoutez des médicaments' }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $medicaments->links() }}</div>
    </div>
</div>