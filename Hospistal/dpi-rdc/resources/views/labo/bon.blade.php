@extends('print.layout')
@section('title', 'Bon ' . ($examen->numero_bon ?? ''))
@section('service', $examen->domaine === 'imagerie' ? "Service d'imagerie médicale" : 'Laboratoire d\'analyses médicales')
@section('numero')
    <div class="numero">{{ $examen->numero_bon ?? '—' }}</div>
    @if($examen->urgence)<span class="badge-urgent">URGENT</span>@endif
@endsection

@section('contenu')
<h2 class="titre-doc">Bon d'examen {{ $examen->domaine === 'imagerie' ? 'd\'imagerie' : 'de laboratoire' }}</h2>

<div class="bloc">
    <div class="bloc-titre">Patient</div>
    <div class="info-patient">
        <div><strong>Nom :</strong> {{ mb_strtoupper($examen->patient->nom) }} {{ $examen->patient->prenom }}</div>
        <div><strong>Dossier :</strong> {{ $examen->patient->dossier_number }}</div>
        <div><strong>Sexe / Âge :</strong> {{ $examen->patient->sexe === 'F' ? 'Féminin' : 'Masculin' }}
            @if($examen->patient->date_naissance) / {{ $examen->patient->date_naissance->age }} ans @endif</div>
        <div><strong>Prescripteur :</strong> {{ $examen->prescripteur ? 'Dr ' . $examen->prescripteur->nom . ' ' . $examen->prescripteur->prenom : '—' }}</div>
        <div><strong>Date prescription :</strong> {{ $examen->date_prescription?->format('d/m/Y H:i') }}</div>
        <div><strong>Prise en charge :</strong> {{ ucfirst($examen->patient->type_prise_en_charge) }}</div>
    </div>
</div>

@if($examen->observations_cliniques)
<div class="bloc">
    <div class="bloc-titre">Renseignements cliniques</div>
    <p style="padding: 4px 2px;">{{ $examen->observations_cliniques }}</p>
</div>
@endif

<div class="bloc">
    <div class="bloc-titre">Examens demandés</div>
    <table class="donnees">
        <thead><tr><th>Code</th><th>Examen</th><th class="num">Prix (CDF)</th></tr></thead>
        <tbody>
            @foreach($examen->resultats->unique('type_examen_id') as $r)
            <tr>
                <td>{{ $r->typeExamen->code }}</td>
                <td>{{ $r->typeExamen->libelle }}</td>
                <td class="num">{{ number_format($r->typeExamen->prix, 0, ',', '.') }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="2">Total</td>
                <td class="num">{{ number_format($examen->resultats->unique('type_examen_id')->sum(fn ($r) => $r->typeExamen->prix), 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</div>

@if($examen->facture)
<div class="bloc">
    <div class="bloc-titre {{ $examen->facture->statut === 'payee' ? 'vert' : '' }}">Règlement</div>
    <p style="padding: 4px 2px;">
        Facture <strong>{{ $examen->facture->numero_facture }}</strong> —
        {{ $examen->facture->statut === 'payee' ? '✓ PAYÉE — le prélèvement/l\'examen peut être réalisé' : '⏳ EN ATTENTE DE PAIEMENT à la caisse' }}
        @if($examen->visit?->serviACredit()) (patient hospitalisé — servi à crédit) @endif
    </p>
</div>
@endif

<div class="signature">
    <div class="cadre">Le prescripteur<div class="ligne">Signature et cachet</div></div>
    <div class="cadre">{{ $examen->domaine === 'imagerie' ? 'Le technicien' : 'Le préleveur' }}<div class="ligne">Signature</div></div>
</div>
@endsection
