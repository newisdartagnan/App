@extends('print.layout')
@section('title', ($examen->domaine === 'imagerie' ? 'CR ' : 'Bulletin ') . ($examen->numero_bon ?? ''))
@section('service', $examen->domaine === 'imagerie' ? "Service d'imagerie médicale" : 'Laboratoire d\'analyses médicales')
@section('numero')
    <div class="numero">{{ $examen->numero_bon ?? '—' }}</div>
    @if($examen->urgence)<span class="badge-urgent">URGENT</span>@endif
@endsection

@section('contenu')
<h2 class="titre-doc">{{ $examen->domaine === 'imagerie' ? 'Compte-rendu d\'examen d\'imagerie' : 'Bulletin de résultats d\'analyses' }}</h2>

<div class="bloc">
    <div class="bloc-titre">Patient</div>
    <div class="info-patient">
        <div><strong>Nom :</strong> {{ mb_strtoupper($examen->patient->nom) }} {{ $examen->patient->prenom }}</div>
        <div><strong>Dossier :</strong> {{ $examen->patient->dossier_number }}</div>
        <div><strong>Sexe / Âge :</strong> {{ $examen->patient->sexe === 'F' ? 'Féminin' : 'Masculin' }}
            @if($examen->patient->date_naissance) / {{ $examen->patient->date_naissance->age }} ans @endif</div>
        <div><strong>Prescripteur :</strong> {{ $examen->prescripteur ? 'Dr ' . $examen->prescripteur->nom : '—' }}</div>
        <div><strong>Prélèvement / examen :</strong> {{ ($examen->date_prelevement ?? $examen->date_prescription)?->format('d/m/Y H:i') }}</div>
        <div><strong>Résultats du :</strong> {{ $examen->date_resultat?->format('d/m/Y H:i') ?? '—' }}</div>
    </div>
</div>

<div class="bloc">
    <div class="bloc-titre">Résultats</div>
    <table class="donnees">
        <thead><tr>
            <th>Examen / paramètre</th><th class="num">Résultat</th><th>Unité</th><th>Valeurs de référence</th><th>Interprétation</th>
        </tr></thead>
        <tbody>
            @foreach($examen->resultats as $r)
            @php $anormal = in_array($r->interpretation, ['bas', 'eleve', 'critique', 'positif']); @endphp
            <tr>
                <td>{{ $r->typeExamen->libelle }}@if($r->parametre) — {{ $r->parametre }}@endif</td>
                <td class="num {{ $anormal ? 'anormal' : 'normal' }}">
                    {{ $r->valeur_brute ?? ($r->valeur_numerique !== null ? $r->valeur_numerique + 0 : '—') }}
                </td>
                <td>{{ $r->unite }}</td>
                <td>
                    @if($r->valeur_reference_min !== null || $r->valeur_reference_max !== null)
                    {{ $r->valeur_reference_min + 0 }} — {{ $r->valeur_reference_max + 0 }}
                    @else — @endif
                </td>
                <td class="{{ $anormal ? 'anormal' : '' }}">{{ $r->interpretation ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if($examen->conclusion)
<div class="bloc">
    <div class="bloc-titre vert">Conclusion</div>
    <div class="conclusion">{{ $examen->conclusion }}</div>
</div>
@endif

<div class="bloc" style="font-size: 11px; color: #444;">
    {{ $examen->domaine === 'imagerie' ? 'Radiologue / technicien' : 'Biologiste / laborantin' }} :
    {{ $examen->laborantin ? $examen->laborantin->nom . ' ' . $examen->laborantin->prenom : '—' }}
    @if($examen->statut === 'valide') — <strong style="color:#198754;">Bilan validé</strong> @endif
</div>

<div class="signature">
    <div class="cadre">{{ $examen->domaine === 'imagerie' ? 'Le radiologue' : 'Le biologiste' }}<div class="ligne">Signature et cachet</div></div>
</div>
@endsection
