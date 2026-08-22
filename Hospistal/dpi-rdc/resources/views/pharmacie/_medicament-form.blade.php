{{-- Formulaire d'ajout de médicament — HTML pur (details/summary), aucun JavaScript requis --}}
<details class="bg-white rounded-xl shadow mb-4" {{ $errors->any() ? 'open' : '' }}>
    <summary class="cursor-pointer select-none px-5 py-3 font-semibold text-blue-700 hover:bg-blue-50 rounded-xl">
        + Ajouter un médicament
    </summary>
    <form method="POST" action="{{ route('pharmacie.medicaments.store') }}" class="px-5 pb-5 pt-2 border-t">
        @csrf
        @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
            @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
        </div>
        @endif
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-2">
                <label for="med-dci" class="block text-sm font-medium text-gray-700 mb-1">DCI (Dénomination Commune) <span class="text-red-500">*</span></label>
                <input id="med-dci" name="denomination_commune" value="{{ old('denomination_commune') }}" type="text" placeholder="Ex: Amoxicilline"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-com" class="block text-sm font-medium text-gray-700 mb-1">Nom commercial</label>
                <input id="med-com" name="nom_commercial" value="{{ old('nom_commercial') }}" type="text" placeholder="Ex: Clamoxyl"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-forme" class="block text-sm font-medium text-gray-700 mb-1">Forme <span class="text-red-500">*</span></label>
                <select id="med-forme" name="forme" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    @foreach(['comprime' => 'Comprimé', 'gelule' => 'Gélule', 'sirop' => 'Sirop', 'injectable' => 'Injectable', 'suppositoire' => 'Suppositoire', 'pommade' => 'Pommade', 'creme' => 'Crème', 'collyre' => 'Collyre', 'sachet' => 'Sachet', 'autre' => 'Autre'] as $val => $lbl)
                    <option value="{{ $val }}" @selected(old('forme') === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>

            {{--
                Voie et conditionnement : ce que le médecin lit en prescrivant,
                et ce sur quoi le système arrondit la quantité. Laissés vides,
                ils se déduisent de la forme — mais tous les comprimés ne vont
                pas par dix, et c'est ici qu'on le corrige.
            --}}
            <div>
                <label for="med-voie" class="block text-sm font-medium text-gray-700 mb-1">Voie d'administration</label>
                <select id="med-voie" name="voie_administration" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    <option value="">— d'après la forme —</option>
                    @foreach(\App\Models\Medicament::VOIES as $val => $lbl)
                    <option value="{{ $val }}" @selected(old('voie_administration') === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="med-cond" class="block text-sm font-medium text-gray-700 mb-1">Conditionnement</label>
                <select id="med-cond" name="conditionnement" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    <option value="">— d'après la forme —</option>
                    @foreach(\App\Models\Medicament::CONDITIONNEMENTS as $val => $lbl)
                    <option value="{{ $val }}" @selected(old('conditionnement') === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="med-upc" class="block text-sm font-medium text-gray-700 mb-1">Unités par conditionnement</label>
                <input id="med-upc" name="unites_par_conditionnement" value="{{ old('unites_par_conditionnement') }}"
                    type="number" min="1" max="1000" placeholder="10"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                <p class="text-xs text-gray-500 mt-1">
                    Combien d'unités dans un contenant — 10 comprimés par plaquette.
                    Une prescription de 15 comprimés fera sortir 2 plaquettes.
                </p>
            </div>
            <div>
                <label for="med-dosage" class="block text-sm font-medium text-gray-700 mb-1">Dosage <span class="text-red-500">*</span></label>
                <input id="med-dosage" name="dosage" value="{{ old('dosage') }}" type="text" placeholder="Ex: 500mg"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-unite" class="block text-sm font-medium text-gray-700 mb-1">Unité dispensation <span class="text-red-500">*</span></label>
                <input id="med-unite" name="unite_dispensation" value="{{ old('unite_dispensation', 'comprimé') }}" type="text"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-classe" class="block text-sm font-medium text-gray-700 mb-1">Classe thérapeutique</label>
                <input id="med-classe" name="classe_therapeutique" value="{{ old('classe_therapeutique') }}" type="text" placeholder="Ex: Antibiotique"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-pv" class="block text-sm font-medium text-gray-700 mb-1">Prix de vente d'UNE unité (CDF) <span class="text-red-500">*</span></label>
                <input id="med-pv" name="prix_unitaire_vente" value="{{ old('prix_unitaire_vente') }}" type="number" step="0.01" min="0"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                <p class="text-xs text-gray-500 mt-1">
                    Le prix d'un comprimé, pas celui de la plaquette : une plaquette
                    de 10 à 500 CDF fait 50 CDF le comprimé.
                </p>
            </div>
            <div>
                <label for="med-pa" class="block text-sm font-medium text-gray-700 mb-1">Prix achat (CDF)</label>
                <input id="med-pa" name="prix_unitaire_achat" value="{{ old('prix_unitaire_achat') }}" type="number" step="0.01" min="0"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-alerte" class="block text-sm font-medium text-gray-700 mb-1">Seuil alerte stock</label>
                <input id="med-alerte" name="quantite_alerte" value="{{ old('quantite_alerte', 10) }}" type="number" min="0"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-qte" class="block text-sm font-medium text-gray-700 mb-1">Quantité initiale au dépôt</label>
                <input id="med-qte" name="quantite_initiale" value="{{ old('quantite_initiale', 0) }}" type="number" step="0.5" min="0"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                <p class="text-xs text-gray-500 mt-1">
                    En unités. Le produit entre au dépôt central ; les officines
                    s'y approvisionnent par réquisition.
                </p>
            </div>
            <div>
                <label for="med-peremption" class="block text-sm font-medium text-gray-700 mb-1">Date péremption</label>
                <input id="med-peremption" name="date_peremption" value="{{ old('date_peremption') }}" type="date"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="med-lot" class="block text-sm font-medium text-gray-700 mb-1">N° lot</label>
                <input id="med-lot" name="lot" value="{{ old('lot') }}" type="text"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div class="flex items-center gap-2 mt-4">
                <input id="med-ord" name="necessite_ordonnance" type="checkbox" value="1" checked class="rounded">
                <label for="med-ord" class="text-sm text-gray-700">Nécessite ordonnance</label>
            </div>
        </div>
        <div class="flex justify-end mt-4">
            <button type="submit" class="min-h-[44px] px-5 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg">
                Enregistrer le médicament
            </button>
        </div>
    </form>
</details>
