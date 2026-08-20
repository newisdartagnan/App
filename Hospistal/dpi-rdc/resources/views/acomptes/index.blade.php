@extends('layouts.app')
@section('title', 'Acomptes de soins')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">💰 Acomptes de soins</h2>
    <p class="text-sm text-gray-500 mb-5">Avances encaissées aux urgences et en hospitalisation.</p>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-blue-700">{{ number_format($totaux['verse'], 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Total encaissé</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-purple-700">{{ number_format($totaux['impute'], 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Imputé sur factures</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-amber-700">{{ number_format($totaux['rembourse'], 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Remboursé</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-green-700">{{ number_format($totaux['disponible'], 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Encore en caisse</p>
        </div>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-statut" class="block text-xs font-semibold text-gray-600 mb-1">Statut</label>
            <select id="f-statut" name="statut" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="tous" @selected($statut === 'tous')>Tous</option>
                @foreach(\App\Models\Caution::STATUTS as $c => $l)
                <option value="{{ $c }}" @selected($statut === $c)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Filtrer</button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Patient</th>
                        <th class="px-4 py-3">Service</th>
                        <th class="px-4 py-3">Nature</th>
                        <th class="px-4 py-3 text-right">Montant</th>
                        <th class="px-4 py-3 text-right">Imputé</th>
                        <th class="px-4 py-3 text-right">Disponible</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3">Encaissé le</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($acomptes as $acompte)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium">{{ $acompte->patient?->nom_complet }}</td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ $acompte->visit?->service?->nom ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $acompte->libelleType() }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format((float) $acompte->montant, 0, ',', ' ') }} {{ $acompte->devise }}</td>
                        <td class="px-4 py-3 text-right text-purple-700">{{ number_format((float) $acompte->montant_impute, 0, ',', ' ') }}</td>
                        <td class="px-4 py-3 text-right font-semibold {{ $acompte->resteDisponible() > 0 ? 'text-green-700' : 'text-gray-400' }}">
                            {{ number_format($acompte->resteDisponible(), 0, ',', ' ') }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $acompte->statut === 'versee' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                {{ $acompte->libelleStatut() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $acompte->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($acompte->visit_id)
                            <a href="{{ route('acomptes.show', $acompte->visit_id) }}" class="text-blue-700 hover:underline text-xs">Détail →</a>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Aucun acompte pour ce filtre</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $acomptes->links() }}</div>
</div>
@endsection
