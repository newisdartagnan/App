@extends('layouts.app')
@section('title', 'Caisse')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Caisse — Factures en attente</h2>
        <div class="text-sm text-gray-500">{{ now()->format('d/m/Y') }}</div>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
        {{ session('success') }}
    </div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">N° Facture</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Patient</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Actes</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Total</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Part patient</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Prise en charge</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($facturesEnAttente as $facture)
                <tr class="hover:bg-gray-50 transition">
                    <td class="px-4 py-3 font-mono text-xs font-bold text-gray-700">
                        {{ $facture->numero_facture }}
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $facture->patient->nom_complet }}</p>
                        <p class="text-xs text-gray-400">{{ $facture->patient->dossier_number }}</p>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $facture->date_facture->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $facture->lignes->count() }} acte(s)</td>
                    <td class="px-4 py-3 text-right font-medium">
                        {{ number_format($facture->total_ttc, 0, ',', '.') }} CDF
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-blue-700">
                        {{ number_format($facture->patient_part, 0, ',', '.') }} CDF
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs
                            {{ $facture->type_prise_en_charge === 'assurance' ? 'bg-blue-100 text-blue-700' :
                               ($facture->type_prise_en_charge === 'indigent' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                            {{ ucfirst($facture->type_prise_en_charge) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('caisse.show', $facture) }}"
                           class="bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                            Encaisser →
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-400">
                        Aucune facture en attente
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection