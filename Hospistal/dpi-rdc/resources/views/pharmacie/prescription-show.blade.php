@extends('layouts.app')
@section('title', 'Dispenser ordonnance')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('pharmacie.prescriptions') }}" class="text-blue-700 hover:underline text-sm">← Ordonnances</a>
        <h2 class="text-2xl font-bold text-gray-800">Dispensation — {{ $prescription->patient->nom_complet }}</h2>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4">{{ session('error') }}</div>@endif

    @php
        $aCredit = $prescription->consultation?->visit?->serviACredit();
        $bonValide = \App\Models\BonSortie::where('prescription_id', $prescription->id)
            ->where('statut', 'emis')->where('expire_at', '>', now())->first();
        $dispensable = $prescription->statut !== 'dispensee' && $prescription->statut !== 'annulee'
            && ($aCredit || (in_array($prescription->statut, ['en_attente', 'partiellement_dispensee']) && $bonValide));
    @endphp

    <div class="bg-blue-50 rounded-xl p-4 mb-6 text-sm flex flex-wrap gap-x-6 gap-y-1 justify-between">
        <span>{{ $prescription->patient->nom_complet }} — prescrit par Dr {{ $prescription->prescripteur?->nom }} — {{ $prescription->date_prescription->format('d/m/Y H:i') }}</span>
        <span>Statut : <strong>{{ str_replace('_', ' ', $prescription->statut) }}</strong></span>
        @if($aCredit)
        <span class="text-indigo-700 font-medium">🛏️ Hospitalisé — servi à crédit, règlement avant la sortie</span>
        @elseif($bonValide)
        <span class="text-green-700 font-medium">🎫 Bon {{ $bonValide->numero }} valide</span>
        @endif
    </div>

    @if($prescription->statut === 'dispensee')
    <div class="bg-green-50 border border-green-200 rounded-lg px-4 py-3 mb-4 text-green-800">✅ Ordonnance déjà dispensée.</div>
    @elseif(! $dispensable)
    <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 text-sm text-amber-800">
        ⏳ Paiement au guichet requis avant la dispensation (le bon pharmacie est émis par la caisse).
    </div>
    @endif

    <form method="POST" action="{{ route('pharmacie.dispenser', $prescription) }}" class="bg-white rounded-xl shadow overflow-hidden">
        @csrf
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Médicament</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Posologie</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Stock dispo</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Qté à dispenser</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($prescription->lignes as $ligne)
                @php $stockDispo = (float) ($ligne->medicament->stock?->quantite_disponible ?? 0); @endphp
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $ligne->medicament->denomination_commune }}</p>
                        <p class="text-xs text-gray-400">{{ $ligne->medicament->dosage }} — {{ $ligne->medicament->forme }}</p>
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        {{ $ligne->dose }} — {{ $ligne->frequence }}{{ $ligne->duree_jours ? ' pendant ' . $ligne->duree_jours . ' jours' : '' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <span class="font-bold {{ $stockDispo <= 0 ? 'text-red-600' : 'text-green-600' }}">{{ $stockDispo }}</span>
                        <span class="text-xs text-gray-400">{{ $ligne->medicament->unite_dispensation }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <label for="qte-{{ $ligne->id }}" class="sr-only">Quantité à dispenser</label>
                        <input id="qte-{{ $ligne->id }}" name="quantites[{{ $ligne->id }}]" type="number" step="0.5" min="0"
                            value="{{ $ligne->quantiteRestante() }}" {{ $dispensable ? '' : 'disabled' }}
                            class="w-24 min-h-[44px] rounded-lg border border-gray-300 px-3 py-2 text-right">
                        <span class="text-xs text-gray-400">{{ $ligne->medicament->unite_dispensation }}</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($dispensable)
        <div class="flex justify-end gap-3 px-4 py-4 border-t">
            <a href="{{ route('pharmacie.prescriptions') }}" class="min-h-[44px] px-5 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 inline-flex items-center">Annuler</a>
            <button type="submit" class="min-h-[44px] px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg">
                ✓ Confirmer la dispensation
            </button>
        </div>
        @endif
    </form>
</div>
@endsection
