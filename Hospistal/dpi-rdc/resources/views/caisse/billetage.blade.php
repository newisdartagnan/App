@extends('layouts.app')
@section('title', 'Billetage')
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6 flex-wrap">
        <a href="{{ route('caisse.index') }}" class="text-blue-700 hover:underline text-sm">← Caisse</a>
        <h2 class="text-2xl font-bold text-gray-800">💵 Billetage — comptage de caisse</h2>
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

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="devise" class="block text-xs text-gray-500 mb-1">Devise comptée</label>
            <select id="devise" name="devise" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="CDF" @selected($devise === 'CDF')>Franc congolais</option>
                <option value="USD" @selected($devise === 'USD')>Dollar américain</option>
            </select>
        </div>
        <div>
            <label for="debut" class="block text-xs text-gray-500 mb-1">Début de période</label>
            <input id="debut" type="datetime-local" name="debut" value="{{ $debut }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="fin" class="block text-xs text-gray-500 mb-1">Fin de période</label>
            <input id="fin" type="datetime-local" name="fin" value="{{ $fin }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="px-4 py-2 bg-gray-700 text-white rounded-lg text-sm">Recalculer</button>
    </form>

    <form method="POST" action="{{ route('caisse.billetage.store') }}" class="bg-white rounded-xl shadow overflow-hidden mb-6">
        @csrf
        <input type="hidden" name="devise" value="{{ $devise }}">
        <input type="hidden" name="debut" value="{{ str_replace('T', ' ', $debut) }}:00">
        <input type="hidden" name="fin" value="{{ str_replace('T', ' ', $fin) }}:59">

        <div class="px-4 py-3 border-b font-semibold text-gray-700">
            Comptage physique — {{ $devise === 'USD' ? 'dollars' : 'francs congolais' }}
        </div>

        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Coupure</th>
                    <th class="px-4 py-2 text-right">Nombre</th>
                    <th class="px-4 py-2 text-right">Sous-total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($coupures as $coupure)
                <tr>
                    <td class="px-4 py-2 font-semibold">{{ number_format($coupure, 0, ',', ' ') }} {{ $devise }}</td>
                    <td class="px-4 py-2 text-right">
                        <label for="c-{{ $coupure }}" class="sr-only">Nombre de coupures de {{ $coupure }}</label>
                        <input id="c-{{ $coupure }}" type="number" min="0" step="1"
                            name="coupures[{{ $coupure }}]" value="0"
                            class="w-28 border border-gray-300 rounded px-2 py-1 text-right">
                    </td>
                    <td class="px-4 py-2 text-right text-gray-400">× {{ number_format($coupure, 0, ',', ' ') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="px-4 py-3 border-t bg-gray-50 space-y-3">
            @if($devise === 'CDF')
            <p class="text-sm text-gray-600">
                Recettes espèces attendues sur la période :
                <strong class="text-blue-800">{{ number_format($theorique, 0, ',', ' ') }} CDF</strong>
                <span class="text-xs text-gray-400">— l'écart sera calculé à l'enregistrement.</span>
            </p>
            @endif
            <div>
                <label for="observation" class="block text-xs text-gray-500 mb-1">Observation</label>
                <input id="observation" name="observation" placeholder="Fond de caisse, remise à la banque…"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-5 py-2 rounded-lg font-semibold">
                Enregistrer le billetage
            </button>
        </div>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Billetages précédents</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Date et heure</th>
                    <th class="px-4 py-2 text-left">Caissier</th>
                    <th class="px-4 py-2 text-right">Compté</th>
                    <th class="px-4 py-2 text-right">Théorique</th>
                    <th class="px-4 py-2 text-right">Écart</th>
                    <th class="px-4 py-2 text-left">Observation</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($historique as $b)
                <tr class="{{ $b->ecartSignificatif() ? 'bg-amber-50' : '' }}">
                    <td class="px-4 py-2 text-xs">{{ $b->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-2 text-xs">{{ $b->caissier?->nom }}</td>
                    <td class="px-4 py-2 text-right font-semibold">{{ number_format((float) $b->total_compte, 0, ',', ' ') }} {{ $b->devise }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">{{ number_format((float) $b->total_theorique, 0, ',', ' ') }}</td>
                    <td class="px-4 py-2 text-right font-semibold {{ $b->ecartSignificatif() ? ((float) $b->ecart < 0 ? 'text-red-700' : 'text-amber-700') : 'text-green-700' }}">
                        {{ (float) $b->ecart > 0 ? '+' : '' }}{{ number_format((float) $b->ecart, 0, ',', ' ') }}
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-500">{{ $b->observation ?: '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucun billetage enregistré</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
