@extends('print.layout')
@section('title', 'Bulletin du jour — ' . $patient->nom_complet)
@section('service', "Laboratoire d'analyses médicales")
@section('numero')
    <div class="numero">{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</div>
@endsection

@section('contenu')
<h2 class="titre-doc">Bulletin de résultats du jour</h2>

<div class="bloc">
    <div class="bloc-titre">Patient</div>
    <div class="info-patient">
        <div><strong>Nom :</strong> {{ mb_strtoupper($patient->nom) }} {{ $patient->postnom }} {{ $patient->prenom }}</div>
        <div><strong>Dossier :</strong> {{ $patient->dossier_number }}</div>
        <div><strong>Sexe / Âge :</strong> {{ $patient->sexe === 'F' ? 'Féminin' : 'Masculin' }}
            @if($patient->date_naissance) / {{ $patient->date_naissance->age }} ans @endif</div>
        <div><strong>Date :</strong> {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</div>
    </div>
</div>

@forelse($examens as $examen)
<div class="bloc">
    <div class="bloc-titre">
        {{ $examen->domaine === 'imagerie' ? 'Imagerie' : 'Bilan' }} {{ $examen->numero_bon }}
        — {{ $examen->date_prescription->format('H:i') }}
        — {{ $examen->statut === 'valide' ? 'VALIDÉ' : str_replace('_', ' ', $examen->statut) }}
    </div>
    <table class="donnees">
        <thead><tr>
            <th>Examen / paramètre</th><th class="num">Résultat</th><th>Unité</th><th>Références</th><th>Interprétation</th>
        </tr></thead>
        <tbody>
            @foreach($examen->resultats as $r)
            @php $anormal = in_array($r->interpretation, ['bas', 'eleve', 'critique', 'positif']); @endphp
            <tr>
                <td>{{ $r->typeExamen->libelle }}@if($r->parametre) — {{ $r->parametre }}@endif</td>
                <td class="num {{ $anormal ? 'anormal' : 'normal' }}">{{ $r->valeur_brute ?? ($r->valeur_numerique !== null ? $r->valeur_numerique + 0 : 'en attente') }}</td>
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
</div>
@empty
<p style="text-align:center;color:#888;padding:20px;">Aucun bilan prescrit ce jour pour ce patient.</p>
@endforelse

<div class="signature">
    <div class="cadre">Le biologiste<div class="ligne">Signature et cachet</div></div>
</div>
@endsection
