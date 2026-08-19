@extends('layouts.app')
@section('title', 'Bilan hydrique — ' . $visit->patient->nom_complet)
@section('content')
@php
    $jourCarbon = \Carbon\Carbon::parse($jour);
    $entrees = \App\Models\BilanHydrique::ENTREES;
    $sorties = \App\Models\BilanHydrique::SORTIES;
    $tranches = \App\Models\BilanHydrique::TRANCHES;
    $totalEntrees = $bilans->sum(fn ($b) => $b->totalEntrees());
    $totalSorties = $bilans->sum(fn ($b) => $b->totalSorties());
    $balance = $totalEntrees - $totalSorties;
@endphp
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-4 flex-wrap">
        @if($visit->service)
        <a href="{{ route('services.dossier', [$visit->service, $visit]) }}" class="text-blue-700 hover:underline text-sm">← Dossier de séjour</a>
        @else
        <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline text-sm">← Parcours</a>
        @endif
        <h2 class="text-2xl font-bold text-gray-800">💧 Bilan hydrique</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
            {{ $visit->patient->nom_complet }} · Lit {{ $visit->lit?->numero ?? '—' }}
        </span>
        <a href="{{ route('mar.index', ['visit' => $visit->id, 'jour' => $jour]) }}"
           class="ml-auto text-sm text-blue-700 hover:underline">💉 Plan de traitement →</a>
    </div>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    <div class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('bilan-hydrique.index', ['visit' => $visit->id, 'jour' => $jourCarbon->copy()->subDay()->toDateString()]) }}"
           class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Veille</a>
        <form method="GET" class="flex gap-2 items-center">
            <label for="jour" class="text-sm text-gray-600">Jour</label>
            <input id="jour" type="date" name="jour" value="{{ $jour }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <button class="px-3 py-1.5 bg-blue-700 text-white rounded-lg text-sm">Afficher</button>
        </form>
        <a href="{{ route('bilan-hydrique.index', ['visit' => $visit->id, 'jour' => $jourCarbon->copy()->addDay()->toDateString()]) }}"
           class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Lendemain →</a>
        <span class="font-semibold text-blue-900 ml-2">{{ $jourCarbon->locale('fr')->isoFormat('dddd D MMMM YYYY') }}</span>
    </div>

    {{-- Balance du jour --}}
    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-blue-700">{{ number_format($totalEntrees, 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Entrées (mL)</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-amber-700">{{ number_format($totalSorties, 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Sorties (mL)</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold {{ abs($balance) > 1500 ? 'text-red-700' : ($balance >= 0 ? 'text-green-700' : 'text-orange-700') }}">
                {{ $balance > 0 ? '+' : '' }}{{ number_format($balance, 0, ',', ' ') }}
            </p>
            <p class="text-xs text-gray-500">Balance du jour (mL)</p>
        </div>
    </div>

    @if(abs($balance) > 1500)
    <div class="bg-red-50 border border-red-300 rounded-xl px-4 py-3 mb-4 text-sm text-red-800">
        ⚠️ Balance de {{ $balance > 0 ? '+' : '' }}{{ number_format($balance, 0, ',', ' ') }} mL —
        {{ $balance > 0 ? 'rétention hydrique' : 'perte hydrique' }} à signaler au médecin.
    </div>
    @endif

    {{-- Saisie par tranche --}}
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
        @foreach($tranches as $cle => $libelle)
        @php $bilan = $bilans->get($cle); @endphp
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b bg-blue-50 flex justify-between items-center">
                <span class="font-semibold text-blue-900 text-sm">{{ $libelle }}</span>
                @if($bilan)
                <span class="text-xs font-bold {{ $bilan->balance() >= 0 ? 'text-green-700' : 'text-orange-700' }}">
                    {{ $bilan->balance() > 0 ? '+' : '' }}{{ $bilan->balance() }} mL
                </span>
                @endif
            </div>
            <form method="POST" action="{{ route('bilan-hydrique.store', $visit) }}" class="p-4 space-y-3">
                @csrf
                <input type="hidden" name="jour" value="{{ $jour }}">
                <input type="hidden" name="tranche" value="{{ $cle }}">

                <p class="text-[11px] font-bold uppercase text-blue-700">Entrées (mL)</p>
                @foreach($entrees as $champ => $etiquette)
                <div class="flex items-center gap-2">
                    <label for="{{ $cle }}-{{ $champ }}" class="flex-1 text-xs text-gray-700">{{ $etiquette }}</label>
                    <input id="{{ $cle }}-{{ $champ }}" type="number" min="0" max="20000" step="10"
                        name="{{ $champ }}" value="{{ $bilan->{$champ} ?? 0 }}"
                        class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right">
                </div>
                @endforeach

                <p class="text-[11px] font-bold uppercase text-amber-700 pt-1">Sorties (mL)</p>
                @foreach($sorties as $champ => $etiquette)
                <div class="flex items-center gap-2">
                    <label for="{{ $cle }}-{{ $champ }}" class="flex-1 text-xs text-gray-700">{{ $etiquette }}</label>
                    <input id="{{ $cle }}-{{ $champ }}" type="number" min="0" max="20000" step="10"
                        name="{{ $champ }}" value="{{ $bilan->{$champ} ?? 0 }}"
                        class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right">
                </div>
                @endforeach

                <label for="{{ $cle }}-obs" class="sr-only">Observation</label>
                <input id="{{ $cle }}-obs" name="observation" value="{{ $bilan->observation ?? '' }}"
                    placeholder="Observation" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs">

                <button class="w-full bg-blue-700 hover:bg-blue-800 text-white text-sm py-1.5 rounded-lg font-semibold">
                    {{ $bilan ? 'Mettre à jour' : 'Enregistrer' }}
                </button>
                @if($bilan)
                <p class="text-[10px] text-gray-400 text-center">
                    Saisi par {{ $bilan->auteur?->nom }} · {{ $bilan->updated_at->format('d/m H:i') }}
                </p>
                @endif
            </form>
        </div>
        @endforeach
    </div>

    {{-- Historique du séjour --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Historique du séjour</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Jour</th>
                        <th class="px-4 py-2 text-center">Tranches saisies</th>
                        <th class="px-4 py-2 text-right">Entrées</th>
                        <th class="px-4 py-2 text-right">Sorties</th>
                        <th class="px-4 py-2 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($historique as $date => $lignes)
                    @php
                        $e = $lignes->sum(fn ($b) => $b->totalEntrees());
                        $s = $lignes->sum(fn ($b) => $b->totalSorties());
                        $bal = $e - $s;
                    @endphp
                    <tr class="{{ $date === $jour ? 'bg-blue-50' : '' }}">
                        <td class="px-4 py-2">
                            <a href="{{ route('bilan-hydrique.index', ['visit' => $visit->id, 'jour' => $date]) }}"
                               class="text-blue-700 hover:underline">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</a>
                        </td>
                        <td class="px-4 py-2 text-center text-xs text-gray-500">{{ $lignes->count() }} / 3</td>
                        <td class="px-4 py-2 text-right text-blue-700">{{ number_format($e, 0, ',', ' ') }}</td>
                        <td class="px-4 py-2 text-right text-amber-700">{{ number_format($s, 0, ',', ' ') }}</td>
                        <td class="px-4 py-2 text-right font-bold {{ abs($bal) > 1500 ? 'text-red-700' : ($bal >= 0 ? 'text-green-700' : 'text-orange-700') }}">
                            {{ $bal > 0 ? '+' : '' }}{{ number_format($bal, 0, ',', ' ') }}
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucun bilan enregistré pour ce séjour</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
