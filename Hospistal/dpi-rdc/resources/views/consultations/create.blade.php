@extends('layouts.app')
@section('title', 'Consultation')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('consultations.index') }}" class="text-blue-700 hover:underline text-sm">← File d'attente</a>
        <h2 class="text-2xl font-bold text-gray-800">Consultation — {{ $patient->nom_complet }}</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $patient->dossier_number }}</span>
    </div>

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    {{-- Contexte de la visite + triage infirmier --}}
    <div class="bg-blue-50 rounded-xl p-4 mb-4 text-sm">
        <div class="flex flex-wrap gap-x-6 gap-y-1">
            <span class="font-semibold text-blue-800">
                {{ $visit->type === 'urgence' ? '🚨 Urgence' : ($visit->typeConsultation?->libelle ?? 'Ambulatoire') }}
                @if($visit->typeConsultation?->specialite) ({{ $visit->typeConsultation->specialite }}) @endif
            </span>
            <span>{{ $visit->gratuite ? '🆓 Contrôle gratuit (< 7 jours)' : '✅ Payée à la caisse' }}</span>
            <span>{{ $visit->estTriee() ? '✓ Trié par ' . ($visit->triagePar?->prenom ?? 'infirmier(ère)') : '⏳ Triage non fait' }}</span>
        </div>
        @if($visit->motif_consultation)<p class="mt-1"><strong>Motif :</strong> {{ $visit->motif_consultation }}</p>@endif
        @if($visit->estTriee())
        <p class="mt-1 text-gray-600">
            @if($visit->poids_kg) Poids {{ $visit->poids_kg + 0 }} kg · @endif
            @if($visit->taille_cm) Taille {{ $visit->taille_cm + 0 }} cm · @endif
            @if($visit->temperature) T° {{ $visit->temperature + 0 }} °C · @endif
            @if($visit->tension_systolique) TA {{ $visit->tension_systolique }}/{{ $visit->tension_diastolique }} · @endif
            @if($visit->frequence_cardiaque) FC {{ $visit->frequence_cardiaque }} bpm · @endif
            @if($visit->saturation_o2) SpO₂ {{ $visit->saturation_o2 + 0 }} % @endif
        </p>
        @else
        <a href="{{ route('visites.triage', $visit) }}" class="text-blue-700 underline text-xs">Faire le triage d'abord →</a>
        @endif
    </div>

    @if(count($visit->alertesVitales()) > 0)
    <div class="bg-red-50 border border-red-300 rounded-xl p-4 mb-4">
        <h4 class="font-semibold text-red-800 mb-1">🚨 Valeurs critiques</h4>
        @foreach($visit->alertesVitales() as $alerte)<p class="text-sm text-red-700">{{ $alerte }}</p>@endforeach
    </div>
    @endif

    <form method="POST" action="{{ route('visites.consultation.store', $visit) }}" class="space-y-4">
        @csrf

        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h3 class="font-semibold text-gray-700 pb-2 border-b">Anamnèse</h3>
            <div>
                <label for="histoire_maladie" class="block text-sm font-medium text-gray-700 mb-1">Histoire de la maladie</label>
                <textarea id="histoire_maladie" name="histoire_maladie" rows="3" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('histoire_maladie') }}</textarea>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="antecedents_personnels" class="block text-sm font-medium text-gray-700 mb-1">Antécédents personnels</label>
                    <textarea id="antecedents_personnels" name="antecedents_personnels" rows="2" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('antecedents_personnels') }}</textarea>
                </div>
                <div>
                    <label for="antecedents_familiaux" class="block text-sm font-medium text-gray-700 mb-1">Antécédents familiaux</label>
                    <textarea id="antecedents_familiaux" name="antecedents_familiaux" rows="2" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('antecedents_familiaux') }}</textarea>
                </div>
                <div>
                    <label for="allergies" class="block text-sm font-medium text-gray-700 mb-1">Allergies</label>
                    <textarea id="allergies" name="allergies" rows="2" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('allergies') }}</textarea>
                </div>
                <div>
                    <label for="traitements" class="block text-sm font-medium text-gray-700 mb-1">Traitements en cours</label>
                    <textarea id="traitements" name="traitements_en_cours" rows="2" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('traitements_en_cours') }}</textarea>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h3 class="font-semibold text-gray-700 pb-2 border-b">Examen clinique</h3>
            <div>
                <label for="examen_general" class="block text-sm font-medium text-gray-700 mb-1">Examen général et physique</label>
                <textarea id="examen_general" name="examen_general" rows="4" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('examen_general') }}</textarea>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h3 class="font-semibold text-gray-700 pb-2 border-b">Diagnostics</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @for($i = 0; $i < 3; $i++)
                <div class="border rounded-lg p-3 {{ $i === 0 ? 'border-blue-300 bg-blue-50/40' : 'border-gray-200' }}">
                    <p class="text-xs font-semibold {{ $i === 0 ? 'text-blue-700' : 'text-gray-500' }} mb-2">
                        {{ $i === 0 ? 'Diagnostic principal' : 'Diagnostic associé ' . $i }}
                    </p>
                    <label for="diag-lib-{{ $i }}" class="sr-only">Libellé</label>
                    <input id="diag-lib-{{ $i }}" name="diagnostics[{{ $i }}][libelle]" type="text"
                        value="{{ old('diagnostics.' . $i . '.libelle') }}"
                        placeholder="Libellé (ex: paludisme simple)"
                        class="w-full min-h-[40px] rounded border border-gray-300 px-3 py-1.5 text-sm mb-2">
                    <label for="diag-cim-{{ $i }}" class="sr-only">Code CIM-10</label>
                    <input id="diag-cim-{{ $i }}" name="diagnostics[{{ $i }}][code_cim10]" type="text"
                        value="{{ old('diagnostics.' . $i . '.code_cim10') }}"
                        placeholder="Code CIM-10 (ex: B50)"
                        class="w-full min-h-[40px] rounded border border-gray-300 px-3 py-1.5 text-sm">
                </div>
                @endfor
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6 space-y-4">
            <h3 class="font-semibold text-gray-700 pb-2 border-b">Conclusion & plan</h3>
            <div>
                <label for="conclusion" class="block text-sm font-medium text-gray-700 mb-1">Conclusion</label>
                <textarea id="conclusion" name="conclusion" rows="2" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('conclusion') }}</textarea>
            </div>
            <div>
                <label for="conduite" class="block text-sm font-medium text-gray-700 mb-1">Conduite à tenir</label>
                <textarea id="conduite" name="conduite_a_tenir" rows="2" class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('conduite_a_tenir') }}</textarea>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('consultations.index') }}" class="min-h-[44px] px-5 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 inline-flex items-center">Annuler</a>
            <button type="submit" class="min-h-[44px] px-6 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg">
                ✓ Enregistrer la consultation
            </button>
        </div>
    </form>
</div>
@endsection
