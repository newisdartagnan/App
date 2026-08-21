@extends('layouts.app')
@section('title', 'Dialyse — calendrier')
@section('content')
<div class="max-w-full mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🩸 Unité de dialyse</h2>
    <p class="text-sm text-gray-500 mb-5">
        Une semaine, un générateur par ligne. Le poste est la ressource rare :
        deux patients ne peuvent y être branchés au même moment.
    </p>

    @include('dialyse._onglets')
    @include('partials._flash')

    {{-- Navigation par semaine --}}
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <a href="{{ route('dialyse.index', ['semaine' => $lundi->copy()->subWeek()->toDateString()]) }}"
           class="text-sm text-blue-700 hover:underline">← Semaine précédente</a>
        <p class="font-semibold text-gray-700">
            Semaine du {{ $lundi->format('d/m/Y') }} au {{ $lundi->copy()->endOfWeek()->format('d/m/Y') }}
        </p>
        <a href="{{ route('dialyse.index', ['semaine' => $lundi->copy()->addWeek()->toDateString()]) }}"
           class="text-sm text-blue-700 hover:underline">Semaine suivante →</a>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="overflow-x-auto">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="border border-gray-200 px-2 py-2 text-gray-500 font-medium" style="min-width:150px;">
                            Générateur
                        </th>
                        @for($j = 0; $j < 7; $j++)
                        @php $jour = $lundi->copy()->addDays($j); @endphp
                        <th class="border border-gray-200 px-2 py-2 font-medium {{ $jour->isToday() ? 'bg-blue-50 text-blue-800' : 'text-gray-700' }}"
                            style="min-width:130px;">
                            {{ ucfirst($jour->translatedFormat('l d/m')) }}
                        </th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @forelse($generateurs as $generateur)
                    <tr>
                        <td class="border border-gray-200 px-2 py-2 align-top">
                            <p class="font-semibold text-gray-800">{{ $generateur->nom }}</p>
                            @if($generateur->reserve_hbs)
                            <span class="text-[10px] bg-amber-100 text-amber-900 px-1 py-0.5 rounded">
                                réservé AgHBs
                            </span>
                            @endif
                        </td>
                        @for($j = 0; $j < 7; $j++)
                        @php
                            $jour = $lundi->copy()->addDays($j);
                            $duJour = $grille[$generateur->id][$jour->toDateString()] ?? collect();
                        @endphp
                        <td class="border border-gray-200 px-1 py-1 align-top {{ $jour->isToday() ? 'bg-blue-50/40' : '' }}">
                            @forelse($duJour as $seance)
                            <div class="rounded px-1.5 py-1 mb-1
                                {{ $seance->statut === 'realisee' ? 'bg-green-100 text-green-900'
                                   : ($seance->statut === 'absente' ? 'bg-gray-100 text-gray-500'
                                   : ($seance->statut === 'annulee' ? 'bg-red-50 text-red-700 line-through'
                                   : 'bg-purple-100 text-purple-900')) }}">
                                <p class="font-semibold leading-tight">
                                    {{ $seance->date_seance->format('H:i') }} → {{ $seance->finPrevue()->format('H:i') }}
                                </p>
                                <p class="leading-tight">{{ $seance->patient->nom_complet }}</p>
                                @if($seance->abord)
                                <p class="leading-tight opacity-75">{{ $seance->libelleAbord() }}</p>
                                @endif
                                @if($seance->estRealisee() && $seance->ultrafiltration_ml !== null)
                                <p class="leading-tight opacity-75">UF {{ $seance->ultrafiltration_ml }} ml</p>
                                @endif
                            </div>
                            @empty
                            <span class="text-gray-300">—</span>
                            @endforelse
                        </td>
                        @endfor
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">
                        Aucun générateur actif dans l'unité.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-wrap gap-4 mb-5 text-xs text-gray-600">
        <span><span class="inline-block w-3 h-3 rounded bg-purple-100 border border-purple-300 align-middle"></span> Planifiée</span>
        <span><span class="inline-block w-3 h-3 rounded bg-green-100 border border-green-300 align-middle"></span> Réalisée</span>
        <span><span class="inline-block w-3 h-3 rounded bg-gray-100 border border-gray-300 align-middle"></span> Absence</span>
    </div>

    <div class="grid lg:grid-cols-2 gap-5">
        {{-- Séance isolée --}}
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-1">Programmer une séance</h3>
            <p class="text-xs text-gray-500 mb-4 pb-3 border-b">
                Pour un passage ponctuel. Le programme récurrent, à côté, convient
                au dialysé chronique.
            </p>

            <form method="POST" action="{{ route('dialyse.planifier') }}" class="grid sm:grid-cols-2 gap-3">
                @csrf
                @include('dialyse._champs-patient')

                <div>
                    <label for="s-date" class="block text-xs font-semibold text-gray-600 mb-1">
                        Date et heure <span class="text-red-500">*</span>
                    </label>
                    <input id="s-date" name="date_seance" type="datetime-local" required
                           value="{{ old('date_seance') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="s-duree" class="block text-xs font-semibold text-gray-600 mb-1">
                        Durée (min) <span class="text-red-500">*</span>
                    </label>
                    <input id="s-duree" name="duree_minutes" type="number" min="60" max="600" step="30" required
                           value="{{ old('duree_minutes', 240) }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>

                <div class="sm:col-span-2">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Programmer la séance
                    </button>
                </div>
            </form>
        </div>

        {{-- Programme récurrent --}}
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-1">Programme récurrent</h3>
            <p class="text-xs text-gray-500 mb-4 pb-3 border-b">
                Un insuffisant rénal chronique vient trois fois par semaine, toute
                l'année. On pose les jours une fois, le calendrier se remplit seul.
            </p>

            <form method="POST" action="{{ route('dialyse.recurrence') }}" class="grid sm:grid-cols-2 gap-3">
                @csrf
                @include('dialyse._champs-patient', ['prefixe' => 'r'])

                <div class="sm:col-span-2">
                    <p class="block text-xs font-semibold text-gray-600 mb-1">
                        Jours de dialyse <span class="text-red-500">*</span>
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach([1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'] as $numero => $abrege)
                        <label class="flex items-center gap-1 text-sm text-gray-700">
                            <input type="checkbox" name="jours[]" value="{{ $numero }}"
                                   @checked(in_array($numero, [1, 3, 5], true)) class="rounded">
                            {{ $abrege }}
                        </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label for="r-heure" class="block text-xs font-semibold text-gray-600 mb-1">
                        Heure <span class="text-red-500">*</span>
                    </label>
                    <input id="r-heure" name="heure" type="time" required value="{{ old('heure', '08:00') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="r-debut" class="block text-xs font-semibold text-gray-600 mb-1">
                        À partir du <span class="text-red-500">*</span>
                    </label>
                    <input id="r-debut" name="date_debut" type="date" required
                           value="{{ old('date_debut', now()->toDateString()) }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="r-semaines" class="block text-xs font-semibold text-gray-600 mb-1">
                        Nombre de semaines <span class="text-red-500">*</span>
                    </label>
                    <input id="r-semaines" name="semaines" type="number" min="1" max="52" required
                           value="{{ old('semaines', 4) }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="r-duree" class="block text-xs font-semibold text-gray-600 mb-1">
                        Durée (min) <span class="text-red-500">*</span>
                    </label>
                    <input id="r-duree" name="duree_minutes" type="number" min="60" max="600" step="30" required
                           value="{{ old('duree_minutes', 240) }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>

                <div class="sm:col-span-2">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Poser le programme
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
