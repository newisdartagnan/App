<div>
    @if(!$showForm)
    <button wire:click="$set('showForm', true)"
        class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition mb-4">
        + Ajouter un médicament
    </button>
    @else
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <div class="flex justify-between items-center mb-4 pb-2 border-b">
            <h3 class="font-semibold text-gray-700">Nouveau médicament</h3>
            <button wire:click="$set('showForm', false)" class="text-gray-400 hover:text-gray-600">✕</button>
        </div>
        <div class="grid grid-cols-3 gap-4">
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">DCI (Dénomination Commune) <span class="text-red-500">*</span></label>
                <input wire:model="denomination_commune" type="text"
                    placeholder="Ex: Amoxicilline"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('denomination_commune') border-red-500 @enderror">
                @error('denomination_commune')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nom commercial</label>
                <input wire:model="nom_commercial" type="text"
                    placeholder="Ex: Clamoxyl"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Forme <span class="text-red-500">*</span></label>
                <select wire:model="forme" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    <option value="comprime">Comprimé</option>
                    <option value="gelule">Gélule</option>
                    <option value="sirop">Sirop</option>
                    <option value="injectable">Injectable</option>
                    <option value="pommade">Pommade</option>
                    <option value="sachet">Sachet</option>
                    <option value="autre">Autre</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Dosage <span class="text-red-500">*</span></label>
                <input wire:model="dosage" type="text" placeholder="Ex: 500mg"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('dosage') border-red-500 @enderror">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unité dispensation <span class="text-red-500">*</span></label>
                <input wire:model="unite_dispensation" type="text" placeholder="Ex: comprimé, flacon"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Classe thérapeutique</label>
                <input wire:model="classe_therapeutique" type="text" placeholder="Ex: Antibiotique"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Prix vente (CDF) <span class="text-red-500">*</span></label>
                <input wire:model="prix_unitaire_vente" type="number" step="0.01"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('prix_unitaire_vente') border-red-500 @enderror">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Prix achat (CDF)</label>
                <input wire:model="prix_unitaire_achat" type="number" step="0.01"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Seuil alerte stock</label>
                <input wire:model="quantite_alerte" type="number"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date péremption</label>
                <input wire:model="date_peremption" type="date"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">N° lot</label>
                <input wire:model="lot" type="text"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
            <div class="flex items-center gap-2 mt-4">
                <input wire:model="necessite_ordonnance" type="checkbox" id="ordonnance" class="rounded">
                <label for="ordonnance" class="text-sm text-gray-700">Nécessite ordonnance</label>
            </div>
        </div>
        <div class="flex justify-end gap-3 mt-4">
            <button wire:click="$set('showForm', false)" type="button"
                class="px-5 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                Annuler
            </button>
            <button wire:click="save" type="button"
                class="px-5 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg">
                Enregistrer
            </button>
        </div>
    </div>
    @endif
</div>