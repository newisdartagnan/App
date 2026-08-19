@extends('layouts.app')
@section('title', 'Dettes à recouvrer')
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('conventions.index') }}" class="text-blue-700 hover:underline text-sm">← Conventions</a>
        <h2 class="text-2xl font-bold text-gray-800">Dettes à recouvrer</h2>
    </div>

    @php $total = $dettes->sum('du'); @endphp
    <div class="bg-white rounded-xl shadow p-5 mb-4 text-center">
        <p class="text-3xl font-bold text-amber-700">{{ number_format($total, 0, ',', ' ') }} CDF</p>
        <p class="text-sm text-gray-500">restant dus par {{ $dettes->count() }} convention(s)</p>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Convention</th>
                    <th class="px-4 py-2 text-center">Factures ouvertes</th>
                    <th class="px-4 py-2 text-left">Plus ancienne</th>
                    <th class="px-4 py-2 text-right">Montant dû</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($dettes as $d)
                @php $anciennete = \Carbon\Carbon::parse($d['plus_ancienne'])->diffInDays(now()); @endphp
                <tr class="{{ $anciennete > 90 ? 'bg-red-50' : '' }}">
                    <td class="px-4 py-2 font-semibold">{{ $d['assurance']->nom }}</td>
                    <td class="px-4 py-2 text-center">{{ $d['factures'] }}</td>
                    <td class="px-4 py-2 text-xs {{ $anciennete > 90 ? 'text-red-700 font-semibold' : 'text-gray-500' }}">
                        {{ \Carbon\Carbon::parse($d['plus_ancienne'])->format('d/m/Y') }}
                        ({{ (int) $anciennete }} j)
                    </td>
                    <td class="px-4 py-2 text-right font-bold">{{ number_format($d['du'], 0, ',', ' ') }} CDF</td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400">Aucune dette en cours</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
