<div>
    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
        {{ session('success') }}
    </div>
    @endif

    @if($showDuplicateWarning)
    <div class="bg-amber-50 border border-amber-300 rounded-xl p-4 mb-4">
        <h3 class="font-semibold text-amber-800 mb-2">⚠️ Patients similaires détectés</h3>
        <ul class="space-y-1 mb-3">
            @foreach($duplicates as $dup)
            <li class="text-sm text-amber-700">
                <a href="{{ route('patients.show', $dup['id']) }}" target="_blank" class="underline">{{ $dup['nom_complet'] }}</a>
                — {{ $dup['dossier_number'] }}
                @if($dup['date_naissance']) — né(e) le {{ $dup['date_naissance'] }} @endif
                <span class="ml-1 text-xs bg-amber-200 px-1 rounded">{{ $dup['score'] }}%</span>
            </li>
            @endforeach
        </ul>
        <button wire:click="confirmNoDuplicate" type="button"
            class="bg-amber-600 hover:bg-amber-700 text-white text-sm px-4 py-2 rounded-lg">
            Ce n'est pas un doublon — continuer
        </button>
    </div>
    @endif

    <form wire:submit="save" class="space-y-6">

        {{-- Identité civile --}}
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Identité civile</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input wire:model.live.debounce.500ms="nom" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('nom') border-red-500 @enderror">
                    @error('nom')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Prénom <span class="text-red-500">*</span></label>
                    <input wire:model.live.debounce.500ms="prenom" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('prenom') border-red-500 @enderror">
                    @error('prenom')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sexe <span class="text-red-500">*</span></label>
                    <select wire:model.live="sexe" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                        <option value="Inconnu">Non précisé</option>
                        <option value="M">Masculin</option>
                        <option value="F">Féminin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                    <input wire:model.live="date_naissance" type="date"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lieu de naissance</label>
                    <input wire:model="lieu_naissance" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nationalité</label>
                    <input wire:model="nationalite" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Situation matrimoniale</label>
                    <select wire:model="situation_matrimoniale" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                        <option value="inconnu">Non précisé</option>
                        <option value="celibataire">Célibataire</option>
                        <option value="marie">Marié(e)</option>
                        <option value="divorce">Divorcé(e)</option>
                        <option value="veuf">Veuf / Veuve</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Niveau d'instruction</label>
                    <select wire:model="niveau_instruction" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                        <option value="inconnu">Non précisé</option>
                        <option value="aucun">Aucun</option>
                        <option value="primaire">Primaire</option>
                        <option value="secondaire">Secondaire</option>
                        <option value="superieur">Supérieur</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Profession</label>
                    <input wire:model="profession" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
            </div>
        </div>

        {{-- Coordonnées --}}
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Coordonnées</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input wire:model="telephone" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Province</label>
                    <input wire:model="province" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Territoire / Commune</label>
                    <input wire:model="territoire" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Adresse complète</label>
                    <input wire:model="adresse" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
            </div>
        </div>

        {{-- Prise en charge --}}
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Prise en charge financière</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Type <span class="text-red-500">*</span></label>
                    <select wire:model.live="type_prise_en_charge" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                        <option value="prive">Privé</option>
                        <option value="assurance">Assurance</option>
                        <option value="indigent">Indigent (gratuit)</option>
                        <option value="fonctionnaire">Fonctionnaire</option>
                        <option value="autre">Autre</option>
                    </select>
                </div>
                @if($type_prise_en_charge === 'assurance')
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom assurance</label>
                    <input wire:model="assurance_nom" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">N° police</label>
                    <input wire:model="assurance_numero" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                @endif
            </div>
        </div>

        {{-- Contact urgence --}}
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Contact d'urgence</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                    <input wire:model="contact_urgence_nom" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input wire:model="contact_urgence_telephone" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lien de parenté</label>
                    <input wire:model="contact_urgence_lien" type="text"
                        placeholder="Père, mère, conjoint..."
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pb-6">
            <a href="{{ route('patients.index') }}"
               class="px-6 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                Annuler
            </a>
            <button type="submit"
                class="px-6 py-2 rounded-lg bg-blue-700 hover:bg-blue-800 text-white font-semibold transition">
                Enregistrer le patient
            </button>
        </div>
    </form>
</div>