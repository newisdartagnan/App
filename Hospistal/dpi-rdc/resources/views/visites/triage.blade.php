@extends('layouts.app')
@section('title', 'Triage infirmier')
@section('content')
<div class="max-w-3xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('consultations.index') }}" class="text-blue-700 hover:underline text-sm">← File d'attente</a>
        <h2 class="text-2xl font-bold text-gray-800">Triage — {{ $visit->patient->nom_complet }}</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $visit->patient->dossier_number }}</span>
    </div>

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    <div class="bg-blue-50 rounded-xl p-4 mb-6 text-sm">
        {{ $visit->type === 'urgence' ? '🚨 Urgence' : ($visit->typeConsultation?->libelle ?? 'Ambulatoire') }}
        — arrivé à {{ $visit->date_entree->format('H:i') }}
        @if($visit->gratuite) — <span class="text-green-700 font-medium">contrôle gratuit (&lt; 7 jours)</span>@endif
    </div>

    <form method="POST" action="{{ route('visites.triage.store', $visit) }}" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf
        <div>
            <label for="motif" class="block text-sm font-medium text-gray-700 mb-1">Motif de consultation <span class="text-red-500">*</span></label>
            <input id="motif" name="motif_consultation" type="text" required
                value="{{ old('motif_consultation', $visit->motif_consultation) }}"
                class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
        </div>
        <div>
            <label for="symptomes" class="block text-sm font-medium text-gray-700 mb-1">Symptômes principaux</label>
            <textarea id="symptomes" name="symptomes_principaux" rows="2"
                class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('symptomes_principaux', $visit->symptomes_principaux) }}</textarea>
        </div>

        <h3 class="font-semibold text-gray-700 pt-2 border-t">Constantes vitales</h3>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            @foreach([
                ['poids_kg', 'Poids (kg)', '0.1'],
                ['taille_cm', 'Taille (cm)', '0.1'],
                ['temperature', 'Température (°C)', '0.1'],
                ['tension_systolique', 'TA systolique (mmHg)', '1'],
                ['tension_diastolique', 'TA diastolique (mmHg)', '1'],
                ['frequence_cardiaque', 'Fréq. cardiaque (bpm)', '1'],
                ['frequence_respiratoire', 'Fréq. respiratoire', '1'],
                ['saturation_o2', 'SpO₂ (%)', '0.1'],
            ] as [$champ, $label, $pas])
            <div>
                <label for="{{ $champ }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
                <input id="{{ $champ }}" name="{{ $champ }}" type="number" step="{{ $pas }}"
                    value="{{ old($champ, $visit->{$champ}) }}"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
            </div>
            @endforeach
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="min-h-[44px] px-6 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg">
                ✓ Enregistrer le triage
            </button>
        </div>
    </form>
</div>
@endsection
