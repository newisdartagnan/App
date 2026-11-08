<!DOCTYPE html>
{{-- PRE-01, PRE-03 : ordonnance en PDF. Aucune ressource externe (dompdf sans accès distant) ; QR code en SVG intégré. --}}
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Ordonnance {{ $ordonnance->numero }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        .marque { font-size: 24px; font-weight: bold; letter-spacing: -0.5px; }
        .medi { color: #0060c8; }
        .home { color: #009860; }
        .devise-marque { color: #475569; font-size: 10px; }
        .entete { width: 100%; border-collapse: collapse; }
        .entete td { vertical-align: top; padding: 0; }
        h1 { font-size: 17px; margin: 18px 0 2px; }
        .bloc { margin-top: 14px; }
        .bloc th { text-align: left; color: #475569; font-weight: normal; width: 28%; padding: 3px 0; }
        .bloc td { padding: 3px 0; }
        .ligne { border-top: 1px solid #cbd5e1; padding: 8px 0; }
        .medicament { font-size: 13px; font-weight: bold; }
        .detail { color: #334155; }
        .consignes { margin-top: 12px; padding: 8px; border: 1px solid #cbd5e1; }
        .qr { text-align: right; }
        .pied { margin-top: 24px; color: #64748b; font-size: 9px; }
    </style>
</head>
<body>
    <table class="entete">
        <tr>
            <td>
                <div class="marque"><span class="medi">MEDI</span><span class="home">HOME</span></div>
                <div class="devise-marque">La médecine vient à vous.</div>
                <h1>Ordonnance n° {{ $ordonnance->numero }}</h1>
                <div>Émise le {{ $date }} à {{ $heure }} (heure de Kinshasa)</div>
            </td>
            <td class="qr">
                <img src="{{ $qr }}" width="120" height="120" alt="QR code de vérification">
            </td>
        </tr>
    </table>

    <table class="bloc">
        <tr><th>Médecin</th><td>{{ $medecin }}</td></tr>
        <tr><th>N° d'Ordre</th><td>{{ $numeroOrdre }}</td></tr>
        <tr><th>Patient</th><td>{{ $patient }}@if ($age !== null), {{ $age }} ans @endif</td></tr>
    </table>

    <div class="bloc">
        @foreach ($ordonnance->lignes as $ligne)
            <div class="ligne">
                <div class="medicament">{{ $ligne->rang }}. {{ $ligne->medicament }} — {{ $ligne->dosage }}</div>
                <div class="detail">Voie : {{ $ligne->voie }}</div>
                <div class="detail">Posologie : {{ $ligne->posologie }}</div>
                <div class="detail">Durée : {{ $ligne->duree }}</div>
                @if ($ligne->consignes)
                    <div class="detail">Consignes : {{ $ligne->consignes }}</div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($ordonnance->consignes)
        <div class="consignes"><strong>Consignes générales</strong><br>{!! nl2br(e($ordonnance->consignes)) !!}</div>
    @endif

    <p class="pied">
        Ordonnance signée électroniquement par le médecin, numérotée et non modifiable : une correction se fait par une nouvelle ordonnance.
        Pharmacien : scannez le QR code, ou ouvrez {{ $adresse }}, pour vérifier son authenticité.
    </p>
</body>
</html>
