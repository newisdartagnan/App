{{--
    Document PDF remis au médecin prescripteur : résultats d'analyses ou
    compte rendu d'imagerie, avec les pièces jointes.

    Le prescripteur n'a pas à entrer dans le plateau technique pour lire ce
    qu'il a demandé : il ouvre sa notification et reçoit ce document.

    Écrit pour dompdf : styles en ligne, pas de flexbox, pas de ressource
    distante — les images sont lues sur le disque local.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $estImagerie ? 'Compte rendu' : 'Résultats' }} {{ $examen->numero_bon }}</title>
    <style>
        @page { margin: 22mm 14mm 18mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        .entete { border-bottom: 3px solid #0056b3; padding-bottom: 8px; margin-bottom: 12px; }
        .entete h1 { color: #0056b3; font-size: 15px; margin: 0; }
        .entete .sous { color: #555; font-size: 9px; margin: 2px 0 0; }
        .entete .num { float: right; text-align: right; font-size: 9px; color: #444; }
        .entete .num strong { font-family: DejaVu Sans Mono, monospace; font-size: 12px; color: #0056b3; }
        h2.titre { text-align: center; color: #0056b3; font-size: 13px; text-transform: uppercase; margin: 8px 0 12px; }
        .bloc { margin-bottom: 11px; }
        .bloc-titre { background: #0056b3; color: #fff; font-weight: bold; font-size: 9px;
                      text-transform: uppercase; padding: 4px 8px; margin-bottom: 5px; }
        .bloc-titre.vert { background: #198754; }
        table { width: 100%; border-collapse: collapse; }
        table.infos td { padding: 2px 4px; font-size: 9.5px; }
        table.donnees th { background: #eef4fb; color: #0056b3; text-align: left;
                           padding: 5px 6px; border: 1px solid #cfd8e3; font-size: 9px; }
        table.donnees td { padding: 4px 6px; border: 1px solid #dbe2ea; font-size: 9.5px; }
        .num-col { text-align: right; }
        .anormal { color: #c1121f; font-weight: bold; }
        .normal { color: #198754; }
        .encadre { border: 1px solid #b7dfc4; background: #f0f9f2; padding: 7px 9px; }
        .encadre-gris { border: 1px solid #dbe2ea; background: #f7f9fc; padding: 7px 9px; }
        .urgent { background: #c1121f; color: #fff; padding: 1px 6px; font-size: 8px; font-weight: bold; }
        .piece { margin-bottom: 10px; page-break-inside: avoid; }
        .piece img { max-width: 100%; max-height: 190mm; border: 1px solid #ccc; }
        .piece .legende { font-size: 8.5px; color: #555; margin-top: 2px; }
        .pied { margin-top: 18px; border-top: 1px solid #ddd; padding-top: 5px;
                text-align: center; font-size: 8px; color: #888; }
        .signature { margin-top: 26px; text-align: right; font-size: 9px; }
        .signature .ligne { border-top: 1px solid #999; width: 62mm; margin-left: auto;
                            margin-top: 30px; padding-top: 3px; text-align: center; }
    </style>
</head>
<body>

<div class="entete">
    <div class="num">
        <strong>{{ $examen->numero_bon }}</strong><br>
        {{ now()->format('d/m/Y H:i') }}
    </div>
    <h1>{{ mb_strtoupper($etablissement) }}</h1>
    <p class="sous">{{ $estImagerie ? "Service d'imagerie médicale" : "Laboratoire d'analyses médicales" }}</p>
</div>

<h2 class="titre">
    {{ $estImagerie ? "Compte rendu d'examen d'imagerie" : "Bulletin de résultats d'analyses" }}
    @if($examen->urgence)<span class="urgent">URGENT</span>@endif
</h2>

<div class="bloc">
    <div class="bloc-titre">Patient</div>
    <table class="infos">
        <tr>
            <td width="50%"><strong>Nom :</strong> {{ mb_strtoupper($examen->patient->nom) }}
                {{ $examen->patient->postnom }} {{ $examen->patient->prenom }}</td>
            <td><strong>Dossier :</strong> {{ $examen->patient->dossier_number }}</td>
        </tr>
        <tr>
            <td><strong>Sexe / Âge :</strong> {{ $examen->patient->sexe === 'F' ? 'Féminin' : 'Masculin' }}
                @if($examen->patient->date_naissance) / {{ $examen->patient->date_naissance->age }} ans @endif</td>
            <td><strong>Prise en charge :</strong> {{ $examen->patient->libellePriseEnCharge() }}</td>
        </tr>
        <tr>
            <td><strong>Prescripteur :</strong>
                {{ $examen->prescripteur ? 'Dr '.$examen->prescripteur->nom.' '.$examen->prescripteur->prenom : '—' }}</td>
            <td><strong>{{ $estImagerie ? 'Examen réalisé le' : 'Prélèvement le' }} :</strong>
                {{ ($examen->date_prelevement ?? $examen->date_prescription)?->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td><strong>{{ $estImagerie ? 'Compte rendu du' : 'Résultats du' }} :</strong>
                {{ $examen->date_resultat?->format('d/m/Y H:i') ?? '—' }}</td>
            <td><strong>{{ $estImagerie ? 'Radiologue' : 'Laborantin' }} :</strong>
                {{ $examen->laborantin ? $examen->laborantin->nom.' '.$examen->laborantin->prenom : '—' }}</td>
        </tr>
    </table>
</div>

@if($examen->observations_cliniques)
<div class="bloc">
    <div class="bloc-titre">Renseignements cliniques</div>
    <div class="encadre-gris">{{ $examen->observations_cliniques }}</div>
</div>
@endif

<div class="bloc">
    <div class="bloc-titre">{{ $estImagerie ? 'Examens réalisés' : 'Résultats' }}</div>
    <table class="donnees">
        @if($estImagerie)
        {{-- Un examen d'imagerie ne mesure rien : ni unité, ni norme. Ce qui
             fait foi est le compte rendu signé, plus bas. --}}
        <thead><tr><th>Examen</th><th width="26%">Modalité</th><th width="30%">Observation</th></tr></thead>
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
            <th>Examen / paramètre</th><th width="14%" class="num-col">Résultat</th>
            <th width="12%">Unité</th><th width="18%">Références</th><th width="14%">Interprétation</th>
        </tr></thead>
        <tbody>
            @foreach($examen->resultats as $r)
            @php $anormal = in_array($r->interpretation, ['bas', 'eleve', 'critique', 'positif'], true); @endphp
            <tr>
                <td>{{ $r->typeExamen?->libelle }}@if($r->parametre) — {{ $r->parametre }}@endif</td>
                <td class="num-col {{ $anormal ? 'anormal' : 'normal' }}">
                    {{ $r->valeur_brute ?? ($r->valeur_numerique !== null ? $r->valeur_numerique + 0 : '—') }}
                </td>
                <td>{{ $r->unite }}</td>
                <td>@if($r->valeur_reference_min !== null || $r->valeur_reference_max !== null){{ $r->valeur_reference_min + 0 }} — {{ $r->valeur_reference_max + 0 }}@else — @endif</td>
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
    <div class="encadre-gris">{{ $examen->technique }}</div>
</div>
@endif

@if($examen->conclusion)
<div class="bloc">
    <div class="bloc-titre vert">{{ $estImagerie ? 'Compte rendu' : 'Conclusion' }}</div>
    <div class="encadre">{{ $examen->conclusion }}</div>
</div>
@endif

@if($examen->recommandations)
<div class="bloc">
    <div class="bloc-titre">Conduite à tenir</div>
    <div class="encadre-gris">{{ $examen->recommandations }}</div>
</div>
@endif

@if($examen->observations_laborantin)
<div class="bloc">
    <div class="bloc-titre">Observations du plateau technique</div>
    <div class="encadre-gris">{{ $examen->observations_laborantin }}</div>
</div>
@endif

<div class="signature">
    {{ $estImagerie ? 'Le médecin radiologue' : 'Le biologiste' }}
    <div class="ligne">Signature et cachet</div>
</div>

@if($pieces->isNotEmpty())
<div style="page-break-before: always;"></div>
<div class="bloc">
    <div class="bloc-titre">Documents annexes ({{ $pieces->count() }})</div>
</div>
@foreach($pieces as $index => $piece)
<div class="piece">
    <strong>Document {{ $index + 1 }} — {{ $piece['nom'] }}</strong>
    @if($piece['description'])<div class="legende">{{ $piece['description'] }}</div>@endif
    @if($piece['image'])
        <div style="margin-top:4px;"><img src="{{ $piece['image'] }}" alt="{{ $piece['nom'] }}"></div>
    @else
        {{-- Une vidéo ou un fichier DICOM ne s'imprime pas : on l'annonce
             pour que le prescripteur sache qu'il existe et où le demander. --}}
        <div class="encadre-gris" style="margin-top:4px;">
            {{ $piece['mention'] }}
        </div>
    @endif
    <div class="legende">Ajouté le {{ $piece['date'] }}</div>
</div>
@endforeach
@endif

<div class="pied">
    Document généré le {{ now()->format('d/m/Y à H:i') }} — {{ $etablissement }}
    — {{ $estImagerie ? 'Imagerie médicale' : 'Laboratoire d\'analyses médicales' }}
</div>

</body>
</html>
