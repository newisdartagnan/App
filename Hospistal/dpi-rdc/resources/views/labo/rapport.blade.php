@extends('layouts.app')
@section('title', 'Rapport journalier')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-800">Rapport journalier — {{ $domaine === 'imagerie' ? 'Imagerie' : 'Laboratoire' }}</h2>
        <form method="GET" class="flex gap-2 items-center">
            <label for="rapport-date" class="text-sm text-gray-600">Date</label>
            <input id="rapport-date" type="date" name="date" value="{{ $date }}" class="min-h-[40px] rounded-lg border border-gray-300 px-3 py-1">
            <select name="domaine" class="min-h-[40px] rounded-lg border border-gray-300 px-2 py-1 text-sm">
                <option value="labo" @selected($domaine === 'labo')>Laboratoire</option>
                <option value="imagerie" @selected($domaine === 'imagerie')>Imagerie</option>
            </select>
            <button type="submit" class="min-h-[40px] px-4 py-1 bg-blue-700 text-white rounded-lg text-sm">Afficher</button>
            <button type="submit" onclick="window.print()" class="min-h-[40px] px-4 py-1 border border-blue-700 text-blue-700 rounded-lg text-sm">🖨️</button>
        </form>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center"><p class="text-2xl font-bold text-blue-700">{{ $stats['total'] }}</p><p class="text-xs text-gray-500">Demandes</p></div>
        <div class="bg-white rounded-xl shadow p-4 text-center"><p class="text-2xl font-bold text-green-700">{{ $stats['valides'] }}</p><p class="text-xs text-gray-500">Validées</p></div>
        <div class="bg-white rounded-xl shadow p-4 text-center"><p class="text-2xl font-bold text-amber-600">{{ $stats['en_cours'] }}</p><p class="text-xs text-gray-500">En cours</p></div>
        <div class="bg-white rounded-xl shadow p-4 text-center"><p class="text-2xl font-bold text-red-600">{{ $stats['urgents'] }}</p><p class="text-xs text-gray-500">Urgents</p></div>
        <div class="bg-white rounded-xl shadow p-4 text-center"><p class="text-2xl font-bold text-indigo-700">{{ number_format($stats['recettes'], 0, ',', '.') }}</p><p class="text-xs text-gray-500">Recettes (CDF)</p></div>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-sm">Examens par catégorie</div>
            @forelse($parCategorie as $categorie => $infos)
            <div class="px-4 py-2 border-b last:border-0">
                <p class="font-medium text-sm capitalize flex justify-between">{{ $categorie }} <span class="text-blue-700 font-bold">{{ $infos['total'] }}</span></p>
                @foreach($infos['examens'] as $libelle => $nombre)
                <p class="text-xs text-gray-500 flex justify-between pl-3">{{ $libelle }} <span>{{ $nombre }}</span></p>
                @endforeach
            </div>
            @empty
            <p class="px-4 py-6 text-center text-gray-400 text-sm">Aucun examen ce jour</p>
            @endforelse
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-sm">Détail des demandes</div>
            <table class="w-full text-xs">
                <thead class="bg-gray-50"><tr>
                    <th class="text-left px-3 py-2">Bon</th><th class="text-left px-3 py-2">Patient</th>
                    <th class="text-left px-3 py-2">Statut</th><th class="text-right px-3 py-2">Montant</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($examens as $e)
                    <tr>
                        <td class="px-3 py-2 font-mono">{{ $e->numero_bon }}</td>
                        <td class="px-3 py-2"><a class="text-blue-700 hover:underline" href="{{ route('labo.show', $e) }}">{{ $e->patient->nom_complet }}</a></td>
                        <td class="px-3 py-2">{{ str_replace('_', ' ', $e->statut) }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($e->facture?->total_ttc ?? 0, 0, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
