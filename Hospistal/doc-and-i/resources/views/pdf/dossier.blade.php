<!DOCTYPE html>
{{-- DOS-04 : dossier médical du patient en PDF. Aucune ressource externe (dompdf sans accès distant). --}}
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Dossier médical</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        .marque { font-size: 22px; font-weight: bold; }
        .medi { color: #0060c8; }
        .home { color: #009860; }
        h1 { font-size: 17px; margin: 18px 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        .allergies { border: 2px solid #b91c1c; color: #7f1d1d; padding: 6px 8px; margin-top: 10px; }
        ul { margin: 2px 0 6px 16px; padding: 0; }
        .vide { color: #64748b; }
        .evenement { margin-bottom: 8px; }
        .date { color: #475569; }
        .pied { margin-top: 24px; color: #64748b; font-size: 9px; }
    </style>
</head>
<body>
    <div class="marque"><span class="medi">MEDI</span><span class="home">HOME</span></div>
    <h1>Dossier médical de {{ trim($patient->prenom.' '.mb_strtoupper((string) $patient->nom)) }}</h1>
    <div>
        @if ($patient->date_naissance) Né(e) le {{ $patient->date_naissance->format('d/m/Y') }} · @endif
        Émis le {{ $emis }} (heure de Kinshasa)
    </div>

    @php($parCategorie = collect($etat['elements'])->keyBy('categorie'))
    <div class="allergies">
        <strong>Allergies :</strong>
        @forelse ($parCategorie['allergie']['elements'] as $e)
            {{ $e['libelle'] }}@if ($e['precision']) ({{ $e['precision'] }})@endif{{ $loop->last ? '' : ' ; ' }}
        @empty
            aucune déclarée.
        @endforelse
    </div>

    <p>Groupe sanguin : <strong>{{ $etat['groupeSanguin'] ?? 'inconnu' }}</strong></p>

    @foreach (['traitement', 'medical', 'chirurgical', 'familial'] as $categorie)
        <h2>{{ $parCategorie[$categorie]['libelle'] }}</h2>
        @if (count($parCategorie[$categorie]['elements']))
            <ul>
                @foreach ($parCategorie[$categorie]['elements'] as $e)
                    <li>{{ $e['libelle'] }}@if ($e['precision']) — {{ $e['precision'] }}@endif</li>
                @endforeach
            </ul>
        @else
            <p class="vide">Rien de déclaré.</p>
        @endif
    @endforeach

    <h2>Dernières constantes</h2>
    @if (count($etat['constantes']))
        <ul>
            @foreach ($etat['constantes'] as $c)
                <li>{{ $c['libelle'] }} : {{ $c['valeur'] }} <span class="date">({{ $c['quand'] }})</span></li>
            @endforeach
        </ul>
    @else
        <p class="vide">Aucune mesure.</p>
    @endif

    <h2>Historique</h2>
    @forelse ($historique as $e)
        <div class="evenement">
            <span class="date">{{ $e['date'] }}</span> — <strong>{{ $e['titre'] }}</strong>
            @if (count($e['details']))
                <ul>@foreach ($e['details'] as $d)<li>{{ $d }}</li>@endforeach</ul>
            @endif
            @foreach ($e['avenants'] ?? [] as $a)
                <div>Avenant du {{ $a['date'] }} ({{ $a['auteur'] }}) : {{ $a['contenu'] }}</div>
            @endforeach
        </div>
    @empty
        <p class="vide">Rien pour l'instant.</p>
    @endforelse

    <p class="pied">Document personnel et confidentiel, émis à la demande du patient par MEDIHOME.</p>
</body>
</html>
