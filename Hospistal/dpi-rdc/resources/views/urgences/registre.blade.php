@extends('layouts.app')
@section('title', 'Registre des triages')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3 no-print">
        <div class="flex items-center gap-3">
            <a href="{{ route('urgences.index') }}" class="text-blue-700 hover:underline text-sm">← Urgences</a>
            <h2 class="text-2xl font-bold text-gray-800">Registre des triages</h2>
        </div>
        <form method="GET" class="flex gap-2 items-center">
            <label for="debut" class="text-sm text-gray-600">Du</label>
            <input id="debut" type="date" name="debut" value="{{ $debut }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <label for="fin" class="text-sm text-gray-600">au</label>
            <input id="fin" type="date" name="fin" value="{{ $fin }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <button class="px-4 py-1.5 bg-blue-700 text-white rounded-lg text-sm">Afficher</button>
        </form>
    </div>

    <div class="text-center border-b-2 border-red-700 pb-3 mb-5">
        <p class="text-lg font-bold text-red-800 uppercase">{{ config('app.name', 'DPI-RDC') }}</p>
        <p class="text-sm text-gray-600">Service des urgences</p>
        <p class="text-base font-bold mt-1">
            REGISTRE DES TRIAGES — du {{ \Carbon\Carbon::parse($debut)->format('d/m/Y') }}
            au {{ \Carbon\Carbon::parse($fin)->format('d/m/Y') }}
        </p>
    </div>

    {{-- Distribution des niveaux --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        @foreach($parNiveau as $niveau => $donnees)
        @php
            $couleur = match($donnees['info']['couleur']) {
                'red' => 'text-red-700', 'orange' => 'text-orange-600',
                'yellow' => 'text-yellow-600', 'green' => 'text-green-700', default => 'text-blue-700',
            };
            $part = $triages->count() > 0 ? round($donnees['total'] / $triages->count() * 100) : 0;
        @endphp
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold {{ $couleur }}">{{ $donnees['total'] }}</p>
            <p class="text-xs font-semibold text-gray-700">Niveau {{ $niveau }}</p>
            <p class="text-[11px] text-gray-500">{{ $donnees['info']['libelle'] }}</p>
            <p class="text-[11px] text-gray-400">{{ $part }} %</p>
        </div>
        @endforeach
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">{{ $triages->count() }} triage(s) sur la période</div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="bg-red-100 text-red-900">
                        <th class="border border-gray-300 px-2 py-1.5 text-center w-10">N°</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Date et heure</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Patient</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-center w-10">Sexe</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-center w-14">Âge</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Prise en charge</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-center">Niveau</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Critères déterminants</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-center">ATR</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Achevé par</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($triages as $i => $triage)
                    <tr class="{{ $triage->niveau <= 2 ? 'bg-red-50' : '' }}">
                        <td class="border border-gray-300 px-2 py-1.5 text-center font-bold">{{ str_pad($i + 1, 3, '0', STR_PAD_LEFT) }}</td>
                        <td class="border border-gray-300 px-2 py-1.5">{{ $triage->triage_at->format('d/m/Y H:i') }}</td>
                        <td class="border border-gray-300 px-2 py-1.5">
                            <span class="font-semibold">{{ strtoupper($triage->visit->patient->nom_complet) }}</span>
                            <span class="block text-gray-400 text-[10px]">{{ $triage->visit->patient->dossier_number }}</span>
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5 text-center">{{ $triage->visit->patient->sexe ?: '—' }}</td>
                        <td class="border border-gray-300 px-2 py-1.5 text-center">{{ $triage->visit->patient->date_naissance?->age ?? '—' }}</td>
                        <td class="border border-gray-300 px-2 py-1.5">
                            {{ $triage->visit->patient->type_prise_en_charge === 'assurance'
                                ? ($triage->visit->patient->assurance_nom ?: 'Assurance') : 'Privé' }}
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5 text-center">
                            <span class="font-bold">{{ $triage->niveau }}</span>
                            <span class="block text-[10px]">{{ $triage->libelleNiveau() }}</span>
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5">
                            @foreach(($triage->criteres_declencheurs ?? []) as $critere)
                            <span class="inline-block">{{ app(\App\Services\TriageUrgenceService::class)->libelleCritere($critere) }}@if(! $loop->last), @endif</span>
                            @endforeach
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5 text-center">{{ $triage->atr ? 'Oui' : '—' }}</td>
                        <td class="border border-gray-300 px-2 py-1.5">{{ trim(($triage->auteur?->prenom ?? '') . ' ' . ($triage->auteur?->nom ?? '')) ?: '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="px-4 py-10 text-center text-gray-400">Aucun triage sur cette période</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="10" class="border-t-2 border-gray-800 px-2 py-3 text-right text-[11px] text-gray-600">
                            Responsable des urgences : _______________________ &nbsp;&nbsp; Signature : _______________
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print, nav, header, footer { display: none !important; }
    body { background: #fff; }
    tr { page-break-inside: avoid; }
}
</style>
@endsection
