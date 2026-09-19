@extends('layouts.app')
@section('title', 'Nutrition — '.$patient->nom_complet)
@section('content')
@php
    $unites = \App\Models\PriseEnChargeNutritionnelle::UNITES;
    $criteres = \App\Models\PriseEnChargeNutritionnelle::CRITERES;
    $criteresUns = \App\Models\PriseEnChargeNutritionnelle::CRITERES_UNS;
    $ageMois = $patient->date_naissance ? (int) $patient->date_naissance->diffInMonths(now()) : null;
    $couleurEtat = [
        'severe' => 'bg-red-100 text-red-800 border-red-200',
        'modere' => 'bg-amber-100 text-amber-900 border-amber-200',
        'normal' => 'bg-green-100 text-green-800 border-green-200',
        'inconnu' => 'bg-gray-100 text-gray-600 border-gray-200',
    ];
@endphp
<div class="max-w-5xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <a href="{{ route('nutrition.index') }}" class="text-blue-700 hover:underline text-sm">← Registre nutritionnel</a>
        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-sm">Dossier du patient</a>
        <h2 class="text-2xl font-bold text-gray-800">🥣 Nutrition — {{ $patient->nom_complet }}</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        {{-- Blade ne compile pas une directive collée à un mot : l'âge se
             construit donc en PHP plutôt qu'avec un @if au milieu du texte. --}}
        Dossier {{ $patient->dossier_number }} — {{ $patient->sexe }}{{ $ageMois !== null ? ', '.$ageMois.' mois' : '' }}.
    </p>

    {{-- Ce que dit la dernière mesure --}}
    @isset($derniere)
    <div class="rounded-xl border px-5 py-4 mb-5 {{ $couleurEtat[$derniere->etatNutritionnel()] }}">
        <p class="font-semibold">
            {{ $derniere->libelleEtat() }}
            <span class="font-normal opacity-75">— mesure du {{ $derniere->mesure_a->format('d/m/Y à H:i') }}</span>
        </p>
        <p class="text-sm mt-1">
            @if($derniere->perimetre_brachial_mm)PB {{ $derniere->perimetre_brachial_mm }} mm · @endif
            @if($derniere->z_score_pt !== null)P/T {{ $derniere->z_score_pt + 0 }} DS · @endif
            Œdèmes : {{ $derniere->libelleOedemes() }}
            @if($derniere->poids_kg) · {{ $derniere->poids_kg + 0 }} kg @endif
            @if($derniere->taille_cm) · {{ $derniere->taille_cm + 0 }} cm @endif
        </p>
        @isset($orientation)
        <p class="text-sm mt-2 opacity-90">{{ $orientation['message'] }}</p>
        @endisset
    </div>
    @else
    <div class="bg-gray-50 border border-gray-200 rounded-xl px-5 py-4 mb-5 text-sm text-gray-600">
        Aucune mesure enregistrée pour ce patient.
    </div>
    @endisset

    {{-- Le suivi en cours, ou l'admission --}}
    @isset($suivi)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Suivi en cours — {{ $suivi->sigle() }}
            <span class="text-gray-400 font-normal text-sm">
                admis le {{ $suivi->date_admission->format('d/m/Y') }},
                {{ (int) $suivi->date_admission->diffInDays(now()) }} jour(s)
            </span>
        </div>
        <div class="px-5 py-3 text-sm text-gray-600 border-b">
            <p><strong>Motif d'entrée :</strong> {{ $suivi->libelleCritere() }}</p>
            @if($suivi->avec_complication)
            <p class="text-amber-800">Avec complication médicale.</p>
            @endif
            @if($suivi->groupe_specifique)
            <p>{{ \App\Models\PriseEnChargeNutritionnelle::GROUPES_SPECIFIQUES[$suivi->groupe_specifique] }}</p>
            @endif
        </div>

        <form method="POST" action="{{ route('nutrition.decharger', $suivi) }}" class="px-5 py-4">
            @csrf
            <p class="text-sm font-semibold text-gray-700 mb-3">Décharger ce suivi</p>
            <div class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[14rem]">
                    <label for="issue" class="block text-xs font-semibold text-gray-600 mb-1">Issue</label>
                    <select id="issue" name="issue" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">— Choisir —</option>
                        @foreach(\App\Models\PriseEnChargeNutritionnelle::ISSUES[$suivi->unite] as $cle => $libelle)
                        <option value="{{ $cle }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_sortie" class="block text-xs font-semibold text-gray-600 mb-1">Date de sortie</label>
                    <input id="date_sortie" name="date_sortie" type="date" value="{{ now()->toDateString() }}"
                           class="min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <button class="bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg px-5 min-h-[44px] text-sm">
                    Décharger
                </button>
            </div>
            <div class="mt-3">
                <label for="observation-sortie" class="block text-xs font-semibold text-gray-600 mb-1">
                    Observation <span class="font-normal text-gray-400">(optionnel)</span>
                </label>
                <textarea id="observation-sortie" name="observation" rows="2" maxlength="1000"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
            </div>
            @error('issue')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
            @error('date_sortie')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
        </form>
    </div>
    @else
    @if($unitesDeLetablissement)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Admettre en nutrition</div>
        <form method="POST" action="{{ route('nutrition.admission', $patient) }}" class="px-5 py-4">
            @csrf
            @isset($derniere)<input type="hidden" name="mesure_id" value="{{ $derniere->id }}">@endisset

            <div class="flex flex-wrap gap-3 items-start">
                <div class="min-w-[16rem] flex-1">
                    <label for="unite" class="block text-xs font-semibold text-gray-600 mb-1">Unité</label>
                    <select id="unite" name="unite" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach($unitesDeLetablissement as $cle)
                        <option value="{{ $cle }}"
                            @selected(old('unite', $orientation['unite'] ?? null) === $cle)>
                            {{ $unites[$cle]['sigle'] }} — {{ $unites[$cle]['nom'] }}
                        </option>
                        @endforeach
                    </select>
                    @foreach($unitesDeLetablissement as $cle)
                    <p class="text-xs text-gray-500 mt-1">
                        <strong>{{ $unites[$cle]['sigle'] }} :</strong> {{ $unites[$cle]['pourquoi'] }}
                    </p>
                    @endforeach
                </div>

                <div class="min-w-[16rem] flex-1">
                    <label for="critere_admission" class="block text-xs font-semibold text-gray-600 mb-1">
                        Motif d'entrée
                    </label>
                    {{-- Les motifs sont groupés par unité : le serveur refuse
                         un motif qui n'appartient pas à l'unité choisie. --}}
                    <select id="critere_admission" name="critere_admission" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <optgroup label="Unité thérapeutique (UNTA / UNTI)">
                            @foreach($criteres as $cle => $libelle)
                            @continue(in_array($cle, $criteresUns, true))
                            <option value="{{ $cle }}"
                                @selected(old('critere_admission', $orientation['critere'] ?? null) === $cle)>
                                {{ $libelle }}
                            </option>
                            @endforeach
                        </optgroup>
                        <optgroup label="Supplémentation (UNS)">
                            @foreach($criteresUns as $cle)
                            <option value="{{ $cle }}"
                                @selected(old('critere_admission', $orientation['critere'] ?? null) === $cle)>
                                {{ $criteres[$cle] }}
                            </option>
                            @endforeach
                        </optgroup>
                    </select>
                </div>

                <div class="min-w-[14rem]">
                    <label for="date_admission" class="block text-xs font-semibold text-gray-600 mb-1">
                        Date d'admission
                    </label>
                    <input id="date_admission" name="date_admission" type="date"
                           value="{{ old('date_admission', now()->toDateString()) }}"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2 items-center">
                {{-- La complication ne se lit sur aucun ruban : elle départage
                     l'ambulatoire de l'intensive, et vient du soignant. --}}
                <label for="avec_complication" class="flex items-center gap-2 text-sm text-gray-700 min-h-[44px]">
                    <input id="avec_complication" name="avec_complication" type="checkbox" value="1"
                           @checked(old('avec_complication')) class="w-5 h-5 rounded border-gray-300">
                    Complication médicale associée
                </label>
                <div class="flex-1 min-w-[16rem]">
                    <label for="groupe_specifique" class="block text-xs font-semibold text-gray-600 mb-1">
                        Groupe spécifique <span class="font-normal text-gray-400">(UNS, optionnel)</span>
                    </label>
                    <select id="groupe_specifique" name="groupe_specifique"
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">— Aucun —</option>
                        @foreach(\App\Models\PriseEnChargeNutritionnelle::GROUPES_SPECIFIQUES as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('groupe_specifique') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button class="mt-4 bg-green-700 hover:bg-green-800 text-white font-semibold rounded-lg px-5 min-h-[44px] text-sm">
                Admettre
            </button>
            @error('unite')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
            @error('critere_admission')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
        </form>
    </div>
    @endif
    @endisset

    {{-- La mesure du jour --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Nouvelle mesure</div>
        <form method="POST" action="{{ route('nutrition.mesure', $patient) }}" class="px-5 py-4">
            @csrf
            @isset($visiteEnCours)<input type="hidden" name="visit_id" value="{{ $visiteEnCours->id }}">@endisset

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div>
                    <label for="poids_kg" class="block text-xs font-semibold text-gray-600 mb-1">Poids (kg)</label>
                    <input id="poids_kg" name="poids_kg" type="number" step="0.01" min="0.5" max="300"
                           value="{{ old('poids_kg') }}"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="taille_cm" class="block text-xs font-semibold text-gray-600 mb-1">Taille (cm)</label>
                    <input id="taille_cm" name="taille_cm" type="number" step="0.1" min="20" max="250"
                           value="{{ old('taille_cm') }}"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="position_taille" class="block text-xs font-semibold text-gray-600 mb-1">
                        Position de mesure
                    </label>
                    <select id="position_taille" name="position_taille"
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">— Non précisée —</option>
                        @foreach(\App\Models\MesureAnthropometrique::POSITIONS as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('position_taille') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="perimetre_brachial_mm" class="block text-xs font-semibold text-gray-600 mb-1">
                        Périmètre brachial (mm)
                    </label>
                    <input id="perimetre_brachial_mm" name="perimetre_brachial_mm" type="number" step="1" min="50" max="500"
                           value="{{ old('perimetre_brachial_mm') }}" placeholder="Ex. 112"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">
                        En millimètres, comme sur le ruban. Sévère sous 115, modéré sous 125.
                    </p>
                </div>
                <div>
                    <label for="oedemes" class="block text-xs font-semibold text-gray-600 mb-1">Œdèmes bilatéraux</label>
                    <select id="oedemes" name="oedemes" required
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        @foreach(\App\Models\MesureAnthropometrique::OEDEMES as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('oedemes', 'aucun') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="z_score_pt" class="block text-xs font-semibold text-gray-600 mb-1">
                        Score poids/taille (DS)
                    </label>
                    <input id="z_score_pt" name="z_score_pt" type="number" step="0.01" min="-6" max="6"
                           value="{{ old('z_score_pt') }}" placeholder="Ex. -3.2"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">
                        Lu sur l'abaque. L'application ne le calcule pas — elle n'embarque
                        pas les tables de l'OMS, et un score inventé enverrait l'enfant
                        dans la mauvaise unité.
                    </p>
                </div>
            </div>

            <div class="mt-3">
                <label for="observation" class="block text-xs font-semibold text-gray-600 mb-1">
                    Observation <span class="font-normal text-gray-400">(optionnel)</span>
                </label>
                <textarea id="observation" name="observation" rows="2" maxlength="1000"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">{{ old('observation') }}</textarea>
            </div>

            <button class="mt-4 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg px-5 min-h-[44px] text-sm">
                Enregistrer la mesure
            </button>
            @error('poids_kg')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
            @error('perimetre_brachial_mm')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
        </form>
    </div>

    {{-- La suite des mesures : c'est elle qui dit si l'enfant remonte --}}
    @if($mesures->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Mesures précédentes
            <span class="text-gray-400 font-normal text-sm">— de la plus récente à la plus ancienne</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-right">Poids</th>
                        <th class="px-4 py-2 text-right">Taille</th>
                        <th class="px-4 py-2 text-right">PB</th>
                        <th class="px-4 py-2 text-right">P/T</th>
                        <th class="px-4 py-2 text-left">Œdèmes</th>
                        <th class="px-4 py-2 text-left">État</th>
                        <th class="px-4 py-2 text-left">Par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($mesures as $mesure)
                    <tr class="{{ $mesure->etatNutritionnel() === 'severe' ? 'bg-red-50' : '' }}">
                        <td class="px-4 py-2 whitespace-nowrap">{{ $mesure->mesure_a->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2 text-right">{{ $mesure->poids_kg ? ($mesure->poids_kg + 0).' kg' : '—' }}</td>
                        <td class="px-4 py-2 text-right">{{ $mesure->taille_cm ? ($mesure->taille_cm + 0).' cm' : '—' }}</td>
                        <td class="px-4 py-2 text-right">{{ $mesure->perimetre_brachial_mm ? $mesure->perimetre_brachial_mm.' mm' : '—' }}</td>
                        <td class="px-4 py-2 text-right">{{ $mesure->z_score_pt !== null ? ($mesure->z_score_pt + 0).' DS' : '—' }}</td>
                        <td class="px-4 py-2">{{ $mesure->libelleOedemes() }}</td>
                        <td class="px-4 py-2">{{ $mesure->libelleEtat() }}</td>
                        <td class="px-4 py-2 text-gray-500 text-xs">{{ $mesure->auteur?->nom_complet }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Les suivis clos --}}
    @if($historique->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Suivis précédents</div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                @foreach($historique as $ancien)
                <tr>
                    <td class="px-4 py-2 font-medium">{{ $ancien->sigle() }}</td>
                    <td class="px-4 py-2 text-gray-600">
                        du {{ $ancien->date_admission->format('d/m/Y') }}
                        au {{ $ancien->date_sortie?->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-2">{{ $ancien->libelleCritere() }}</td>
                    <td class="px-4 py-2 text-right font-semibold">{{ $ancien->libelleIssue() }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
