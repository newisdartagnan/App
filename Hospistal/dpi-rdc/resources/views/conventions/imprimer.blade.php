@extends('print.layout')
@section('title', 'Facture ' . $facture->numero)
@section('service', 'Facturation société / convention')

@section('numero')
    <div class="numero">{{ $facture->numero }}</div>
    <div>{{ $facture->created_at->format('d/m/Y') }}</div>
@endsection

@section('contenu')
    <h2 class="titre-doc">Facture de convention</h2>

    <div class="bloc">
        <div class="bloc-titre">Destinataire</div>
        <div class="info-patient">
            <div><strong>Société :</strong> {{ $facture->assurance->nom }}</div>
            <div><strong>Code :</strong> {{ $facture->assurance->code }}</div>
            <div><strong>Période :</strong> {{ $facture->periode_debut->format('d/m/Y') }} au {{ $facture->periode_fin->format('d/m/Y') }}</div>
            <div><strong>Devise :</strong> {{ \App\Models\FactureConvention::DEVISES[$facture->devise] ?? $facture->devise }}</div>
        </div>
    </div>

    <div class="bloc">
        <div class="bloc-titre">Détail des prises en charge</div>
        <table style="width:100%;border-collapse:collapse;font-size:11px;">
            <thead>
                <tr style="background:#e8eef7;">
                    <th style="border:1px solid #999;padding:4px;text-align:left;">Bénéficiaire</th>
                    <th style="border:1px solid #999;padding:4px;text-align:left;">Dossier</th>
                    <th style="border:1px solid #999;padding:4px;text-align:left;">Facture</th>
                    <th style="border:1px solid #999;padding:4px;text-align:left;">Date</th>
                    <th style="border:1px solid #999;padding:4px;text-align:right;">Part convention</th>
                </tr>
            </thead>
            <tbody>
                @foreach($facture->lignes as $ligne)
                <tr>
                    <td style="border:1px solid #999;padding:4px;">{{ $ligne->patient->nom_complet }}</td>
                    <td style="border:1px solid #999;padding:4px;">{{ $ligne->patient->dossier_number }}</td>
                    <td style="border:1px solid #999;padding:4px;">{{ $ligne->facture->numero_facture }}</td>
                    <td style="border:1px solid #999;padding:4px;">{{ $ligne->facture->date_facture->format('d/m/Y') }}</td>
                    <td style="border:1px solid #999;padding:4px;text-align:right;">{{ number_format((float) $ligne->part_assurance, 0, ',', ' ') }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background:#f2f2f2;font-weight:bold;">
                    <td colspan="4" style="border:1px solid #999;padding:5px;text-align:right;">
                        Total à régler ({{ $facture->devise }})
                    </td>
                    <td style="border:1px solid #999;padding:5px;text-align:right;">
                        {{ number_format((float) $facture->montant_total, 2, ',', ' ') }}
                    </td>
                </tr>
            </tfoot>
        </table>
        @if((float) $facture->taux_change != 1.0)
        <p style="font-size:10px;color:#666;margin-top:4px;">
            Montants convertis au taux de 1 {{ $facture->devise }} = {{ number_format((float) $facture->taux_change, 2, ',', ' ') }} CDF.
        </p>
        @endif
    </div>

    <div class="signature">
        <div class="cadre">
            <div class="ligne">Établi par {{ trim(($facture->emisePar?->prenom ?? '') . ' ' . ($facture->emisePar?->nom ?? '')) }}</div>
        </div>
        <div class="cadre">
            <div class="ligne">Cachet et signature</div>
        </div>
    </div>
@endsection
