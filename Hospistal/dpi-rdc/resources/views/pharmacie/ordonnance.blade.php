@extends('print.layout')
@php
    // L'ordonnance externe ne porte jamais de prix : ces produits ne passent
    // ni par notre officine ni par notre caisse. Le patient les achète où il
    // veut, l'hôpital n'a rien à facturer dessus.
    $lignes = $externe ? $prescription->lignesExternes() : $prescription->lignesInternes();
    $patient = $prescription->patient;
@endphp
@section('title', ($externe ? 'Ordonnance externe' : 'Ordonnance').' — '.$patient->nom_complet)
@section('service', $externe ? 'Prescription à retirer hors de l\'établissement' : ($prescription->officine?->nom ?? 'Pharmacie'))
@section('numero')
    <div class="numero">{{ strtoupper(substr($prescription->id, 0, 8)) }}</div>
    <div>{{ $prescription->date_prescription?->format('d/m/Y H:i') }}</div>
@endsection

@section('contenu')
<h2 class="titre-doc">{{ $externe ? 'Ordonnance externe' : 'Ordonnance' }}</h2>

@include('partials.bandeau-patient-impression', [
    'patient' => $patient,
    'lignes' => array_filter([
        'Prescripteur' => $prescription->prescripteur
            ? 'Dr '.$prescription->prescripteur->nom.' '.$prescription->prescripteur->prenom
            : '—',
        'À retirer à' => $externe ? null : $prescription->officine?->nom,
    ]),
])

@if($externe)
<div class="bloc">
    <div class="bloc-titre">Produits à acheter à l'extérieur</div>
    <p style="padding:4px 2px;color:#555;">
        Ces produits ne sont disponibles ni à l'officine ni au dépôt central de
        l'établissement. Ils sont à se procurer dans une pharmacie de ville et
        ne figurent sur aucune facture de l'hôpital.
    </p>
</div>
@endif

<div class="bloc">
    <div class="bloc-titre {{ $externe ? '' : 'vert' }}">Prescription</div>
    <table class="donnees">
        <thead><tr>
            <th>Produit</th>
            <th>Posologie</th>
            <th>Voie</th>
            @if(! $externe)<th class="num">Quantité à délivrer</th>@endif
        </tr></thead>
        <tbody>
            @forelse($lignes as $ligne)
            <tr>
                <td>
                    <strong>{{ $ligne->designation() }}</strong>
                    @if($ligne->instructions)
                    <div style="font-size:10px;color:#555;">{{ $ligne->instructions }}</div>
                    @endif
                </td>
                <td>{{ $ligne->posologie() }}</td>
                <td>{{ $ligne->medicament?->libelleVoie() ?? $ligne->voie_administration }}</td>
                @if(! $externe)
                <td class="num">
                    {{ $ligne->quantiteADelivrer() + 0 }} {{ $ligne->medicament?->unite($ligne->quantiteADelivrer()) }}
                    @if($ligne->estMajoree())
                    <div style="font-size:10px;color:#555;">
                        {{ $ligne->libelleConditionnement() }}
                        (prescrit : {{ $ligne->quantite_totale + 0 }})
                    </div>
                    @endif
                </td>
                @endif
            </tr>
            @empty
            <tr><td colspan="{{ $externe ? 3 : 4 }}" style="text-align:center;color:#888;padding:14px;">
                {{ $externe ? 'Aucun produit externe sur cette ordonnance.' : 'Aucun produit à délivrer sur place.' }}
            </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($prescription->observations)
<div class="bloc">
    <div class="bloc-titre">Observations</div>
    <p style="padding:4px 2px;">{{ $prescription->observations }}</p>
</div>
@endif

@if(! $externe)
<div class="bloc">
    <div class="bloc-titre">Circuit</div>
    <p style="padding:4px 2px;">
        Règlement à la caisse, puis retrait à <strong>{{ $prescription->officine?->nom ?? 'l\'officine' }}</strong>.
        Le dépôt central ne délivre pas aux patients.
    </p>
</div>
@endif

<div class="signature">
    <div class="cadre">Le prescripteur<div class="ligne">Signature et cachet</div></div>
    <div class="cadre">{{ $externe ? 'Le patient' : 'Le pharmacien' }}<div class="ligne">Signature</div></div>
</div>
@endsection
