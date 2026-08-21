@extends('print.layout')
@php
    $estImagerie = $examen->domaine === 'imagerie';
    // Les résultats sont enregistrés paramètre par paramètre : on les regroupe
    // par examen prescrit pour que le bon liste les sous-examens du panel,
    // comme le fait le bulletin. Le prescripteur et le patient voient ainsi
    // exactement ce qui est demandé et ce qui est facturé.
    $panels = $examen->resultats->groupBy('type_examen_id');
@endphp
@section('title', 'Bon '.($examen->numero_bon ?? ''))
@section('service', $estImagerie ? "Service d'imagerie médicale" : "Laboratoire d'analyses médicales")
@section('numero')
    <div class="numero">{{ $examen->numero_bon ?? '—' }}</div>
    @if($examen->urgence)<span class="badge-urgent">URGENT</span>@endif
@endsection

@section('contenu')
<h2 class="titre-doc">Bon d'examen {{ $estImagerie ? "d'imagerie" : 'de laboratoire' }}</h2>

@include('partials.bandeau-patient-impression', [
    'patient' => $examen->patient,
    'lignes' => [
        'Prescripteur' => $examen->prescripteur
            ? 'Dr '.$examen->prescripteur->nom.' '.$examen->prescripteur->prenom
            : '—',
        'Date prescription' => $examen->date_prescription?->format('d/m/Y H:i'),
    ],
])

@if($examen->observations_cliniques)
<div class="bloc">
    <div class="bloc-titre">Renseignements cliniques</div>
    <p style="padding: 4px 2px;">{{ $examen->observations_cliniques }}</p>
</div>
@endif

<div class="bloc">
    <div class="bloc-titre">Examens demandés</div>
    <table class="donnees">
        <thead><tr>
            <th>Code</th>
            <th>{{ $estImagerie ? 'Examen / modalité' : 'Examen et sous-examens' }}</th>
            <th class="num">Prix (CDF)</th>
        </tr></thead>
        <tbody>
            @php $total = 0; @endphp
            @foreach($panels as $resultats)
            @php
                $type = $resultats->first()->typeExamen;
                $parametres = $resultats->map(fn ($r) => $r->parametre)->filter()->unique()->values();
                $totalParametres = count($type?->valeurs_reference['parametres'] ?? []);
                // Un panel prescrit en partie est facturé au prorata : le bon
                // affiche le même prix que la facture, sans quoi le patient
                // découvrirait un écart au guichet.
                $partiel = $totalParametres > 1 && $parametres->count() > 0 && $parametres->count() < $totalParametres;
                $prix = $partiel
                    ? round((float) $type->prix * $parametres->count() / $totalParametres, 2)
                    : (float) ($type?->prix ?? 0);
                $total += $prix;
            @endphp
            <tr>
                <td>{{ $type?->code }}</td>
                <td>
                    <strong>{{ $type?->libelle }}</strong>
                    @if($estImagerie)
                        <div style="font-size:10px;color:#555;">{{ $type?->libelleModalite() }}</div>
                    @elseif($parametres->count() > 1)
                        <div style="font-size:10px;color:#555;">{{ $parametres->implode(' · ') }}</div>
                        @if($partiel)
                        <div style="font-size:10px;color:#a15c00;">
                            {{ $parametres->count() }} sous-examen(s) sur {{ $totalParametres }} — facturé au prorata
                        </div>
                        @endif
                    @endif
                </td>
                <td class="num">{{ number_format($prix, 0, ',', '.') }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="2">Total</td>
                <td class="num">{{ number_format($total, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</div>

@php $bonCaisse = \App\Models\BonSortie::where('examen_id', $examen->id)->latest('created_at')->first(); @endphp
@if($examen->facture)
<div class="bloc">
    <div class="bloc-titre {{ $examen->facture->statut === 'payee' ? 'vert' : '' }}">Règlement</div>
    <p style="padding: 4px 2px;">
        Facture <strong>{{ $examen->facture->numero_facture }}</strong>
        @if($bonCaisse) — Bon caisse <strong style="font-family:'Courier New',monospace;">{{ $bonCaisse->numero }}</strong> @endif
        —
        {{ $examen->facture->statut === 'payee' ? '✓ PAYÉE — le prélèvement/l\'examen peut être réalisé' : '⏳ EN ATTENTE DE PAIEMENT à la caisse' }}
        @if($examen->visit?->serviACredit()) (patient hospitalisé — servi à crédit) @endif
    </p>
</div>
@endif

<div class="signature">
    <div class="cadre">Le prescripteur<div class="ligne">Signature et cachet</div></div>
    <div class="cadre">{{ $estImagerie ? 'Le technicien' : 'Le préleveur' }}<div class="ligne">Signature</div></div>
</div>
@endsection
