@extends('print.layout')
@section('title', 'Feuille d\'intervention — '.$acte->patient->nom_complet)
@section('service', 'Bloc opératoire')
@section('numero')
    <div class="numero">{{ strtoupper(substr($acte->id, 0, 8)) }}</div>
    @if($acte->urgence)<span class="badge-urgent">URGENT</span>@endif
@endsection

@section('contenu')
<h2 class="titre-doc">
    {{ $acte->statut === 'realise' || $acte->statut === 'facture'
        ? 'Compte rendu opératoire'
        : "Feuille d'intervention" }}
</h2>

@include('partials.bandeau-patient-impression', [
    'patient' => $acte->patient,
    'lignes' => array_filter([
        'Service' => $acte->visit?->service?->nom,
        'Demandée par' => $acte->demandeur?->nom_complet ?? $acte->prescripteur?->nom_complet,
    ]),
])

<div class="bloc">
    <div class="bloc-titre">Intervention</div>
    <table class="donnees">
        <tbody>
            <tr>
                <th style="width:28%;">Intervention</th>
                <td>{{ $acte->libelle }}</td>
            </tr>
            <tr>
                <th>Salle / créneau</th>
                <td>
                    {{ $acte->salle?->nom ?? 'non attribuée' }}
                    @if($acte->date_prevue)
                        — prévue le {{ $acte->date_prevue->format('d/m/Y à H:i') }}
                        ({{ $acte->duree_minutes ?: 60 }} min)
                    @endif
                </td>
            </tr>
            <tr>
                <th>Chirurgien</th>
                <td>{{ $acte->operateur?->nom_complet ?? '—' }}
                    @if($acte->instrumentiste) — instrumentiste : {{ $acte->instrumentiste }}@endif</td>
            </tr>
            <tr>
                <th>Anesthésie</th>
                <td>{{ $acte->libelleAnesthesie() }}
                    @if($acte->anesthesiste) — {{ $acte->anesthesiste->nom_complet }}@endif</td>
            </tr>
            <tr>
                <th>Consentement</th>
                <td class="{{ $acte->consentement ? 'normal' : 'anormal' }}">
                    {{ $acte->consentement ? '✓ signé' : 'à recueillir avant l\'entrée en salle' }}
                </td>
            </tr>
            @if($acte->diagnostic_preop)
            <tr><th>Diagnostic préopératoire</th><td>{{ $acte->diagnostic_preop }}</td></tr>
            @endif
        </tbody>
    </table>
</div>

@if($acte->statut === 'realise' || $acte->statut === 'facture')
<div class="bloc">
    <div class="bloc-titre vert">Déroulement</div>
    <table class="donnees">
        <tbody>
            <tr>
                <th style="width:28%;">Entrée en salle</th>
                <td>{{ $acte->heure_entree_salle?->format('d/m/Y à H:i') ?? '—' }}</td>
            </tr>
            <tr>
                <th>Sortie de salle</th>
                <td>{{ $acte->heure_sortie_salle?->format('d/m/Y à H:i') ?? '—' }}
                    @if($acte->dureeReelleMinutes() !== null)
                        — {{ $acte->dureeReelleMinutes() }} minutes
                    @endif
                </td>
            </tr>
            @if($acte->diagnostic_postop)
            <tr><th>Diagnostic postopératoire</th><td>{{ $acte->diagnostic_postop }}</td></tr>
            @endif
            <tr><th>Kits ouverts</th><td>{{ $acte->libelleKits() }}</td></tr>
            <tr>
                <th>Incidents peropératoires</th>
                <td class="{{ $acte->incidents ? 'anormal' : '' }}">{{ $acte->incidents ?: 'Aucun' }}</td>
            </tr>
        </tbody>
    </table>
</div>

@if($acte->compte_rendu)
<div class="bloc">
    <div class="bloc-titre vert">Compte rendu opératoire</div>
    <div class="conclusion" style="white-space:pre-line;">{{ $acte->compte_rendu }}</div>
</div>
@endif
@else
<div class="bloc">
    <div class="bloc-titre">Compte rendu opératoire</div>
    <p style="padding:4px 2px;color:#888;">
        À rédiger à la clôture de l'intervention.
    </p>
</div>
@endif

<div class="signature">
    <div class="cadre">Le chirurgien<div class="ligne">Signature et cachet</div></div>
    <div class="cadre">L'anesthésiste<div class="ligne">Signature</div></div>
</div>
@endsection
