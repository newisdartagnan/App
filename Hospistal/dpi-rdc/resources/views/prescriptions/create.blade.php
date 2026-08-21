@extends('layouts.app')
@section('title', 'Ordonnance')
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route('consultations.show', $consultation) }}" class="text-blue-700 hover:underline text-sm">← Consultation</a>
        <h2 class="text-2xl font-bold text-gray-800">Ordonnance — {{ $consultation->visit->patient->nom_complet }}</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Le patient règle à la caisse, puis retire ses produits à
        <strong>{{ $officine?->nom ?? 'l\'officine' }}</strong>.
        Le dépôt central ne délivre pas aux patients.
    </p>

    @php
        $allergiesPatient = app(\App\Services\DossierMedicalService::class)
            ->allergies($consultation->visit->patient);
    @endphp

    @if($allergiesPatient->isNotEmpty())
    <div class="bg-red-50 border-2 border-red-300 rounded-xl px-4 py-3 mb-4">
        <p class="font-bold text-red-800 mb-1">⚠️ Allergies connues de ce patient</p>
        <p class="text-sm text-red-700">
            @foreach($allergiesPatient as $a){{ $a->referentiel->libelle }}@if($a->severite) ({{ $a->severite }})@endif@if(! $loop->last) · @endif @endforeach
        </p>
    </div>
    @endif

    @if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm font-semibold">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    <form method="POST" action="{{ route('prescriptions.store', $consultation) }}" class="bg-white rounded-xl shadow p-6">
        @csrf

        <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-5 text-sm text-blue-900">
            <strong>La quantité se calcule toute seule.</strong>
            Posez la dose par prise, le nombre de prises par jour et la durée :
            2 comprimés × 3 fois × 5 jours = 30 comprimés. La pharmacie servira
            le conditionnement entier (3 plaquettes de 10), et c'est ce qui sera facturé.
        </div>

        @for($i = 0; $i < 5; $i++)
        <div class="border-b py-4">
            <div class="grid grid-cols-2 md:grid-cols-12 gap-3 items-end">
                <div class="col-span-2 md:col-span-5">
                    <label for="med-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">
                        Médicament de l'officine
                    </label>
                    <select id="med-{{ $i }}" name="lignes[{{ $i }}][medicament_id]"
                            class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
                        <option value="">— aucun (voir ligne externe ci-dessous) —</option>
                        @foreach($medicaments as $med)
                        <option value="{{ $med->id }}" @selected(old("lignes.$i.medicament_id") === $med->id)>
                            {{ $med->libelleComplet($med->stock_officine) }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="dose-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">
                        Dose par prise
                    </label>
                    <input id="dose-{{ $i }}" name="lignes[{{ $i }}][dose]" type="number" step="0.25" min="0.25"
                           value="{{ old("lignes.$i.dose") }}" placeholder="1"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="freq-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">
                        Prises / jour
                    </label>
                    <input id="freq-{{ $i }}" name="lignes[{{ $i }}][frequence]" type="number" min="1" max="12"
                           value="{{ old("lignes.$i.frequence") }}" placeholder="3"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
                </div>
                <div class="md:col-span-1">
                    <label for="duree-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">Jours</label>
                    <input id="duree-{{ $i }}" name="lignes[{{ $i }}][duree_jours]" type="number" min="1" max="365"
                           value="{{ old("lignes.$i.duree_jours") }}" placeholder="5"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="qte-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">
                        Quantité <span class="text-gray-400 font-normal">(calculée)</span>
                    </label>
                    <input id="qte-{{ $i }}" name="lignes[{{ $i }}][quantite_totale]" type="number" step="0.5" min="0.5"
                           value="{{ old("lignes.$i.quantite_totale") }}" placeholder="auto"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 mt-2">
                <div class="md:col-span-5">
                    <label for="ext-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">
                        …ou produit à acheter à l'extérieur
                    </label>
                    <input id="ext-{{ $i }}" name="lignes[{{ $i }}][libelle_externe]" maxlength="255"
                           value="{{ old("lignes.$i.libelle_externe") }}"
                           placeholder="Insuline glargine 100 UI/ml — indisponible à l'officine"
                           class="w-full min-h-[44px] rounded-lg border border-amber-300 bg-amber-50 px-2 py-2 text-sm">
                </div>
                <div class="md:col-span-7">
                    <label for="inst-{{ $i }}" class="block text-xs font-medium text-gray-600 mb-1">Instructions</label>
                    <input id="inst-{{ $i }}" name="lignes[{{ $i }}][instructions]" maxlength="255"
                           value="{{ old("lignes.$i.instructions") }}" placeholder="À prendre après le repas"
                           class="w-full min-h-[44px] rounded-lg border border-gray-300 px-2 py-2 text-sm">
                </div>
            </div>
        </div>
        @endfor

        <p class="text-xs text-gray-500 mt-3">
            Un produit absent de l'officine et du dépôt central se prescrit sur la ligne
            ambre : il part sur une ordonnance externe, imprimée sans prix, que le patient
            règle ailleurs. La facturation interne ne le prend pas en compte.
        </p>

        <div class="mt-4">
            <label for="observations" class="block text-sm font-medium text-gray-700 mb-1">Observations</label>
            <textarea id="observations" name="observations" rows="2"
                      class="w-full rounded-lg border border-gray-300 px-4 py-2">{{ old('observations') }}</textarea>
        </div>

        @if($errors->has('allergie'))
        <div class="mt-4 bg-red-50 border-2 border-red-400 rounded-lg px-4 py-3">
            <p class="font-bold text-red-800 text-sm mb-2">Prescription bloquée : allergie connue au produit</p>
            <label class="flex items-start gap-2 text-sm text-red-800">
                <input type="checkbox" name="confirmer_allergie" value="1" required class="mt-0.5 rounded">
                <span>Je confirme prescrire malgré l'allergie connue, en connaissance de cause.</span>
            </label>
        </div>
        @endif

        <div class="flex justify-end mt-4">
            <button type="submit" class="min-h-[44px] px-6 py-2 bg-blue-700 hover:bg-blue-800 text-white font-semibold rounded-lg">
                ✓ Enregistrer l'ordonnance
            </button>
        </div>
    </form>
</div>
@endsection
