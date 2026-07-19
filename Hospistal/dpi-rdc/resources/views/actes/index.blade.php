@extends('layouts.app')
@section('title', $domaine === 'maternite' ? 'Maternité' : ($domaine === 'examen_specialise' ? 'Examens spécialisés' : 'Bloc opératoire'))
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold">{{ $domaine === 'maternite' ? 'Maternité' : ($domaine === 'examen_specialise' ? 'Examens spécialisés' : 'Bloc opératoire') }}</h2>
        <a href="{{ $domaine === 'maternite' ? route('maternite.create') : route('bloc.create') }}" class="bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">+ Nouvel acte</a>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-3 text-left">Patient</th>
                <th class="px-4 py-3 text-left">Acte</th>
                <th class="px-4 py-3 text-left">Statut</th>
                <th class="px-4 py-3 text-left">Montant</th>
                <th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse($actes as $acte)
                <tr class="border-t">
                    <td class="px-4 py-3">{{ $acte->patient->nom_complet }}</td>
                    <td class="px-4 py-3">{{ $acte->libelle }}</td>
                    <td class="px-4 py-3">{{ $acte->statut }}</td>
                    <td class="px-4 py-3">{{ number_format($acte->montantTotal(), 0, ',', ' ') }} CDF</td>
                    <td class="px-4 py-3 text-right space-x-2">
                        @if(!$acte->facture_id)
                        <form method="POST" action="{{ route('actes.facturer', $acte) }}" class="inline">@csrf<button class="text-amber-700 text-xs">Facturer</button></form>
                        @endif
                        @if($acte->visit)
                        <a href="{{ route('visites.show', $acte->visit) }}" class="text-blue-700 text-xs">Parcours</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucun acte</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $actes->links() }}</div>
</div>
@endsection
