@extends('layouts.app')
@section('title', 'Ordonnance')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('consultations.show', $consultation) }}" class="text-blue-700 hover:underline text-sm">← Consultation</a>
        <h2 class="text-2xl font-bold text-gray-800">Ordonnance — {{ $consultation->visit->patient->nom_complet }}</h2>
    </div>

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    <form method="POST" action="{{ route('prescriptions.store', $consultation) }}" class="bg-white rounded-xl shadow p-6">
        @csrf
        <p class="text-sm text-gray-500 mb-4">Remplissez une ligne par médicament (les lignes vides sont ignorées). Stock affiché entre parenthèses.</p>

        @for($i = 0; $i < 5; $i++)
        <div class="border-b py-4 grid grid-cols-2 md:grid-cols-6 gap-3 items-end">
            <div class="col-span-2">
                <label for="med-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">Médicament</label>
                <select id="med-{{ $i }}" name="lignes[{{ $i }}][medicament_id]" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
                    <option value="">—</option>
                    @foreach($medicaments as $med)
                    <option value="{{ $med->id }}">
                        {{ $med->denomination_commune }} {{ $med->dosage }} ({{ ($med->stock?->quantite_disponible ?? 0) + 0 }} {{ $med->unite_dispensation }})
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="dose-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">Dose</label>
                <input id="dose-{{ $i }}" name="lignes[{{ $i }}][dose]" type="text" placeholder="1 cp"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
            </div>
            <div>
                <label for="freq-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">Fréquence</label>
                <input id="freq-{{ $i }}" name="lignes[{{ $i }}][frequence]" type="text" placeholder="3×/jour"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
            </div>
            <div>
                <label for="duree-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">Durée (j)</label>
                <input id="duree-{{ $i }}" name="lignes[{{ $i }}][duree_jours]" type="number" min="1"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
            </div>
            <div>
                <label for="qte-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">Qté totale</label>
                <input id="qte-{{ $i }}" name="lignes[{{ $i }}][quantite_totale]" type="number" step="0.5" min="0.5"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
            </div>
        </div>
        @endfor

        <div class="mt-4">
            <label for="observations" class="block text-sm font-medium text-gray-700 mb-1">Observations</label>
            <textarea id="observations" name="observations" rows="2" class="w-full rounded-lg border border-gray-300 px-4 py-2"></textarea>
        </div>

        <div class="flex justify-end mt-4">
            <button type="submit" class="min-h-[44px] px-6 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg">
                ✓ Enregistrer l'ordonnance
            </button>
        </div>
    </form>
</div>
@endsection
