@extends('print.layout')
@section('title', ($examen->domaine === 'imagerie' ? 'CR ' : 'Bulletin ') . ($examen->numero_bon ?? ''))
@section('service', $examen->domaine === 'imagerie' ? "Service d'imagerie médicale" : 'Laboratoire d\'analyses médicales')
@section('numero')
    <div class="numero">{{ $examen->numero_bon ?? '—' }}</div>
    @if($examen->urgence)<span class="badge-urgent">URGENT</span>@endif
@endsection

@section('contenu')
<h2 class="titre-doc">{{ $examen->domaine === 'imagerie' ? 'Compte-rendu d\'examen d\'imagerie' : 'Bulletin de résultats d\'analyses' }}</h2>

@php
    $estImagerie = $examen->domaine === 'imagerie';
    $bonCaisse = \App\Models\BonSortie::where('examen_id', $examen->id)->latest('created_at')->first();
@endphp
@include('partials.bandeau-patient-impression', [
    'patient' => $examen->patient,
    'lignes' => array_filter([
        'Prescripteur' => $examen->prescripteur ? 'Dr '.$examen->prescripteur->nom.' '.$examen->prescripteur->prenom : '—',
        ($estImagerie ? 'Examen réalisé le' : 'Prélèvement le')
            => ($examen->date_prelevement ?? $examen->date_prescription)?->format('d/m/Y H:i'),
        ($estImagerie ? 'Compte rendu du' : 'Résultats du') => $examen->date_resultat?->format('d/m/Y H:i') ?? '—',
        'Bon caisse' => $bonCaisse?->numero,
    ]),
])

<div class="bloc">
    <div class="bloc-titre">{{ $estImagerie ? 'Examens réalisés' : 'Résultats' }}</div>
    <table class="donnees">
        @if($estImagerie)
        {{-- Un examen d'imagerie ne rend pas de valeur mesurée : il n'a donc
             ni unité ni norme, seulement un compte rendu signé plus bas. --}}
        <thead><tr><th>Examen</th><th>Modalité</th><th>Incidence / observation</th></tr></thead>
        <tbody>
            @foreach($examen->resultats->unique('type_examen_id') as $r)
            <tr>
                <td>{{ $r->typeExamen?->libelle ?? $r->parametre }}</td>
                <td>{{ $r->typeExamen?->libelleModalite() ?? '—' }}</td>
                <td>{{ $r->valeur_brute ?: ($r->commentaire ?: '—') }}</td>
            </tr>
            @endforeach
        </tbody>
        @else
        <thead><tr>
            <th>Examen / paramètre</th><th class="num">Résultat</th><th>Unité</th><th>Valeurs de référence</th><th>Interprétation</th>
        </tr></thead>
        <tbody>
            @foreach($examen->resultats as $r)
            @php $anormal = in_array($r->interpretation, ['bas', 'eleve', 'critique', 'positif'], true); @endphp
            <tr>
                <td>{{ $r->typeExamen?->libelle }}@if($r->parametre) — {{ $r->parametre }}@endif</td>
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
        @endif
    </table>
</div>

@if($examen->technique)
<div class="bloc">
    <div class="bloc-titre">Technique utilisée</div>
    <div class="conclusion" style="background:#f5f8fc;border-color:#cfd8e3;">{{ $examen->technique }}</div>
</div>
@endif

@if($examen->conclusion)
<div class="bloc">
    <div class="bloc-titre vert">Conclusion</div>
    <div class="conclusion">{{ $examen->conclusion }}</div>
</div>
@endif

@if($examen->recommandations)
<div class="bloc">
    <div class="bloc-titre" style="background:#0dcaf0;">Recommandations</div>
    <div class="conclusion" style="background:#f0fbfd;border-color:#b6e8f2;">{{ $examen->recommandations }}</div>
</div>
@endif

@if($examen->fichiers->count() > 0)
<div class="bloc">
    <div class="bloc-titre">Documents annexes</div>
    @foreach($examen->fichiers as $i => $f)
    <p style="padding:2px;font-size:11px;"><strong>Document {{ $i + 1 }} :</strong> {{ $f->nom_original }}@if($f->description) — {{ $f->description }}@endif</p>
    @endforeach
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
