@extends('print.layout')
@php
    // Le bulletin ne mélange pas les deux plateaux techniques : au laboratoire
    // on rend des valeurs mesurées avec leurs normes, en imagerie un compte
    // rendu signé par le radiologue. Chacun signe le sien.
    $estImagerie = $domaine === 'imagerie';
    $nomPlateau = $estImagerie ? 'Imagerie médicale' : "Laboratoire d'analyses médicales";
    $signataire = $estImagerie ? 'Le médecin radiologue' : 'Le biologiste';
@endphp
@section('title', ($estImagerie ? 'Comptes rendus du jour' : 'Bulletin du jour').' — '.$patient->nom_complet)
@section('service', $nomPlateau)
@section('numero')
    <div class="numero">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</div>
@endsection

@section('contenu')
<h2 class="titre-doc">
    {{ $estImagerie ? 'Comptes rendus d\'imagerie du jour' : 'Bulletin de résultats du jour' }}
</h2>

@include('partials.bandeau-patient-impression', [
    'patient' => $patient,
    'lignes' => ['Date' => \Carbon\Carbon::parse($date)->format('d/m/Y')],
])

@forelse($examens as $examen)
<div class="bloc">
    <div class="bloc-titre {{ $estImagerie ? 'vert' : '' }}">
        {{ $examen->numero_bon }}
        — {{ $examen->date_prescription->format('H:i') }}
        — {{ $examen->statut === 'valide' ? 'VALIDÉ' : mb_strtoupper(str_replace('_', ' ', $examen->statut)) }}
        @if($examen->urgence) <span class="badge-urgent">URGENT</span>@endif
    </div>

    @if($estImagerie)
        {{-- L'imagerie ne porte ni valeur ni norme : on liste les examens
             réalisés, puis la technique et le compte rendu du radiologue. --}}
        <table class="donnees">
            <thead><tr><th>Examen réalisé</th><th>Modalité</th><th>Statut</th></tr></thead>
            <tbody>
                @foreach($examen->resultats->unique('type_examen_id') as $r)
                <tr>
                    <td>{{ $r->typeExamen?->libelle ?? $r->parametre }}</td>
                    <td>{{ $r->typeExamen?->libelleModalite() ?? '—' }}</td>
                    <td class="{{ filled($examen->conclusion) ? 'normal' : '' }}">
                        {{ filled($examen->conclusion) ? 'Compte rendu établi' : 'En attente' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($examen->technique)
        <p style="margin-top:6px;"><strong>Technique :</strong> {{ $examen->technique }}</p>
        @endif
        @if($examen->conclusion)
        <div class="conclusion" style="margin-top:6px;">
            <strong>Compte rendu :</strong> {{ $examen->conclusion }}
        </div>
        @else
        <p style="margin-top:6px;color:#888;">Compte rendu non encore rédigé.</p>
        @endif
        @if($examen->recommandations)
        <p style="margin-top:6px;"><strong>Conduite à tenir :</strong> {{ $examen->recommandations }}</p>
        @endif
    @else
        <table class="donnees">
            <thead><tr>
                <th>Examen / paramètre</th><th class="num">Résultat</th><th>Unité</th>
                <th>Références</th><th>Interprétation</th>
            </tr></thead>
            <tbody>
                @foreach($examen->resultats as $r)
                @php $anormal = in_array($r->interpretation, ['bas', 'eleve', 'critique', 'positif'], true); @endphp
                <tr>
                    <td>{{ $r->typeExamen?->libelle }}@if($r->parametre) — {{ $r->parametre }}@endif</td>
                    <td class="num {{ $anormal ? 'anormal' : 'normal' }}">
                        {{ $r->valeur_brute ?? ($r->valeur_numerique !== null ? $r->valeur_numerique + 0 : 'en attente') }}
                    </td>
                    <td>{{ $r->unite }}</td>
                    <td>@if($r->valeur_reference_min !== null || $r->valeur_reference_max !== null){{ $r->valeur_reference_min + 0 }} — {{ $r->valeur_reference_max + 0 }}@else — @endif</td>
                    <td class="{{ $anormal ? 'anormal' : '' }}">{{ $r->interpretation ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($examen->conclusion)
        <div class="conclusion" style="margin-top:6px;"><strong>Conclusion :</strong> {{ $examen->conclusion }}</div>
        @endif
    @endif
</div>
@empty
<p style="text-align:center;color:#888;padding:20px;">
    {{ $estImagerie
        ? 'Aucun examen d\'imagerie prescrit ce jour pour ce patient.'
        : 'Aucun bilan de laboratoire prescrit ce jour pour ce patient.' }}
</p>
@endforelse

<div class="signature">
    <div class="cadre">{{ $signataire }}<div class="ligne">Signature et cachet</div></div>
</div>
@endsection
