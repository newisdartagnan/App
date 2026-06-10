<div>
    @if($dispensationComplete)
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl p-6 text-center">
        <p class="text-2xl mb-2">✅</p>
        <p class="font-semibold text-lg">Dispensation effectuée avec succès</p>
        <a href="{{ route('pharmacie.prescriptions') }}"
           class="mt-4 inline-block px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
            Retour aux ordonnances
        </a>
    </div>
    @else
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4">
        <p class="font-semibold text-blue-900">{{ $prescription->patient->nom_complet }}</p>
        <p class="text-sm text-blue-700">
            Prescrit par Dr {{ $prescription->prescripteur->nom }} — {{ $prescription->date_prescription->format('d/m/Y H:i') }}
        </p>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Médicament</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Posologie</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Stock dispo</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Qté à dispenser</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($prescription->lignes as $ligne)
                <tr class="{{ isset($erreurs[$ligne->id]) ? 'bg-red-50' : '' }}">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $ligne->medicament->denomination_commune }}</p>
                        <p class="text-xs text-gray-400">{{ $ligne->medicament->dosage }} — {{ $ligne->medicament->forme }}</p>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                        {{ $ligne->dose }} — {{ $ligne->frequence }}
                        @if($ligne->duree_jours) pendant {{ $ligne->duree_jours }} jours @endif
                        @if($ligne->instructions)
                        <p class="text-xs text-gray-400">{{ $ligne->instructions }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php $stock = $ligne->medicament->stock; @endphp
                        <span class="font-bold {{ !$stock || $stock->quantite_disponible <= 0 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $stock?->quantite_disponible ?? 0 }}
                        </span>
                        <span class="text-xs text-gray-400"> {{ $ligne->medicament->unite_dispensation }}</span>
                        @if(isset($erreurs[$ligne->id]))
                        <p class="text-red-600 text-xs mt-1">{{ $erreurs[$ligne->id] }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <input wire:model="quantites.{{ $ligne->id }}" type="number" min="0"
                            step="1"
                            class="w-24 min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 focus:border-blue-500
                            {{ isset($erreurs[$ligne->id]) ? 'border-red-500' : '' }}">
                        <span class="text-xs text-gray-400 ml-1">{{ $ligne->medicament->unite_dispensation }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="flex justify-end gap-3">
        <a href="{{ route('pharmacie.prescriptions') }}"
           class="px-5 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
            Annuler
        </a>
        <button wire:click="dispenser" type="button"
            class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition">
            ✓ Confirmer la dispensation
        </button>
    </div>
    @endif
</div>