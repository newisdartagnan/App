@extends('layouts.app')
@section('title', 'Horaire du bloc')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🏥 Bloc opératoire</h2>
    <p class="text-sm text-gray-500 mb-5">
        Une semaine, une salle. Chaque bloc coloré occupe la salle du début à la
        fin du créneau réservé.
    </p>

    @include('bloc._onglets')
    @include('bloc._flash')

    {{-- Choix de la salle --}}
    <div class="flex flex-wrap gap-1 mb-4">
        @foreach($salles as $salle)
        <a href="{{ route('bloc.horaire', ['salle' => $salle->id, 'semaine' => $lundi->toDateString()]) }}"
           class="px-4 py-2 rounded-lg text-sm border {{ $salleCourante?->id === $salle->id ? 'bg-blue-700 text-white border-blue-700 font-semibold' : 'bg-white text-gray-700 border-gray-300 hover:border-blue-400' }}">
            {{ $salle->nom }}
        </a>
        @endforeach
    </div>

    {{-- Navigation par semaine --}}
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <a href="{{ route('bloc.horaire', ['salle' => $salleCourante?->id, 'semaine' => $lundi->copy()->subWeek()->toDateString()]) }}"
           class="text-sm text-blue-700 hover:underline">← Semaine précédente</a>
        <p class="font-semibold text-gray-700">
            Semaine du {{ $lundi->format('d/m/Y') }} au {{ $lundi->copy()->endOfWeek()->format('d/m/Y') }}
            @if($salleCourante) — {{ $salleCourante->nom }}@endif
        </p>
        <a href="{{ route('bloc.horaire', ['salle' => $salleCourante?->id, 'semaine' => $lundi->copy()->addWeek()->toDateString()]) }}"
           class="text-sm text-blue-700 hover:underline">Semaine suivante →</a>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="w-16 border border-gray-200 px-2 py-2 text-gray-500 font-medium">Heure</th>
                        @for($j = 0; $j < 7; $j++)
                        @php $jour = $lundi->copy()->addDays($j); @endphp
                        <th class="border border-gray-200 px-2 py-2 text-gray-700 font-medium {{ $jour->isToday() ? 'bg-blue-50 text-blue-800' : '' }}">
                            {{ ucfirst($jour->translatedFormat('l d')) }}
                        </th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @foreach($heures as $heure)
                    <tr>
                        <td class="border border-gray-200 px-2 py-1 text-gray-500 align-top text-right">
                            {{ sprintf('%02d:00', $heure) }}
                        </td>
                        @for($j = 0; $j < 7; $j++)
                        @php
                            $jour = $lundi->copy()->addDays($j);
                            // Une intervention occupe la case dès qu'elle
                            // chevauche l'heure affichée, même commencée avant.
                            $duJour = $interventions[$jour->toDateString()] ?? collect();
                            $occupants = $duJour->filter(function ($acte) use ($jour, $heure) {
                                $creneau = $jour->copy()->setTime($heure, 0);
                                return $acte->date_prevue->lt($creneau->copy()->addHour())
                                    && $acte->finPrevue()->gt($creneau);
                            });
                        @endphp
                        <td class="border border-gray-200 px-1 py-1 align-top {{ $jour->isToday() ? 'bg-blue-50/40' : '' }}"
                            style="min-width:120px;">
                            @foreach($occupants as $acte)
                            @if($acte->date_prevue->hour === $heure)
                            {{-- L'intervention n'est écrite qu'à son heure de
                                 début ; les heures suivantes restent grisées. --}}
                            <div class="rounded px-1.5 py-1 mb-1 {{ $acte->urgence ? 'bg-red-100 text-red-900' : ($acte->statut === 'realise' ? 'bg-green-100 text-green-900' : 'bg-purple-100 text-purple-900') }}">
                                <p class="font-semibold leading-tight">{{ $acte->date_prevue->format('H:i') }} — {{ $acte->libelle }}</p>
                                <p class="leading-tight">{{ $acte->patient->nom_complet }}</p>
                                <p class="leading-tight opacity-75">
                                    {{ $acte->operateur ? 'Dr '.$acte->operateur->nom : 'chirurgien à désigner' }}
                                    · {{ $acte->duree_minutes ?: 60 }} min
                                </p>
                            </div>
                            @else
                            <div class="rounded {{ $acte->urgence ? 'bg-red-50' : ($acte->statut === 'realise' ? 'bg-green-50' : 'bg-purple-50') }}"
                                 style="height:18px;" title="{{ $acte->libelle }} (suite)"></div>
                            @endif
                            @endforeach
                        </td>
                        @endfor
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-wrap gap-4 mt-3 text-xs text-gray-600">
        <span><span class="inline-block w-3 h-3 rounded bg-purple-100 border border-purple-300 align-middle"></span> Planifiée</span>
        <span><span class="inline-block w-3 h-3 rounded bg-green-100 border border-green-300 align-middle"></span> Réalisée</span>
        <span><span class="inline-block w-3 h-3 rounded bg-red-100 border border-red-300 align-middle"></span> Urgence</span>
    </div>
</div>
@endsection
