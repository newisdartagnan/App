@extends('layouts.app')
@section('title', $domaine === 'imagerie' ? 'Imagerie' : 'Laboratoire')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">{{ $domaine === 'imagerie' ? 'Imagerie médicale' : 'Laboratoire' }}</h2>
        <div class="flex items-center gap-3">
            <a href="{{ $domaine === 'imagerie' ? route('labo.index') : route('imagerie.index') }}" class="text-blue-700 hover:underline text-sm">
                → {{ $domaine === 'imagerie' ? 'Laboratoire' : 'Imagerie' }}
            </a>
            <span class="text-xs text-gray-400">Prescription d'examens : depuis le parcours patient (Consultations → visite → Prescrire)</span>
        </div>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="px-4 py-3">Patient</th>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Examens</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($examens as $examen)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium">{{ $examen->patient->nom_complet }}</td>
                    <td class="px-4 py-3">{{ $examen->date_prescription?->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">{{ $examen->resultats->pluck('typeExamen.libelle')->join(', ') }}</td>
                    <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs bg-gray-100">{{ $examen->statut }}</span></td>
                    <td class="px-4 py-3 text-right"><a href="{{ route('labo.show', $examen) }}" class="text-blue-700">Bilan →</a></td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucun examen</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $examens->links() }}</div>
</div>
@endsection
