<div>
    <form wire:submit="save" class="space-y-4">

        @foreach($lignes as $i => $ligne)
        <div class="bg-white rounded-xl shadow p-5 relative">
            <div class="flex justify-between items-center mb-4 pb-2 border-b">
                <span class="font-semibold text-gray-700 text-sm">Médicament {{ $i + 1 }}</span>
                @if(count($lignes) > 1)
                <button wire:click="retirerLigne({{ $i }})" type="button"
                    class="text-red-500 hover:text-red-700 text-xs font-medium">✕ Retirer</button>
                @endif
            </div>

            {{-- Recherche médicament --}}
            <div class="mb-4 relative">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Médicament <span class="text-red-500">*</span>
                </label>
                <input
                    wire:model="lignes.{{ $i }}.medicament_nom"
                    wire:keyup="searchMedicament({{ $i }})"
                    type="text"
                    placeholder="Tapez DCI ou nom commercial..."
                    autocomplete="off"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                    @error('lignes.' . $i . '.medicament_id') border-red-500 @enderror">
                @error('lignes.' . $i . '.medicament_id')
                <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                @enderror

                {{-- Résultats autocomplete --}}
                @if($ligneActive === $i && count($resultsMed) > 0)
                <div class="absolute z-50 w-full bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto">
                    @foreach($resultsMed as $med)
                    <button wire:click="selectMedicament('{{ $med['id'] }}', '{{ addslashes($med['label']) }}', {{ $i }})"
                        type="button"
                        class="w-full text-left px-4 py-2 hover:bg-blue-50 text-sm border-b border-gray-100 last:border-0">
                        <span class="font-medium">{{ $med['label'] }}</span>
                        <span class="ml-2 text-xs {{ $med['stock'] <= 0 ? 'text-red-500' : 'text-green-600' }}">
                            Stock: {{ $med['stock'] }} {{ $med['unite'] }}
                        </span>
                    </button>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Posologie --}}
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Dose <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="lignes.{{ $i }}.dose" type="text"
                        placeholder="Ex: 1 comprimé, 5ml"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                        @error('lignes.' . $i . '.dose') border-red-500 @enderror">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Fréquence <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="lignes.{{ $i }}.frequence" type="text"
                        placeholder="Ex: 3 fois par jour"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                        @error('lignes.' . $i . '.frequence') border-red-500 @enderror">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Durée (jours)</label>
                    <input wire:model="lignes.{{ $i }}.duree_jours" type="number" min="1"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Quantité totale <span class="text-red-500">*</span>
                    </label>
                    <input wire:model="lignes.{{ $i }}.quantite_totale" type="number" step="0.5" min="0.5"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                        @error('lignes.' . $i . '.quantite_totale') border-red-500 @enderror">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Voie d'administration</label>
                    <select wire:model="lignes.{{ $i }}.voie_administration"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                        <option value="orale">Orale</option>
                        <option value="injectable_iv">Injectable IV</option>
                        <option value="injectable_im">Injectable IM</option>
                        <option value="topique">Topique</option>
                        <option value="rectale">Rectale</option>
                        <option value="ophtalmique">Ophtalmique</option>
                        <option value="autre">Autre</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Instructions</label>
                    <input wire:model="lignes.{{ $i }}.instructions" type="text"
                        placeholder="Ex: Après repas, à jeun..."
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
            </div>

            <label for="observations" class="flex items-center gap-2 text-sm text-gray-600">
                <input wire:model="lignes.{{ $i }}.est_substituable" type="checkbox" class="rounded">
                Substituable (générique accepté)
            </label>
        </div>
        @endforeach

        {{-- Ajouter ligne --}}
        <button wire:click="ajouterLigne" type="button"
            class="w-full py-3 border-2 border-dashed border-gray-300 hover:border-blue-400 text-gray-500
            hover:text-blue-600 rounded-xl transition text-sm font-medium">
            + Ajouter un médicament
        </button>

        {{-- Observations --}}
        <div class="bg-white rounded-xl shadow p-5">
            <label class="block text-sm font-medium text-gray-700 mb-1">Observations</label>
            <textarea id="observations" name="observations" wire:model="observations" rows="2"
                placeholder="Remarques pour le pharmacien..."
                class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500"></textarea>
        </div>

        {{-- Actions --}}
        <div class="flex justify-between pb-6">
            <a href="{{ route('consultations.show', $consultation) }}"
               class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                Annuler
            </a>
            <button type="submit"
                class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition">
                ✓ Valider l'ordonnance
            </button>
        </div>
    </form>
</div>