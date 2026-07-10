<div>
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
                @switch($facture->statut)
                    @case('payee') bg-green-100 text-green-700 @break
                    @case('emise') bg-amber-100 text-amber-700 @break
                    @case('partiellement_payee') bg-blue-100 text-blue-700 @break
                    @default bg-gray-100 text-gray-600
                @endswitch">
                @switch($facture->statut)
                    @case('payee') ✅ Payée @break
                    @case('emise') ⏳ En attente @break
                    @case('partiellement_payee') 🔄 Partielle @break
                    @default {{ $facture->statut }}
                @endswitch
            </span>
        </div>
    </div>

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
                @php
                    $tp = $facture->lignesTiersPayant->where('ligne_facture_id', $ligne->id)->first();
                @endphp
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $ligne->libelle }}</p>
                        @if($tp)
                        <p class="text-xs mt-0.5
                            {{ !$tp->acte_couvert ? 'text-red-500' : ($tp->plafond_atteint ? 'text-orange-500' : 'text-green-600') }}">
                            @if(!$tp->acte_couvert) ✗ Non couvert par assurance
                            @elseif($tp->plafond_atteint) ⚠️ Plafond atteint
                            @else ✓ Couvert à {{ $tp->taux_applique }}%
                            @endif
                        </p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">{{ $ligne->quantite }}</td>
                    <td class="px-4 py-3 text-right">{{ number_format($ligne->prix_unitaire, 0, ',', '.') }}</td>
                    <td class="px-4 py-3 text-right font-medium">{{ number_format($ligne->total_ligne, 0, ',', '.') }}</td>
                    <td class="px-4 py-3 text-right text-green-600">
                        {{ $tp ? number_format($tp->part_assurance, 0, ',', '.') : '—' }}
                    </td>
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

    {{-- Bon de sortie émis --}}
    @if($showBonSortie && $bonSortie)
    <div class="bg-green-50 border-2 border-green-400 rounded-xl p-5 mb-4">
        <div class="flex justify-between items-start">
            <div>
                <h4 class="font-bold text-green-800 text-lg">🎫 Bon de sortie pharmacie émis</h4>
                <p class="text-green-700 font-mono text-xl font-bold mt-1">{{ $bonSortie->numero }}</p>
                <p class="text-green-600 text-sm mt-1">
                    Valable jusqu'au {{ $bonSortie->expire_at->format('d/m/Y à H:i') }}
                </p>
            </div>
            <button onclick="window.print()"
                wire:click="marquerBonImprime"
                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                🖨️ Imprimer
            </button>
        </div>
        <p class="text-sm text-green-700 mt-3">
            Le patient peut se présenter à la pharmacie avec ce numéro pour récupérer ses médicaments.
        </p>
    </div>
    @endif

    {{-- Formulaire paiement --}}
    @if($facture->statut !== 'payee' && !$paiementEffectue)
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Encaissement</h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Montant reçu <span class="text-red-500">*</span>
                </label>
                <input wire:model="montantRecu" type="number" step="100"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500
                    @error('montantRecu') border-red-500 @enderror">
                @error('montantRecu')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Devise</label>
                <select wire:model="devise"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                    <option value="CDF">Francs Congolais (CDF)</option>
                    <option value="USD">Dollars USD</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mode de paiement</label>
                <select wire:model="modePaiement"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
                    <option value="especes">Espèces</option>
                    <option value="mobile_money">Mobile Money (M-Pesa / Airtel)</option>
                    <option value="virement">Virement bancaire</option>
                    <option value="cheque">Chèque</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Référence transaction
                    <span class="text-gray-400 text-xs">(Mobile Money, virement...)</span>
                </label>
                <input wire:model="reference" type="text"
                    placeholder="Ex: MP-123456789"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            </div>
        </div>

        {{-- Monnaie à rendre --}}
        @if($montantRecu > $facture->soldeRestant() && $facture->soldeRestant() > 0)
        <div class="mt-3 bg-blue-50 rounded-lg px-4 py-2 text-sm text-blue-800">
            💰 Monnaie à rendre : <strong>{{ number_format($montantRecu - $facture->soldeRestant(), 0, ',', '.') }} {{ $devise }}</strong>
        </div>
        @endif

        <div class="flex justify-end mt-4">
            <button wire:click="validerPaiement" type="button"
                class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition">
                ✓ Valider le paiement
            </button>
        </div>
    </div>
    @endif

    {{-- Paiements effectués --}}
    @if($facture->paiements->count() > 0)
    <div class="bg-white rounded-xl shadow p-4 mt-4">
        <h4 class="font-medium text-gray-700 mb-3 text-sm">Historique des paiements</h4>
        @foreach($facture->paiements as $paiement)
        <div class="flex justify-between items-center text-sm py-1 border-b last:border-0">
            <span class="text-gray-600">
                {{ $paiement->created_at->format('d/m/Y H:i') }} —
                {{ ucfirst(str_replace('_', ' ', $paiement->mode_paiement)) }}
                @if($paiement->reference_paiement)
                <span class="text-gray-400 text-xs">({{ $paiement->reference_paiement }})</span>
                @endif
            </span>
            <span class="font-bold text-green-700">{{ number_format($paiement->montant, 0, ',', '.') }} CDF</span>
        </div>
        @endforeach
    </div>
    @endif
</div>