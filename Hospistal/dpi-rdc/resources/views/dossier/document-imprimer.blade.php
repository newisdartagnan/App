@extends('print.layout')
@section('title', $document->libelleType())
@section('service', $document->libelleType())

@section('numero')
    <div class="numero">{{ strtoupper(substr($document->id, 0, 8)) }}</div>
    <div>{{ $document->created_at->format('d/m/Y') }}</div>
@endsection

@section('contenu')
    <h2 class="titre-doc">{{ $document->titre }}</h2>

    <div class="bloc">
        <div class="bloc-titre">Patient</div>
        <div class="info-patient">
            <div><strong>Nom :</strong> {{ $document->patient->nom_complet }}</div>
            <div><strong>Dossier :</strong> {{ $document->patient->dossier_number }}</div>
            <div><strong>Sexe :</strong> {{ $document->patient->sexe ?: '—' }}</div>
            <div><strong>Âge :</strong> {{ $document->patient->date_naissance?->age ?? '—' }} ans</div>
            @if($document->patient->date_naissance)
            <div><strong>Né(e) le :</strong> {{ $document->patient->date_naissance->format('d/m/Y') }}</div>
            @endif
            @if($document->visit)
            <div><strong>Épisode :</strong> {{ str_replace('_', ' ', $document->visit->type) }} du {{ $document->visit->date_entree->format('d/m/Y') }}</div>
            @endif
        </div>
    </div>

    <div class="bloc">
        <div class="bloc-titre">{{ $document->libelleType() }}</div>
        <div style="padding: 10px 12px; white-space: pre-line; line-height: 1.6;">{{ $document->contenu }}</div>
    </div>

    @if($document->statut !== 'valide')
    <p style="text-align:center; color:#b45309; font-size:11px; margin-top:10px;">
        Document non validé — brouillon
    </p>
    @endif

    <div class="signature">
        <div class="cadre">
            <div class="ligne">Fait à {{ config('dpi.ville', 'Kinshasa') }}, le {{ ($document->valide_at ?? $document->created_at)->format('d/m/Y') }}</div>
        </div>
        <div class="cadre">
            <div class="ligne">
                Dr {{ trim(($document->auteur?->prenom ?? '') . ' ' . ($document->auteur?->nom ?? '')) }}<br>
                <span style="font-size:10px;color:#666;">Signature et cachet</span>
            </div>
        </div>
    </div>
@endsection
