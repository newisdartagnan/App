{{-- Tableau du stock — rendu serveur, recherche GET, entrée stock en formulaire HTML pur --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <a href="{{ request()->url() }}" class="bg-white rounded-xl shadow p-4 text-center {{ $filtre === 'tous' ? 'ring-2 ring-blue-400' : '' }}">
        <p class="text-2xl font-bold text-blue-700">{{ $stats['total'] }}</p>
        <p class="text-xs text-gray-500 mt-1">Médicaments actifs</p>
    </a>
    <a href="{{ request()->url() }}?filtre=alerte" class="bg-white rounded-xl shadow p-4 text-center {{ $filtre === 'alerte' ? 'ring-2 ring-amber-400' : '' }}">
        <p class="text-2xl font-bold text-amber-600">{{ $stats['alerte'] }}</p>
        <p class="text-xs text-gray-500 mt-1">Stock bas</p>
    </a>
    <a href="{{ request()->url() }}?filtre=rupture" class="bg-white rounded-xl shadow p-4 text-center {{ $filtre === 'rupture' ? 'ring-2 ring-red-400' : '' }}">
        <p class="text-2xl font-bold text-red-600">{{ $stats['rupture'] }}</p>
        <p class="text-xs text-gray-500 mt-1">Rupture de stock</p>
    </a>
    <a href="{{ request()->url() }}?filtre=peremption" class="bg-white rounded-xl shadow p-4 text-center {{ $filtre === 'peremption' ? 'ring-2 ring-orange-400' : '' }}">
        <p class="text-2xl font-bold text-orange-600">{{ $stats['peremption'] }}</p>
        <p class="text-xs text-gray-500 mt-1">Expirent dans 30j</p>
    </a>
</div>

<form method="GET" action="{{ request()->url() }}" class="bg-white rounded-xl shadow p-4 mb-4 flex gap-3">
    <label for="stock-q" class="sr-only">Rechercher un médicament</label>
    <input id="stock-q" name="q" value="{{ $search }}" type="search"
        placeholder="Rechercher un médicament (DCI, nom commercial)..."
        class="flex-1 min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
    @if($filtre !== 'tous')<input type="hidden" name="filtre" value="{{ $filtre }}">@endif
    <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium">Rechercher</button>
</form>

<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b">
            <tr>
                <th class="text-left px-4 py-3 font-medium text-gray-600">DCI / Nom commercial</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Forme</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Stock</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Prix unitaire</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Péremption</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Officine</th>
                <th class="text-left px-4 py-3 font-medium text-gray-600">Statut</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($medicaments as $med)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <p class="font-medium">{{ $med->denomination_commune }}</p>
                    @if($med->nom_commercial)<p class="text-xs text-gray-400">{{ $med->nom_commercial }}</p>@endif
                    <p class="text-xs text-gray-400">{{ $med->dosage }}</p>
                    <details class="mt-1">
                        <summary class="cursor-pointer text-blue-700 text-xs font-medium select-none">📥 Entrée / ajustement de stock</summary>
                        <form method="POST" action="{{ route('pharmacie.stock.mouvement', $med) }}" class="flex flex-wrap items-end gap-2 mt-2 p-3 bg-blue-50 rounded-lg">
                            @csrf
                            <div>
                                <label for="mvt-type-{{ $med->id }}" class="block text-xs text-gray-600 mb-1">Type</label>
                                <select id="mvt-type-{{ $med->id }}" name="type" class="min-h-[40px] rounded border border-gray-300 px-2 py-1 text-xs">
                                    <option value="entree">Entrée (réception)</option>
                                    <option value="ajustement_inventaire">Ajustement (±)</option>
                                    <option value="sortie_peremption">Sortie péremption</option>
                                </select>
                            </div>
                            <div>
                                <label for="mvt-qte-{{ $med->id }}" class="block text-xs text-gray-600 mb-1">Quantité</label>
                                <input id="mvt-qte-{{ $med->id }}" name="quantite" type="number" step="0.5" required
                                    class="w-24 min-h-[40px] rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                            <div>
                                <label for="mvt-lot-{{ $med->id }}" class="block text-xs text-gray-600 mb-1">N° lot</label>
                                <input id="mvt-lot-{{ $med->id }}" name="lot" type="text"
                                    class="w-28 min-h-[40px] rounded border border-gray-300 px-2 py-1 text-xs">
                            </div>
                            <button type="submit" class="min-h-[40px] px-3 py-1 bg-blue-700 hover:bg-blue-800 text-white text-xs font-semibold rounded">
                                Valider
                            </button>
                        </form>
                    </details>
                </td>
                <td class="px-4 py-3 text-gray-600 capitalize">{{ $med->forme }}</td>
                <td class="px-4 py-3">
                    @if($med->stock)
                    <span class="font-bold {{ $med->stock->quantite_disponible <= 0 ? 'text-red-600' : ($med->stock->estEnAlerte() ? 'text-amber-600' : 'text-green-600') }}">
                        {{ $med->stock->quantite_disponible + 0 }}
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
                    @else — @endif
                </td>
                <td class="px-4 py-3 text-xs text-gray-500">
                    {{ $med->stock?->officine?->nom ?? 'Officine ambulatoire' }}
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
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">{{ $search ? 'Aucun médicament trouvé' : 'Stock vide — ajoutez des médicaments' }}</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t">{{ $medicaments->links() }}</div>
</div>
