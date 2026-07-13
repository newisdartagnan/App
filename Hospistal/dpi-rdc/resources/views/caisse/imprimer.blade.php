@extends('print.layout')
@section('title', 'Facture ' . $facture->numero_facture)
@section('service', 'Caisse — Facturation')
@section('numero')
    <div class="numero">{{ $facture->numero_facture }}</div>
@endsection

@section('contenu')
<h2 class="titre-doc">{{ $facture->statut === 'payee' ? 'Reçu de paiement' : 'Facture' }}</h2>

<div class="bloc">
    <div class="bloc-titre">Patient</div>
    <div class="info-patient">
        <div><strong>Nom :</strong> {{ mb_strtoupper($facture->patient->nom) }} {{ $facture->patient->prenom }}</div>
        <div><strong>Dossier :</strong> {{ $facture->patient->dossier_number }}</div>
        <div><strong>Date facture :</strong> {{ $facture->date_facture->format('d/m/Y H:i') }}</div>
        <div><strong>Prise en charge :</strong> {{ ucfirst($facture->type_prise_en_charge) }}</div>
    </div>
</div>

<div class="bloc">
    <div class="bloc-titre">Détail</div>
    <table class="donnees">
        <thead><tr>
            <th>Désignation</th><th class="num">Qté</th><th class="num">P.U. (CDF)</th><th class="num">Total (CDF)</th>
        </tr></thead>
        <tbody>
            @foreach($facture->lignes as $ligne)
            <tr>
                <td>{{ $ligne->libelle }}</td>
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
        </tbody>
    </table>
</div>

@if($facture->paiements->count() > 0)
<div class="bloc">
    <div class="bloc-titre vert">Paiements</div>
    <table class="donnees">
        <thead><tr><th>Date</th><th>Mode</th><th>Reçu n°</th><th>Caissier</th><th class="num">Montant (CDF)</th></tr></thead>
        <tbody>
            @foreach($facture->paiements as $p)
            <tr>
                <td>{{ $p->date_paiement?->format('d/m/Y H:i') }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $p->mode_paiement)) }}{{ $p->reference_paiement ? ' (' . $p->reference_paiement . ')' : '' }}</td>
                <td>{{ $p->recu_numero }}</td>
                <td>{{ $p->caissier?->nom }}</td>
                <td class="num">{{ number_format($p->montant, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<p style="font-weight: bold; font-size: 13px; margin-top: 8px;">
    Statut :
    <span class="{{ $facture->statut === 'payee' ? 'normal' : 'anormal' }}">
        {{ $facture->statut === 'payee' ? '✓ PAYÉE' : mb_strtoupper(str_replace('_', ' ', $facture->statut)) }}
    </span>
    @if($facture->soldeRestant() > 0)
    — Solde restant : {{ number_format($facture->soldeRestant(), 0, ',', '.') }} CDF
    @endif
</p>

<div class="signature">
    <div class="cadre">Le caissier<div class="ligne">Signature et cachet</div></div>
    <div class="cadre">Le patient<div class="ligne">Signature</div></div>
</div>
@endsection
