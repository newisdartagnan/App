@extends('layouts.app')
@section('title', 'Ordonnances')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('pharmacie.dashboard') }}" class="text-blue-700 hover:underline text-sm">← Pharmacie</a>
        <h2 class="text-2xl font-bold text-gray-800">Ordonnances en attente</h2>
    </div>
    @php
        $prescriptions = \App\Models\Prescription::with(['patient', 'prescripteur', 'lignes'])
            ->where('statut', 'en_attente')
            ->orderByDesc('date_prescription')
            ->get();
    @endphp
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Patient</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Prescripteur</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Médicaments</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($prescriptions as $prescription)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $prescription->patient->nom_complet }}</p>
                        <p class="text-xs text-gray-400">{{ $prescription->patient->dossier_number }}</p>
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        Dr {{ $prescription->prescripteur->nom }} {{ $prescription->prescripteur->prenom }}
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $prescription->date_prescription->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $prescription->lignes->count() }} médicament(s)</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs bg-amber-100 text-amber-700">En attente</span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('pharmacie.prescription', $prescription) }}"
                           class="text-blue-700 hover:underline text-xs font-medium">Dispenser →</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucune ordonnance en attente</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection