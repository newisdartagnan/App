@extends('layouts.app')
@section('title', 'Avoir '.$avoir->numero)
@section('content')
@php $facture = $avoir->facture; $credit = $avoir->credit; @endphp
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5 dpi-sans-impression">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('caisse.show', $facture) }}" class="text-blue-700 hover:underline text-sm">← Facture {{ $facture->numero_facture }}</a>
            @if($peutAnnuler)
            <a href="{{ route('avoirs.index') }}" class="text-blue-700 hover:underline text-sm">Registre des avoirs</a>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <div class="flex flex-wrap justify-between items-start gap-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">{{ $avoir->estRemise() ? $avoir->libelleCourt() : $avoir->libelleType() }}</p>
                <h2 class="text-2xl font-bold text-gray-800">Avoir {{ $avoir->numero }}</h2>
                <p class="text-sm text-gray-500">
                    du {{ $avoir->date_avoir->format('d/m/Y à H:i') }}
                    · établi par {{ $avoir->auteur?->nom_complet ?? '—' }}
                </p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-500">Montant repris</p>
                <p class="text-2xl font-bold text-red-700">− {{ $avoir->formater((float) $avoir->montant_total) }}</p>
            </div>
        </div>

        <dl class="grid gap-3 sm:grid-cols-2 mt-5 text-sm">
            <div>
                <dt class="text-gray-500">Patient</dt>
                <dd class="font-medium">{{ $facture->patient->nom_complet }} <span class="text-gray-400">— {{ $facture->patient->dossier_number }}</span></dd>
            </div>
            <div>
                <dt class="text-gray-500">Facture corrigée</dt>
                <dd class="font-medium">
                    <a href="{{ route('caisse.show', $facture) }}" class="text-blue-700 hover:underline">{{ $facture->numero_facture }}</a>
                    du {{ $facture->date_facture->format('d/m/Y') }} — {{ $facture->formater((float) $facture->total_ttc) }}
                    @if($facture->estAnnulee())<span class="ml-1 px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-700">annulée</span>@endif
                </dd>
            </div>
            <div>
                <dt class="text-gray-500">Nature</dt>
                <dd class="font-medium">{{ $avoir->libelleMotif() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Répartition</dt>
                <dd>part patient {{ $avoir->formater((float) $avoir->montant_patient) }}
                    · part assureur {{ $avoir->formater((float) $avoir->montant_assurance) }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-gray-500">Motif</dt>
                <dd class="whitespace-pre-line">{{ $avoir->motif }}</dd>
            </div>
        </dl>
    </div>

    @if($avoir->lignes->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Ligne reprise</th>
                        <th class="px-4 py-2 text-right">Patient</th>
                        <th class="px-4 py-2 text-right">Assureur</th>
                        <th class="px-4 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($avoir->lignes as $ligne)
                    <tr>
                        <td class="px-4 py-2">{{ $ligne->libelle }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $avoir->formater((float) $ligne->montant_patient) }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $avoir->formater((float) $ligne->montant_assurance) }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap font-medium">{{ $avoir->formater((float) $ligne->montant) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if($avoir->factureConvention)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4 text-sm text-amber-900">
        📄 La facture figurait sur le bordereau <strong>{{ $avoir->factureConvention->numero }}</strong> :
        sa part assureur ({{ $avoir->formater((float) $avoir->montant_assurance) }}) est déduite de ce que l'assureur doit.
    </div>
    @endif

    {{-- Le crédit du patient --}}
    @if($credit)
    <div class="bg-purple-50 border-2 border-purple-300 rounded-xl p-5 mb-4">
        <h3 class="font-bold text-purple-900">💰 Crédit du patient : {{ $credit->montantFormate() }}</h3>
        <p class="text-sm text-purple-800 mt-1">
            Ce que le patient avait déjà versé au-delà de ce qu'il doit encore.
            @if($credit->resteDisponible() > 0)
            Reste disponible : <strong>{{ $credit->resteFormate() }}</strong> — imputable sur sa prochaine
            facture depuis le guichet, ou à lui rendre.
            @else
            Il est entièrement utilisé ou rendu.
            @endif
        </p>

        @if($credit->imputations->isNotEmpty())
        <ul class="text-xs text-purple-800 mt-2 space-y-0.5">
            @foreach($credit->imputations as $imputation)
            <li>{{ $imputation->montantFormate() }} imputés sur
                <a href="{{ route('caisse.show', $imputation->facture_id) }}" class="underline">{{ $imputation->facture?->numero_facture }}</a>
                le {{ $imputation->created_at->format('d/m/Y') }}</li>
            @endforeach
        </ul>
        @endif
        @if((float) $credit->montant_rembourse > 0)
        <p class="text-xs text-purple-800 mt-2">
            Rendu au patient : {{ app(\App\Services\DeviseService::class)->formater((float) $credit->montant_rembourse, $credit->devise) }}
            @if($credit->reference_paiement) (réf. {{ $credit->reference_paiement }}) @endif
        </p>
        @endif

        @if($peutRendre && $credit->resteDisponible() > 0)
        <form method="POST" action="{{ route('avoirs.rembourser', $avoir) }}" class="mt-3 flex flex-wrap items-end gap-3 dpi-sans-impression">
            @csrf
            <div>
                <label for="reference" class="block text-xs text-purple-900 mb-1">Référence (reçu, transaction)</label>
                <input id="reference" name="reference" type="text" maxlength="200"
                       class="min-h-[40px] rounded-lg border border-purple-300 px-3 py-1 text-sm">
            </div>
            <button class="min-h-[40px] bg-purple-700 hover:bg-purple-800 text-white font-semibold px-4 py-2 rounded-lg text-sm">
                Rendre {{ $credit->resteFormate() }} au patient
            </button>
        </form>
        @endif
    </div>
    @endif

    {{-- Refacturation --}}
    @if($avoir->liberer_prestations)
    <div class="bg-white rounded-xl shadow p-5 mb-4">
        <h3 class="font-semibold text-gray-700">Prestations libérées</h3>
        @if($avoir->facturesDeRemplacement->isNotEmpty())
        <p class="text-sm text-gray-600 mt-1">Refacturées le {{ $avoir->refacture_le?->format('d/m/Y à H:i') ?? '—' }} sur :</p>
        <ul class="text-sm mt-1 space-y-0.5">
            @foreach($avoir->facturesDeRemplacement as $remplacante)
            <li><a href="{{ route('caisse.show', $remplacante) }}" class="text-blue-700 hover:underline">{{ $remplacante->numero_facture }}</a>
                — {{ $remplacante->formater((float) $remplacante->total_ttc) }} · {{ $remplacante->libellePriseEnCharge() }}</li>
            @endforeach
        </ul>
        @elseif($avoir->peutEtreRefacture())
        <p class="text-sm text-gray-600 mt-1">
            Les prestations de la facture annulée sont de nouveau facturables. Corrigez d'abord ce qui
            devait l'être — la prise en charge sur la fiche du patient, par exemple — puis refacturez :
            la nouvelle facture appliquera la bonne convention{{ $credit ? ', et le crédit du patient s\'y imputera' : '' }}.
        </p>
        @if($peutAnnuler)
        <form method="POST" action="{{ route('avoirs.refacturer', $avoir) }}" class="mt-3 dpi-sans-impression">
            @csrf
            <button class="min-h-[44px] bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg">
                Refacturer les prestations
            </button>
        </form>
        @endif
        @else
        <p class="text-sm text-gray-600 mt-1">Refacturées le {{ $avoir->refacture_le?->format('d/m/Y à H:i') ?? '—' }}.</p>
        @endif
    </div>
    @endif
</div>
@endsection
