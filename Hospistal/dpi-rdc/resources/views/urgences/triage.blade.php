@extends('layouts.app')
@section('title', 'Triage — ' . $visit->patient->nom_complet)
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-4 flex-wrap">
        <a href="{{ route('urgences.index') }}" class="text-blue-700 hover:underline text-sm">← Urgences</a>
        <h2 class="text-2xl font-bold text-gray-800">🚨 Triage — {{ $visit->patient->nom_complet }}</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $visit->patient->dossier_number }}</span>
    </div>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    <div class="bg-blue-50 rounded-xl p-4 mb-4 text-sm flex flex-wrap gap-x-6 gap-y-1">
        <span>{{ $visit->patient->sexe }} · {{ $visit->patient->date_naissance?->age }} ans</span>
        <span>Arrivée : {{ $visit->date_entree->format('d/m/Y H:i') }}</span>
        @if($visit->motif_consultation)<span><strong>Motif :</strong> {{ $visit->motif_consultation }}</span>@endif
    </div>

    @if($precedent)
    <div class="bg-amber-50 border border-amber-300 rounded-xl px-4 py-3 mb-4 text-sm text-amber-900">
        Un triage a déjà été effectué le {{ $precedent->triage_at->format('d/m/Y à H:i') }} par
        {{ $precedent->auteur?->nom }} — <strong>niveau {{ $precedent->niveau }} ({{ $precedent->libelleNiveau() }})</strong>.
        Vous pouvez le revoir : les critères précédents sont pré-cochés.
    </div>
    @endif

    {{-- Rappel de l'échelle --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4">
        @foreach($niveaux as $n => $info)
        <div class="rounded-lg px-3 py-2 text-center border
            {{ match($info['couleur']) {
                'red' => 'bg-red-50 border-red-300 text-red-800',
                'orange' => 'bg-orange-50 border-orange-300 text-orange-800',
                'yellow' => 'bg-yellow-50 border-yellow-300 text-yellow-800',
                'green' => 'bg-green-50 border-green-300 text-green-800',
                default => 'bg-blue-50 border-blue-300 text-blue-800',
            } }}">
            <p class="font-bold text-lg">{{ $n }}</p>
            <p class="text-[11px] font-semibold">{{ $info['libelle'] }}</p>
            <p class="text-[10px]">{{ $info['delai'] === 0 ? 'immédiat' : $info['delai'] . ' min' }}</p>
        </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500 mb-4">
        Le niveau d'urgence est <strong>calculé</strong> à partir des critères cochés : c'est le critère le plus grave
        qui l'emporte. Le soignant ne choisit pas le niveau lui-même.
    </p>

    <form method="POST" action="{{ route('urgences.triage.store', $visit) }}">
        @csrf
        @php $dejaCoches = $precedent?->criteres ?? []; @endphp

        <div class="grid md:grid-cols-2 gap-4 mb-4">
            @foreach($grille as $cleBloc => $bloc)
            <div class="bg-white rounded-xl shadow p-4">
                <h3 class="font-semibold text-gray-700 text-sm pb-2 mb-2 border-b">{{ $bloc['titre'] }}</h3>
                <div class="space-y-1.5">
                    @foreach($bloc['criteres'] as $cle => [$libelle, $niveau])
                    <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer hover:bg-gray-50 rounded px-1 py-0.5">
                        <input type="{{ $bloc['type'] === 'unique' ? 'radio' : 'checkbox' }}"
                            name="criteres[{{ $bloc['type'] === 'unique' ? $cleBloc : $cle }}]"
                            value="{{ $cle }}"
                            @checked(in_array($cle, $dejaCoches, true))
                            class="rounded">
                        <span class="flex-1">{{ $libelle }}</span>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded
                            {{ $niveau <= 1 ? 'bg-red-600 text-white'
                             : ($niveau === 2 ? 'bg-orange-500 text-white'
                             : ($niveau === 3 ? 'bg-yellow-400 text-yellow-950'
                             : ($niveau === 4 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'))) }}"
                            title="Ce critère impose le niveau {{ $niveau }}">N{{ $niveau }}</span>
                    </label>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>

        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <label class="flex items-center gap-2 text-sm text-gray-700 mb-3">
                <input type="checkbox" name="atr" value="1" @checked($precedent?->atr) class="rounded">
                Accident de travail ou de la route (ATR)
            </label>
            <label for="observation" class="block text-sm text-gray-600 mb-1">Observation du triage</label>
            <textarea id="observation" name="observation" rows="2"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">{{ old('observation', $precedent?->observation) }}</textarea>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('urgences.index') }}" class="min-h-[44px] px-5 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 inline-flex items-center">Annuler</a>
            <button type="submit" class="min-h-[44px] px-6 py-2 bg-red-700 hover:bg-red-800 text-white font-semibold rounded-lg">
                ✓ Fin du triage
            </button>
        </div>
    </form>
</div>
@endsection
