@extends('layouts.app')
@section('title', 'Facture')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('caisse.index') }}" class="text-blue-700 hover:underline text-sm">← Caisse</a>
            <h2 class="text-2xl font-bold text-gray-800">Facture {{ $facture->numero_facture }}</h2>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if($peutAnnuler || auth()->user()?->can('rapport.financier.view'))
            <a href="{{ route('caisse.journal', $facture) }}"
               class="border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-medium px-4 py-2 rounded-lg">
                📜 Journal
            </a>
            @endif
            @if($peutAnnuler && ! $facture->estAnnulee())
            <a href="{{ route('avoirs.create', $facture) }}"
               class="border border-red-700 text-red-700 hover:bg-red-50 text-sm font-medium px-4 py-2 rounded-lg">
                Établir un avoir
            </a>
            @endif
            <a href="{{ route('caisse.imprimer', $facture) }}" target="_blank"
               class="border border-blue-700 text-blue-700 hover:bg-blue-50 text-sm font-medium px-4 py-2 rounded-lg">
                🖨️ Imprimer {{ $facture->statut === 'payee' ? 'le reçu' : 'la facture' }}
            </a>
        </div>
    </div>

@php $dev = app(\App\Services\DeviseService::class); @endphp
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
                <p class="text-xs text-gray-500 mt-1">
                    Prise en charge : <span class="font-semibold text-gray-700">{{ $facture->libellePriseEnCharge() }}</span>
                    · Facture libellée en <span class="font-semibold text-gray-700">{{ $dev->libelle($facture->deviseFacture()) }}</span>
                </p>
            </div>
            @php
                [$classeStatut, $libelleStatut] = match ($facture->statut) {
                    'payee' => ['bg-green-100 text-green-700', '✅ Payée'],
                    'emise' => ['bg-amber-100 text-amber-700', '⏳ En attente'],
                    'annulee' => ['bg-red-100 text-red-700', '✕ Annulée'],
                    default => ['bg-blue-100 text-blue-700', ucfirst(str_replace('_', ' ', $facture->statut))],
                };
            @endphp
            <span class="px-3 py-1.5 rounded-full text-sm font-medium {{ $classeStatut }}">{{ $libelleStatut }}</span>
        </div>
    </div>

    @php $annulation = $facture->avoirs->firstWhere('type', 'annulation'); @endphp
    @if($annulation)
    <div class="bg-red-50 border-2 border-red-300 rounded-xl px-5 py-4 mb-4 text-sm text-red-900">
        <p class="font-bold">Facture annulée par l'avoir
            <a href="{{ route('avoirs.show', $annulation) }}" class="underline">{{ $annulation->numero }}</a>
            le {{ $annulation->date_avoir->format('d/m/Y') }}.</p>
        <p class="mt-1">{{ $annulation->libelleMotif() }} — {{ $annulation->motif }}</p>
        <p class="mt-1 text-xs">Plus rien n'y est dû ni encaissé.
            @if($annulation->credit)Le patient dispose d'un crédit de {{ $annulation->credit->montantFormate() }}.@endif
        </p>
    </div>
    @endif

    @if($facture->avoirOrigine)
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-4 text-sm text-blue-900">
        Cette facture remplace la facture
        <a href="{{ route('caisse.show', $facture->avoirOrigine->facture_id) }}" class="underline font-medium">{{ $facture->avoirOrigine->facture?->numero_facture }}</a>,
        annulée par l'avoir
        <a href="{{ route('avoirs.show', $facture->avoirOrigine) }}" class="underline">{{ $facture->avoirOrigine->numero }}</a>.
    </div>
    @endif

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
                    <td class="px-4 py-3 text-right">{{ $facture->formater((float) $ligne->prix_unitaire) }}</td>
                    <td class="px-4 py-3 text-right font-medium">{{ $facture->formater((float) $ligne->total_ligne) }}</td>
                    <td class="px-4 py-3 text-right text-green-600">{{ $tp ? $facture->formater((float) $tp->part_assurance) : '—' }}</td>
                    <td class="px-4 py-3 text-right font-bold text-blue-700">
                        {{ $facture->formater((float) ($tp->part_patient ?? $ligne->total_ligne)) }}
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 border-t">
                <tr>
                    <td colspan="3" class="px-4 py-3 text-right font-semibold text-gray-700">Total actes</td>
                    <td class="px-4 py-3 text-right font-bold">{{ $facture->formater((float) $facture->total_ttc) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-green-600">{{ $facture->formater((float) $facture->assurance_part) }}</td>
                    <td class="px-4 py-3 text-right font-bold text-blue-700 text-base">{{ $facture->formater((float) $facture->patient_part) }}</td>
                </tr>
                @foreach($facture->avoirs as $avoir)
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right text-sm text-red-700">
                        Avoir <a href="{{ route('avoirs.show', $avoir) }}" class="underline">{{ $avoir->numero }}</a>
                        du {{ $avoir->date_avoir->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-2 text-right text-sm text-red-700">− {{ $facture->formater((float) $avoir->montant_total) }}</td>
                    <td class="px-4 py-2 text-right text-sm text-red-700">− {{ $facture->formater((float) $avoir->montant_assurance) }}</td>
                    <td class="px-4 py-2 text-right text-sm text-red-700">− {{ $facture->formater((float) $avoir->montant_patient) }}</td>
                </tr>
                @endforeach
                @if($facture->avoirs->isNotEmpty())
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right font-semibold text-gray-700">Net après avoirs</td>
                    <td class="px-4 py-2 text-right font-bold">{{ $facture->formater($facture->totalNet()) }}</td>
                    <td class="px-4 py-2 text-right font-bold text-green-600">{{ $facture->formater($facture->partAssuranceNette()) }}</td>
                    <td class="px-4 py-2 text-right font-bold text-blue-700">{{ $facture->formater($facture->partPatientNette()) }}</td>
                </tr>
                @endif
                @if($facture->acompte_impute > 0)
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right text-sm text-purple-700">Acompte imputé</td>
                    <td class="px-4 py-2 text-right text-sm font-semibold text-purple-700">
                        − {{ $facture->formater((float) $facture->acompte_impute) }}
                    </td>
                </tr>
                @endif
                @if($facture->montantPaye() > 0 || $facture->acompte_impute > 0)
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right text-sm text-gray-500">Déjà payé au guichet</td>
                    <td class="px-4 py-2 text-right text-sm text-gray-500">{{ $facture->formater($facture->montantPaye()) }}</td>
                </tr>
                @php $credits = (float) $facture->avoirs->sum('credit_patient'); @endphp
                @if($credits > 0)
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right text-sm text-purple-700">Rendu au patient en crédit</td>
                    <td class="px-4 py-2 text-right text-sm text-purple-700">{{ $facture->formater($credits) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="5" class="px-4 py-2 text-right font-bold text-red-600">Solde restant</td>
                    <td class="px-4 py-2 text-right font-bold text-red-600">{{ $facture->formater($facture->soldeRestant()) }}</td>
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

    {{-- Acompte du patient mobilisable sur cette facture --}}
    @if(! in_array($facture->statut, ['payee', 'annulee'], true) && $acompteDisponible > 0)
    <div class="bg-purple-50 border-2 border-purple-300 rounded-xl p-5 mb-4">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h4 class="font-bold text-purple-900">
                    💰 Ce patient dispose d'un acompte :
                    @foreach($acompteParDevise as $code => $montant){{ $loop->first ? '' : ' + ' }}{{ $dev->formater((float) $montant, $code) }}@endforeach
                </h4>
                <p class="text-sm text-purple-800 mt-1">
                    Avance versée lors d'un passage précédent, soit
                    <strong>{{ $dev->formater($acompteDisponible, $dev->pivot()) }}</strong> de contre-valeur.
                    Elle peut régler tout ou partie de cette facture, quelle que soit la devise.
                </p>
            </div>
            <form method="POST" action="{{ route('caisse.acompte', $facture) }}">
                @csrf
                <button class="bg-purple-700 hover:bg-purple-800 text-white font-semibold px-5 py-2.5 rounded-lg">
                    Utiliser l'acompte
                </button>
            </form>
        </div>
    </div>
    @endif

    {{-- Acomptes déjà mobilisés sur cette facture --}}
    @if($facture->imputations->isNotEmpty())
    <div class="bg-white rounded-xl shadow p-4 mb-4">
        <p class="text-sm font-semibold text-gray-700 mb-2">Acomptes imputés sur cette facture</p>
        <ul class="text-xs text-gray-600 space-y-1">
            @foreach($facture->imputations as $imputation)
            <li>
                {{ $imputation->montantFormate() }} portés sur la facture
                @if($imputation->acompte && $imputation->acompte->devise !== $imputation->devise)
                <span class="text-gray-400">(prélevés : {{ $imputation->preleveFormate() }})</span>
                @endif
                — acompte du {{ $imputation->acompte?->created_at->format('d/m/Y') }}
                ({{ $imputation->acompte?->libelleType() }})
                · imputé le {{ $imputation->created_at->format('d/m/Y à H:i') }}
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Encaissement — formulaire classique, aucune dépendance JavaScript --}}
    @if(! in_array($facture->statut, ['payee', 'annulee'], true))
    <form method="POST" action="{{ route('caisse.encaisser', $facture) }}" class="bg-white rounded-xl shadow p-6">
        @csrf
        <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Encaissement</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="montant" class="block text-sm font-medium text-gray-700 mb-1">Montant reçu <span class="text-red-500">*</span></label>
                <input id="montant" name="montant" type="number" step="0.01" min="0.01"
                    value="{{ old('montant', $facture->soldeRestant() + 0) }}"
                    class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                <p class="text-xs text-gray-500 mt-1">
                    Reste dû : {{ $facture->formater($facture->soldeRestant()) }}.
                    Payé dans une autre devise, le montant est converti au taux du jour.
                </p>
            </div>
            <div>
                <label for="devise" class="block text-sm font-medium text-gray-700 mb-1">Devise</label>
                <select id="devise" name="devise" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
                    @foreach($dev->referentiel() as $code => $definition)
                    <option value="{{ $code }}" @selected(old('devise', $facture->deviseFacture()) === $code)>
                        {{ $definition['libelle'] }} ({{ $code }}){{ $code === $dev->pivot() ? '' : ' — 1 '.$code.' = '.number_format($definition['taux_cdf'], 2, ',', ' ').' CDF' }}
                    </option>
                    @endforeach
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
            <span class="font-bold text-green-700">{{ $paiement->montantFormate() }}</span>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection
