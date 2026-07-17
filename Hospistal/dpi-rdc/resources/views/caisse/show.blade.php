@extends('layouts.app')
@section('title', 'Facture')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('caisse.index') }}" class="text-blue-700 hover:underline text-sm">← Caisse</a>
            <h2 class="text-2xl font-bold text-gray-800">Facture {{ $facture->numero_facture }}</h2>
        </div>
        <a href="{{ route('caisse.imprimer', $facture) }}" target="_blank"
           class="border border-blue-700 text-blue-700 hover:bg-blue-50 text-sm font-medium px-4 py-2 rounded-lg">
            🖨️ Imprimer {{ $facture->statut === 'payee' ? 'le reçu' : 'la facture' }}
        </a>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>@endif
    @if(session('info'))<div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg px-4 py-3 mb-4">{{ session('info') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4">{{ session('error') }}</div>@endif
    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    {{-- En-tête facture --}}
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-lg font-bold text-gray-800">{{ $facture->numero_facture }}</h3>
                <p class="text-sm text-gray-500">{{ $facture->date_facture->format('d/m/Y à H:i') }}</p>
                <p class="text-sm font-medium mt-1">
                    {{ $facture->patient->nom_complet }}
                    <span class="text-gray-400">— {{ $facture->patient->dossier_number }}</span>
                </p>
            </div>
            <span class="px-3 py-1.5 rounded-full text-sm font-medium
                {{ $facture->statut === 'payee' ? 'bg-green-100 text-green-700' : ($facture->statut === 'emise' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700') }}">
                {{ $facture->statut === 'payee' ? '✅ Payée' : ($facture->statut === 'emise' ? '⏳ En attente' : ucfirst(str_replace('_', ' ', $facture->statut))) }}
            </span>
        </div>
    </div>

    @if($facture->type_prise_en_charge === 'assurance' && $facture->lignesTiersPayant->isEmpty())
    <div class="bg-amber-50 border border-amber-300 rounded-xl px-4 py-3 mb-4 text-sm text-amber-800">
        ⚠️ Patient déclaré « assurance » mais aucune assurance active n'est liée à son dossier — la part patient est de 100 %.
        <a href="{{ route('patients.show', $facture->patient) }}" class="text-blue-700 underline font-medium">Renseigner l'assurance sur la fiche patient →</a>
    </div>
    @endif

    {{-- Lignes --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Désignation</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-600">Qté</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Prix unit.</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Total</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Part assurance</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-600">Part patient</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($facture->lignes as $ligne)
                @php $tp = $facture->lignesTiersPayant->where('ligne_facture_id', $ligne->id)->first(); @endphp
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $ligne->libelle }}</p>
                        @if($tp)
                        <p class="text-xs mt-0.5 {{ !$tp->acte_couvert ? 'text-red-500' : ($tp->plafond_atteint ? 'text-orange-500' : 'text-green-600') }}">
                            @if(!$tp->acte_couvert) ✗ Non couvert par assurance
                            @elseif($tp->plafond_atteint) ⚠️ Plafond atteint
                            @else ✓ Couvert à {{ $tp->taux_applique + 0 }}%
                            @endif
                        </p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">{{ $ligne->quantite + 0 }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format($ligne->prix_unitaire, 0, ',', '.') }}</td>
                    <td class="px-4 py-3 text-right font-medium">{{ number_format($ligne->total_ligne, 0, ',', '.') }}</td>
                    <td class="px-4 py-3 text-right text-green-600">{{ $tp ? number_format($tp->part_assurance, 0, ',', '.') : '—' }}</td>
                    <td class="px-4 py-3 text-right font-bold text-blue-700">
                        {{ $tp ? number_format($tp->part_patient, 0, ',', '.') : number_format($ligne->total_ligne, 0, ',', '.') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 border-t">
                <tr>
                    <td colspan="3" class="px-4 py-3 text-right font-semibold text-gray-700">Total actes</td>
                    <td class="px-4 py-3 text-right font-bold">{{ number_format($facture->total_ttc, 0, ',', '.') }} CDF</td>
                    <td class="px-4 py-3 text-right font-bold text-green-600">{{ number_format($facture->assurance_part, 0, ',', '.') }} CDF</td>
                    <td class="px-4 py-3 text-right font-bold text-blue-700 text-base">{{ number_format($facture->patient_part, 0, ',', '.') }} CDF</td>
                </tr>
                @if($facture->montantPaye() > 0)
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right text-sm text-gray-500">Déjà payé</td>
                    <td class="px-4 py-2 text-right text-sm text-gray-500">{{ number_format($facture->montantPaye(), 0, ',', '.') }} CDF</td>
                </tr>
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right font-bold text-red-600">Solde restant</td>
                    <td class="px-4 py-2 text-right font-bold text-red-600">{{ number_format($facture->soldeRestant(), 0, ',', '.') }} CDF</td>
                </tr>
                @endif
            </tfoot>
        </table>
    </div>

    {{-- Bons de sortie émis --}}
    @foreach($facture->bonsSortie as $bon)
    <div class="bg-green-50 border-2 border-green-400 rounded-xl p-5 mb-4">
        <h4 class="font-bold text-green-800 text-lg">🎫 Bon {{ $bon->type }} {{ $bon->statut === 'utilise' ? '(utilisé)' : 'émis' }}</h4>
        <p class="text-green-700 font-mono text-xl font-bold mt-1">{{ $bon->numero }}</p>
        @if($bon->expire_at)
        <p class="text-green-600 text-sm mt-1">Valable jusqu'au {{ $bon->expire_at->format('d/m/Y à H:i') }}</p>
        @endif
        <p class="text-sm text-green-700 mt-2">
            Le patient se présente {{ $bon->type === 'pharmacie' ? 'à la pharmacie' : ($bon->type === 'imagerie' ? "à l'imagerie" : 'au laboratoire') }} avec ce numéro.
        </p>
    </div>
    @endforeach

    {{-- Encaissement — formulaire classique, aucune dépendance JavaScript --}}
    @if($facture->statut !== 'payee')
    <form method="POST" action="{{ route('caisse.encaisser', $facture) }}" class="bg-white rounded-xl shadow p-6">
        @csrf
        <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Encaissement</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="montant" class="block text-sm font-medium text-gray-700 mb-1">Montant reçu <span class="text-red-500">*</span></label>
                <input id="montant" name="montant" type="number" step="1" min="1"
                    value="{{ old('montant', $facture->soldeRestant() + 0) }}"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
            <div>
                <label for="devise" class="block text-sm font-medium text-gray-700 mb-1">Devise</label>
                <select id="devise" name="devise" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    <option value="CDF">Francs Congolais (CDF)</option>
                    <option value="USD">Dollars USD</option>
                </select>
            </div>
            <div>
                <label for="mode_paiement" class="block text-sm font-medium text-gray-700 mb-1">Mode de paiement</label>
                <select id="mode_paiement" name="mode_paiement" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    <option value="especes">Espèces</option>
                    <option value="mobile_money">Mobile Money (M-Pesa / Airtel)</option>
                    <option value="virement">Virement bancaire</option>
                    <option value="cheque">Chèque</option>
                </select>
            </div>
            <div>
                <label for="reference" class="block text-sm font-medium text-gray-700 mb-1">
                    Référence transaction <span class="text-gray-400 text-xs">(Mobile Money, virement…)</span>
                </label>
                <input id="reference" name="reference" type="text" placeholder="Ex: MP-123456789"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
            </div>
        </div>
        <div class="flex justify-end mt-4">
            <button type="submit"
                class="min-h-[44px] px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition">
                ✓ Valider le paiement
            </button>
        </div>
    </form>
    @endif

    {{-- Historique des paiements --}}
    @if($facture->paiements->count() > 0)
    <div class="bg-white rounded-xl shadow p-4 mt-4">
        <h4 class="font-medium text-gray-700 mb-3 text-sm">Historique des paiements</h4>
        @foreach($facture->paiements as $paiement)
        <div class="flex justify-between items-center text-sm py-1 border-b last:border-0">
            <span class="text-gray-600">
                {{ $paiement->date_paiement?->format('d/m/Y H:i') ?? '—' }} —
                {{ ucfirst(str_replace('_', ' ', $paiement->mode_paiement)) }}
                @if($paiement->reference_paiement)<span class="text-gray-400 text-xs">({{ $paiement->reference_paiement }})</span>@endif
            </span>
            <span class="font-bold text-green-700">{{ number_format($paiement->montant, 0, ',', '.') }} CDF</span>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
