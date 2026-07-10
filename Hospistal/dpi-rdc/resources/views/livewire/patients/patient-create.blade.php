<div>
    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4">
            <p class="font-semibold mb-1">Corrigez les erreurs suivantes :</p>
            <ul class="list-disc list-inside text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($showDuplicateWarning)
        <div class="bg-amber-50 border border-amber-300 rounded-xl p-4 mb-4">
            <h3 class="font-semibold text-amber-800 mb-2">Patients similaires détectés</h3>
            <ul class="space-y-1 mb-3">
                @foreach ($duplicates as $dup)
                    <li class="text-sm text-amber-700">
                        <a href="{{ route('patients.show', $dup['id']) }}" target="_blank" class="underline">{{ $dup['nom_complet'] }}</a>
                        — {{ $dup['dossier_number'] }}
                        @if ($dup['date_naissance']) — né(e) le {{ $dup['date_naissance'] }} @endif
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

    <form wire:submit.prevent="save" class="space-y-6" novalidate>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Identité civile</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="patient-nom" class="block text-sm font-medium text-gray-700 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input id="patient-nom" name="nom" wire:model.live.debounce.300ms="nom" type="text" autocomplete="family-name"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('nom') border-red-500 @enderror">
                    @error('nom')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="patient-prenom" class="block text-sm font-medium text-gray-700 mb-1">Prénom <span class="text-red-500">*</span></label>
                    <input id="patient-prenom" name="prenom" wire:model.live.debounce.300ms="prenom" type="text" autocomplete="given-name"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 @error('prenom') border-red-500 @enderror">
                    @error('prenom')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="patient-sexe" class="block text-sm font-medium text-gray-700 mb-1">Sexe <span class="text-red-500">*</span></label>
                    <select id="patient-sexe" name="sexe" wire:model.live="sexe" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                        <option value="Inconnu">Non précisé</option>
                        <option value="M">Masculin</option>
                        <option value="F">Féminin</option>
                    </select>
                </div>
                <div>
                    <label for="patient-date-naissance" class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                    <input id="patient-date-naissance" name="date_naissance" wire:model.live="date_naissance" type="date" autocomplete="bday"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="patient-lieu-naissance" class="block text-sm font-medium text-gray-700 mb-1">Lieu de naissance</label>
                    <input id="patient-lieu-naissance" name="lieu_naissance" wire:model="lieu_naissance" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="patient-nationalite" class="block text-sm font-medium text-gray-700 mb-1">Nationalité</label>
                    <input id="patient-nationalite" name="nationalite" wire:model="nationalite" type="text" autocomplete="country-name"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="patient-situation" class="block text-sm font-medium text-gray-700 mb-1">Situation matrimoniale</label>
                    <select id="patient-situation" name="situation_matrimoniale" wire:model="situation_matrimoniale" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                        <option value="inconnu">Non précisé</option>
                        <option value="celibataire">Célibataire</option>
                        <option value="marie">Marié(e)</option>
                        <option value="divorce">Divorcé(e)</option>
                        <option value="veuf">Veuf / Veuve</option>
                    </select>
                </div>
                <div>
                    <label for="patient-instruction" class="block text-sm font-medium text-gray-700 mb-1">Niveau d'instruction</label>
                    <select id="patient-instruction" name="niveau_instruction" wire:model="niveau_instruction" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                        <option value="inconnu">Non précisé</option>
                        <option value="aucun">Aucun</option>
                        <option value="primaire">Primaire</option>
                        <option value="secondaire">Secondaire</option>
                        <option value="superieur">Supérieur</option>
                    </select>
                </div>
                <div>
                    <label for="patient-profession" class="block text-sm font-medium text-gray-700 mb-1">Profession</label>
                    <input id="patient-profession" name="profession" wire:model="profession" type="text" autocomplete="organization-title"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Coordonnées</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="patient-telephone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input id="patient-telephone" name="telephone" wire:model="telephone" type="tel" autocomplete="tel"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="patient-province" class="block text-sm font-medium text-gray-700 mb-1">Province</label>
                    <input id="patient-province" name="province" wire:model="province" type="text" autocomplete="address-level1"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="patient-territoire" class="block text-sm font-medium text-gray-700 mb-1">Territoire / Commune</label>
                    <input id="patient-territoire" name="territoire" wire:model="territoire" type="text" autocomplete="address-level2"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div class="col-span-2">
                    <label for="patient-adresse" class="block text-sm font-medium text-gray-700 mb-1">Adresse complète</label>
                    <input id="patient-adresse" name="adresse" wire:model="adresse" type="text" autocomplete="street-address"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Prise en charge financière</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="patient-prise-en-charge" class="block text-sm font-medium text-gray-700 mb-1">Type <span class="text-red-500">*</span></label>
                    <select id="patient-prise-en-charge" name="type_prise_en_charge" wire:model.live="type_prise_en_charge" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                        <option value="prive">Privé</option>
                        <option value="assurance">Assurance</option>
                        <option value="indigent">Indigent (gratuit)</option>
                        <option value="fonctionnaire">Fonctionnaire</option>
                        <option value="autre">Autre</option>
                    </select>
                </div>
                @if ($type_prise_en_charge === 'assurance')
                    <div>
                        <label for="patient-assurance-nom" class="block text-sm font-medium text-gray-700 mb-1">Nom assurance</label>
                        <input id="patient-assurance-nom" name="assurance_nom" wire:model="assurance_nom" type="text"
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    </div>
                    <div>
                        <label for="patient-assurance-numero" class="block text-sm font-medium text-gray-700 mb-1">N° police</label>
                        <input id="patient-assurance-numero" name="assurance_numero" wire:model="assurance_numero" type="text"
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    </div>
                @endif
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Contact d'urgence</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label for="patient-urgence-nom" class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                    <input id="patient-urgence-nom" name="contact_urgence_nom" wire:model="contact_urgence_nom" type="text"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="patient-urgence-tel" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input id="patient-urgence-tel" name="contact_urgence_telephone" wire:model="contact_urgence_telephone" type="tel"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="patient-urgence-lien" class="block text-sm font-medium text-gray-700 mb-1">Lien de parenté</label>
                    <input id="patient-urgence-lien" name="contact_urgence_lien" wire:model="contact_urgence_lien" type="text"
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
                wire:loading.attr="disabled"
                wire:target="save"
                class="px-6 py-2 rounded-lg bg-blue-700 hover:bg-blue-800 text-white font-semibold transition disabled:opacity-50">
                <span wire:loading.remove wire:target="save">Enregistrer le patient</span>
                <span wire:loading wire:target="save">Enregistrement…</span>
            </button>
        </div>
    </form>
</div>
