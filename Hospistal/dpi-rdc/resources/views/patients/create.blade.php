@extends('layouts.app')
@section('title', 'Nouveau patient')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('patients.index') }}" class="text-blue-700 hover:underline text-sm">← Retour</a>
        <h2 class="text-2xl font-bold text-gray-800">Nouveau patient</h2>
    </div>

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

    @if (session('duplicates'))
        <div class="bg-amber-50 border border-amber-300 rounded-xl p-4 mb-4">
            <h3 class="font-semibold text-amber-800 mb-2">Patients similaires détectés</h3>
            <ul class="space-y-1 mb-3 text-sm text-amber-700">
                @foreach (session('duplicates') as $dup)
                    <li>
                        <a href="{{ route('patients.show', $dup['id']) }}" target="_blank" class="underline">{{ $dup['nom_complet'] }}</a>
                        — {{ $dup['dossier_number'] }}
                        @if ($dup['date_naissance']) — né(e) le {{ $dup['date_naissance'] }} @endif
                        <span class="ml-1 text-xs bg-amber-200 px-1 rounded">{{ $dup['score'] }}%</span>
                    </li>
                @endforeach
            </ul>
            <p class="text-sm text-amber-800 mb-3">Si ce n'est pas un doublon, confirmez ci-dessous puis ré-enregistrez.</p>
        </div>
    @endif

    <form method="POST" action="{{ route('patients.store') }}" class="space-y-6">
        @csrf
        @if (session('duplicates'))
            <input type="hidden" name="confirm_no_duplicate" value="1">
        @endif

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Identité civile</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="nom" class="block text-sm font-medium text-gray-700 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input id="nom" name="nom" type="text" value="{{ old('nom') }}" required autocomplete="family-name"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="prenom" class="block text-sm font-medium text-gray-700 mb-1">Prénom <span class="text-red-500">*</span></label>
                    <input id="prenom" name="prenom" type="text" value="{{ old('prenom') }}" required autocomplete="given-name"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                </div>
                <div>
                    <label for="sexe" class="block text-sm font-medium text-gray-700 mb-1">Sexe <span class="text-red-500">*</span></label>
                    <select id="sexe" name="sexe" required class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                        @foreach (['Inconnu' => 'Non précisé', 'M' => 'Masculin', 'F' => 'Féminin'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('sexe', 'Inconnu') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_naissance" class="block text-sm font-medium text-gray-700 mb-1">Date de naissance</label>
                    <input id="date_naissance" name="date_naissance" type="date" value="{{ old('date_naissance') }}" autocomplete="bday"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="lieu_naissance" class="block text-sm font-medium text-gray-700 mb-1">Lieu de naissance</label>
                    <input id="lieu_naissance" name="lieu_naissance" type="text" value="{{ old('lieu_naissance') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="nationalite" class="block text-sm font-medium text-gray-700 mb-1">Nationalité</label>
                    <input id="nationalite" name="nationalite" type="text" value="{{ old('nationalite', 'Congolaise') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="situation_matrimoniale" class="block text-sm font-medium text-gray-700 mb-1">Situation matrimoniale</label>
                    <select id="situation_matrimoniale" name="situation_matrimoniale" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                        @foreach (['inconnu' => 'Non précisé', 'celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'divorce' => 'Divorcé(e)', 'veuf' => 'Veuf / Veuve'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('situation_matrimoniale', 'inconnu') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="niveau_instruction" class="block text-sm font-medium text-gray-700 mb-1">Niveau d'instruction</label>
                    <select id="niveau_instruction" name="niveau_instruction" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                        @foreach (['inconnu' => 'Non précisé', 'aucun' => 'Aucun', 'primaire' => 'Primaire', 'secondaire' => 'Secondaire', 'superieur' => 'Supérieur'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('niveau_instruction', 'inconnu') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="profession" class="block text-sm font-medium text-gray-700 mb-1">Profession</label>
                    <input id="profession" name="profession" type="text" value="{{ old('profession') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Coordonnées</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="telephone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input id="telephone" name="telephone" type="tel" value="{{ old('telephone') }}" autocomplete="tel"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="province" class="block text-sm font-medium text-gray-700 mb-1">Province</label>
                    <input id="province" name="province" type="text" value="{{ old('province') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="territoire" class="block text-sm font-medium text-gray-700 mb-1">Territoire / Commune</label>
                    <input id="territoire" name="territoire" type="text" value="{{ old('territoire') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div class="col-span-2">
                    <label for="adresse" class="block text-sm font-medium text-gray-700 mb-1">Adresse complète</label>
                    <input id="adresse" name="adresse" type="text" value="{{ old('adresse') }}" autocomplete="street-address"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Prise en charge financière</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="type_prise_en_charge" class="block text-sm font-medium text-gray-700 mb-1">Type <span class="text-red-500">*</span></label>
                    <select id="type_prise_en_charge" name="type_prise_en_charge" required class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                        @foreach (['prive' => 'Privé', 'assurance' => 'Assurance', 'indigent' => 'Indigent (gratuit)', 'fonctionnaire' => 'Fonctionnaire', 'autre' => 'Autre'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('type_prise_en_charge', 'prive') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="assurance_nom" class="block text-sm font-medium text-gray-700 mb-1">Nom assurance</label>
                    <input id="assurance_nom" name="assurance_nom" type="text" value="{{ old('assurance_nom') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="assurance_numero" class="block text-sm font-medium text-gray-700 mb-1">N° police</label>
                    <input id="assurance_numero" name="assurance_numero" type="text" value="{{ old('assurance_numero') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Contact d'urgence</h3>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label for="contact_urgence_nom" class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                    <input id="contact_urgence_nom" name="contact_urgence_nom" type="text" value="{{ old('contact_urgence_nom') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="contact_urgence_telephone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input id="contact_urgence_telephone" name="contact_urgence_telephone" type="tel" value="{{ old('contact_urgence_telephone') }}"
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
                <div>
                    <label for="contact_urgence_lien" class="block text-sm font-medium text-gray-700 mb-1">Lien de parenté</label>
                    <input id="contact_urgence_lien" name="contact_urgence_lien" type="text" value="{{ old('contact_urgence_lien') }}"
                        placeholder="Père, mère, conjoint..."
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 pb-6">
            <a href="{{ route('patients.index') }}" class="px-6 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
                Annuler
            </a>
            <button type="submit" class="px-6 py-2 rounded-lg bg-blue-700 hover:bg-blue-800 text-white font-semibold">
                Enregistrer le patient
            </button>
        </div>
    </form>
</div>
@endsection
