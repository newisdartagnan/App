@extends('print.layout')
@section('title', 'Facture ' . $facture->numero_facture)
@section('service', 'Caisse — Facturation')
@section('numero')
    <div class="numero">{{ $facture->numero_facture }}</div>
@endsection

@section('contenu')
@php $dev = app(\App\Services\DeviseService::class); $devFact = $facture->deviseFacture(); @endphp
<h2 class="titre-doc">{{ $facture->estAnnulee() ? 'Facture annulée' : ($facture->statut === 'payee' ? 'Reçu de paiement' : 'Facture') }}</h2>

@php $annulation = $facture->avoirs->firstWhere('type', 'annulation'); @endphp
@if($annulation)
<p style="border:2px solid #b91c1c;color:#b91c1c;padding:6px 8px;font-weight:bold;margin-bottom:8px;">
    Annulée par l'avoir {{ $annulation->numero }} du {{ $annulation->date_avoir->format('d/m/Y') }} —
    {{ $annulation->libelleMotif() }}. Cette facture n'est plus due.
</p>
@endif
@if($facture->avoirOrigine)
<p style="font-size:11px;margin-bottom:8px;">
    Remplace la facture {{ $facture->avoirOrigine->facture?->numero_facture }},
    annulée par l'avoir {{ $facture->avoirOrigine->numero }}.
</p>
@endif

<div class="bloc">
    <div class="bloc-titre">Patient</div>
    <div class="info-patient">
        <div><strong>Nom :</strong> {{ mb_strtoupper($facture->patient->nom) }} {{ $facture->patient->prenom }}</div>
        <div><strong>Dossier :</strong> {{ $facture->patient->dossier_number }}</div>
        <div><strong>Date facture :</strong> {{ $facture->date_facture->format('d/m/Y H:i') }}</div>
        <div><strong>Prise en charge :</strong> {{ $facture->libellePriseEnCharge() }}</div>
        @if($facture->bonPriseEnCharge)
        <div><strong>Bon de prise en charge :</strong> n° {{ $facture->bonPriseEnCharge->numero }}
            (valable jusqu'au {{ $facture->bonPriseEnCharge->date_expiration->format('d/m/Y') }})</div>
        @endif
        <div><strong>Devise :</strong> {{ $dev->libelle($devFact) }}@if($devFact !== $dev->pivot()) — 1 {{ $devFact }} = {{ number_format($facture->tauxApplique(), 2, ',', ' ') }} CDF @endif</div>
    </div>
</div>

<div class="bloc">
    <div class="bloc-titre">Détail</div>
    <table class="donnees">
        <thead><tr>
            <th>Désignation</th><th class="num">Qté</th>
            <th class="num">P.U. ({{ $devFact }})</th><th class="num">Total ({{ $devFact }})</th>
        </tr></thead>
        <tbody>
            @foreach($facture->lignes as $ligne)
            <tr>
                <td>
                    {{ $ligne->libelle }}
                    @if(!empty($sousExamens[$ligne->reference_id]))
                    <div style="font-size:10px;color:#555;padding-left:10px;">
                        {{ implode(' · ', $sousExamens[$ligne->reference_id]) }}
                    </div>
                    @endif
                </td>
                <td class="num">{{ $ligne->quantite + 0 }}</td>
                <td class="num">{{ number_format($ligne->prix_unitaire, 0, ',', '.') }}</td>
                <td class="num">{{ number_format($ligne->total_ligne, 0, ',', '.') }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="3">Total</td>
                <td class="num">{{ number_format($facture->total_ttc, 0, ',', '.') }}</td>
            </tr>
            @if($facture->assurance_part > 0)
            <tr>
                <td colspan="3">Part assurance</td>
                <td class="num">{{ number_format($facture->assurance_part, 0, ',', '.') }}</td>
            </tr>
            @endif
            <tr class="total-row">
                <td colspan="3">Part patient</td>
                <td class="num">{{ number_format($facture->patient_part, 0, ',', '.') }}</td>
            </tr>
            @foreach($facture->avoirs as $avoir)
            <tr>
                <td colspan="3">{{ $avoir->libelleCourt() }} {{ $avoir->numero }} du {{ $avoir->date_avoir->format('d/m/Y') }}{{ $avoir->estRemise() ? '' : ' — part patient reprise' }}</td>
                <td class="num">− {{ number_format((float) $avoir->montant_patient, 0, ',', '.') }}</td>
            </tr>
            @endforeach
            @if($facture->avoirs->isNotEmpty())
            <tr class="total-row">
                <td colspan="3">Part patient après {{ $facture->avoirs->every(fn ($a) => $a->estRemise()) ? 'remise' : 'avoirs' }}</td>
                <td class="num">{{ number_format($facture->partPatientNette(), 0, ',', '.') }}</td>
            </tr>
            @endif
            @if($facture->acompte_impute > 0)
            <tr>
                <td colspan="3">Acompte déjà versé, imputé sur cette facture</td>
                <td class="num">− {{ number_format($facture->acompte_impute, 2, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="3">Reste à payer au guichet</td>
                <td class="num">{{ number_format($facture->soldeRestant(), 2, ',', '.') }}</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>

@if($facture->bonsSortie->count() > 0)
<div class="bloc">
    <div class="bloc-titre vert">Bons de service émis</div>
    <table class="donnees">
        <thead><tr><th>N° bon</th><th>Service</th><th>Statut</th><th>Validité</th></tr></thead>
        <tbody>
            @foreach($facture->bonsSortie as $bon)
            <tr>
                <td style="font-family:'Courier New',monospace;font-weight:bold;">{{ $bon->numero }}</td>
                <td>{{ ucfirst($bon->type) }}</td>
                <td>{{ $bon->statut === 'utilise' ? 'Utilisé' : 'Émis' }}</td>
                <td>{{ $bon->expire_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <p style="font-size:10px;color:#555;padding:4px 2px;">Le patient présente ce numéro au service concerné (pharmacie, laboratoire, imagerie).</p>
</div>
@endif

@if($facture->paiements->count() > 0)
<div class="bloc">
    <div class="bloc-titre vert">Paiements</div>
    <table class="donnees">
        <thead><tr><th>Date</th><th>Mode</th><th>Reçu n°</th><th>Caissier</th><th class="num">Montant reçu</th></tr></thead>
        <tbody>
            @foreach($facture->paiements as $p)
            <tr>
                <td>{{ $p->date_paiement?->format('d/m/Y H:i') }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $p->mode_paiement)) }}{{ $p->reference_paiement ? ' (' . $p->reference_paiement . ')' : '' }}</td>
                <td>{{ $p->recu_numero }}</td>
                <td>{{ $p->caissier?->nom }}</td>
                <td class="num">{{ $p->montantFormate() }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<p style="font-weight: bold; font-size: 13px; margin-top: 8px;">
    Statut :
    <span class="{{ $facture->statut === 'payee' ? 'normal' : 'anormal' }}">
        {{ $facture->statut === 'payee' ? '✓ PAYÉE' : ($facture->estAnnulee() ? 'ANNULÉE' : mb_strtoupper(str_replace('_', ' ', $facture->statut))) }}
    </span>
    @if($facture->soldeRestant() > 0)
    — Solde restant : {{ $facture->formater($facture->soldeRestant()) }}
    @endif
</p>

<div class="signature">
    <div class="cadre">Le caissier<div class="ligne">Signature et cachet</div></div>
    <div class="cadre">Le patient<div class="ligne">Signature</div></div>
</div>
@endsection
