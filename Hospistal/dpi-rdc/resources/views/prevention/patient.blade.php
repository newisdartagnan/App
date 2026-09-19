@extends('layouts.app')
@section('title', 'Prévention — '.$patient->nom_complet)
@section('content')
@php
    $methodes = \App\Models\ActePlanificationFamiliale::METHODES;
    $antigenes = \App\Models\Vaccination::ANTIGENES;
    $ageAns = $patient->date_naissance ? (int) $patient->date_naissance->diffInYears(now()) : null;
    $ageTexte = $ageAns !== null ? ', '.$ageAns.' an'.($ageAns > 1 ? 's' : '') : '';
@endphp
<div class="max-w-5xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-sm">← Dossier du patient</a>
        <h2 class="text-2xl font-bold text-gray-800">🛡️ Prévention — {{ $patient->nom_complet }}</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Dossier {{ $patient->dossier_number }} — {{ $patient->sexe }}{{ $ageTexte }}.
    </p>

    {{-- ══════════ Le carnet de vaccination ══════════ --}}
    @if($suitLePev)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b">
            <p class="font-semibold text-gray-700">
                Carnet de vaccination
                <span class="font-normal text-gray-400 text-sm">
                    — {{ $carnet['doses_recues'] }} dose(s) reçue(s)
                </span>
            </p>
            @if($carnet['complet'])
            <p class="text-sm text-green-700 mt-0.5">Schéma complet : cet enfant est complètement vacciné.</p>
            @elseif($carnet['manquants_pour_etre_complet'])
            <p class="text-sm text-gray-600 mt-0.5">
                Il manque, pour le schéma complet :
                <strong>{{ implode(', ', $carnet['manquants_pour_etre_complet']) }}</strong>.
            </p>
            @endif
            @if($carnet['en_retard'] > 0)
            <p class="text-sm text-amber-800 mt-0.5">
                {{ $carnet['en_retard'] }} dose(s) en retard sur le calendrier — un rattrapage se fait.
            </p>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Antigène</th>
                        <th class="px-4 py-2 text-left">Reçu le</th>
                        <th class="px-4 py-2 text-left">Stratégie</th>
                        <th class="px-4 py-2 text-left">État</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($carnet['lignes'] as $ligne)
                    <tr class="{{ $ligne['en_retard'] ? 'bg-amber-50' : '' }}">
                        <td class="px-4 py-2 {{ $ligne['recue'] ? 'font-medium' : 'text-gray-500' }}">
                            {{ $ligne['libelle'] }}
                        </td>
                        <td class="px-4 py-2">{{ $ligne['date']?->format('d/m/Y') ?? '—' }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $ligne['strategie'] ?? '—' }}</td>
                        <td class="px-4 py-2">
                            @if($ligne['recue'])
                            <span class="text-green-700">Reçu</span>
                            @elseif($ligne['en_retard'])
                            <span class="text-amber-800 font-medium">En retard</span>
                            @else
                            <span class="text-gray-400">À venir</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('prevention.vaccination.store', $patient) }}" class="px-5 py-4 border-t">
            @csrf
            <p class="text-sm font-semibold text-gray-700 mb-3">Enregistrer une dose</p>
            <div class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[14rem]">
                    <label for="antigene" class="block text-xs font-semibold text-gray-600 mb-1">Antigène</label>
                    <select id="antigene" name="antigene" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">— Choisir —</option>
                        @foreach($antigenes as $cle => $antigene)
                        {{-- Une dose déjà reçue ne se repropose pas : le
                             deuxième enregistrement gonflerait la couverture. --}}
                        @continue($carnet['lignes'][$cle]['recue'])
                        <option value="{{ $cle }}" @selected(old('antigene') === $cle)>{{ $antigene['libelle'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="strategie" class="block text-xs font-semibold text-gray-600 mb-1">Stratégie</label>
                    <select id="strategie" name="strategie" required
                            class="min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach(\App\Models\Vaccination::STRATEGIES as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('strategie', 'fixe') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_vaccination" class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                    <input id="date_vaccination" name="date_vaccination" type="date"
                           value="{{ old('date_vaccination', now()->toDateString()) }}"
                           class="min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="lot" class="block text-xs font-semibold text-gray-600 mb-1">
                        Lot <span class="font-normal text-gray-400">(optionnel)</span>
                    </label>
                    <input id="lot" name="lot" type="text" maxlength="60" value="{{ old('lot') }}"
                           class="min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <button class="bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg px-5 min-h-[44px] text-sm">
                    Enregistrer la dose
                </button>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                Le lot n'est pas obligatoire, mais c'est la première chose qu'on
                demande en cas de manifestation post-vaccinale.
            </p>
            @error('antigene')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
        </form>
    </div>
    @endif

    {{-- ══════════ La planification familiale ══════════ --}}
    @if($suitLaPf)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Planification familiale</div>

        <form method="POST" action="{{ route('prevention.pf.store', $patient) }}" class="px-5 py-4">
            @csrf
            <div class="flex flex-wrap gap-3 items-end">
                <div class="min-w-[16rem] flex-1">
                    <label for="type_acte" class="block text-xs font-semibold text-gray-600 mb-1">Nature de l'acte</label>
                    <select id="type_acte" name="type_acte" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach(\App\Models\ActePlanificationFamiliale::TYPES_ACTE as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('type_acte', 'methode') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-[18rem] flex-1">
                    <label for="methode" class="block text-xs font-semibold text-gray-600 mb-1">
                        Méthode <span class="font-normal text-gray-400">(si méthode remise)</span>
                    </label>
                    {{-- Les méthodes masculines sont dans leur propre groupe :
                         le canevas ventile par sexe, et l'enregistrement
                         refuse le mélange. --}}
                    <select id="methode" name="methode"
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">— Aucune —</option>
                        <optgroup label="Méthodes féminines">
                            @foreach($methodes as $cle => $methode)
                            @continue($methode['masculine'])
                            <option value="{{ $cle }}" @selected(old('methode') === $cle)>{{ $methode['libelle'] }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Méthodes masculines">
                            @foreach($methodes as $cle => $methode)
                            @continue(! $methode['masculine'])
                            <option value="{{ $cle }}" @selected(old('methode') === $cle)>{{ $methode['libelle'] }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 items-end mt-3">
                <div class="min-w-[12rem]">
                    <label for="type_acceptation" class="block text-xs font-semibold text-gray-600 mb-1">Acceptation</label>
                    <select id="type_acceptation" name="type_acceptation" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach(\App\Models\ActePlanificationFamiliale::ACCEPTATIONS as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('type_acceptation', 'nouvelle') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-[16rem] flex-1">
                    <label for="canal" class="block text-xs font-semibold text-gray-600 mb-1">Canal</label>
                    <select id="canal" name="canal" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach(\App\Models\ActePlanificationFamiliale::CANAUX as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('canal', 'ess') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_acte" class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                    <input id="date_acte" name="date_acte" type="date"
                           value="{{ old('date_acte', now()->toDateString()) }}"
                           class="min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <label for="post_partum" class="flex items-center gap-2 text-sm text-gray-700 min-h-[44px]">
                    <input id="post_partum" name="post_partum" type="checkbox" value="1"
                           @checked(old('post_partum')) class="w-5 h-5 rounded border-gray-300">
                    Avant la sortie de la maternité
                </label>
            </div>

            <button class="mt-4 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg px-5 min-h-[44px] text-sm">
                Enregistrer l'acte
            </button>
            @error('methode')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
        </form>

        @if($actesPf->isNotEmpty())
        <div class="overflow-x-auto border-t">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Méthode</th>
                        <th class="px-4 py-2 text-left">Acceptation</th>
                        <th class="px-4 py-2 text-left">Canal</th>
                        <th class="px-4 py-2 text-left">Par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($actesPf as $acte)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $acte->date_acte->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">
                            @if($acte->type_acte === 'conseil_post_partum')
                            <span class="text-gray-600 italic">Conseil en PF du post-partum</span>
                            @else
                            {{ $acte->libelleMethode() }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-600">
                            {{ \App\Models\ActePlanificationFamiliale::ACCEPTATIONS[$acte->type_acceptation] ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ strtoupper($acte->canal) }}</td>
                        <td class="px-4 py-2 text-gray-500 text-xs">{{ $acte->auteur?->nom_complet }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif

    @if(! $suitLePev && ! $suitLaPf)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 text-sm text-amber-900">
        Le canevas retenu pour cet établissement ne remonte ni la planification
        familiale ni la vaccination. Changez de système de santé depuis l'écran
        SNIS si ce n'est pas le cas.
    </div>
    @endif
</div>
@endsection
