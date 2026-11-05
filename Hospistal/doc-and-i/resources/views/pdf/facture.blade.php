<!DOCTYPE html>
{{-- PAY-05, PAY-08 : facture ou avoir en PDF. Aucune ressource externe (dompdf sans accès distant). --}}
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $type }} {{ $facture->numero }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1e293b; }
        .marque { font-size: 26px; font-weight: bold; letter-spacing: -0.5px; }
        .medi { color: #0060c8; }
        .home { color: #009860; }
        .devise-marque { color: #475569; font-size: 11px; }
        h1 { font-size: 18px; margin: 28px 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #cbd5e1; vertical-align: top; }
        th { width: 32%; color: #475569; font-weight: normal; }
        .montant { font-size: 16px; font-weight: bold; }
        .pied { margin-top: 36px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <div class="marque"><span class="medi">MEDI</span><span class="home">HOME</span></div>
    <div class="devise-marque">La médecine vient à vous.</div>

    <h1>{{ $type }} n° {{ $facture->numero }}</h1>
    <div>Émis le {{ $emise }} (heure de Kinshasa)</div>

    <table>
        <tr><th>Patient</th><td>{{ $patient }}</td></tr>
        <tr><th>Objet</th><td>{{ $facture->libelle }}</td></tr>
        @if ($facture->origine)
            <tr><th>Facture d'origine</th><td>{{ $facture->origine->numero }}</td></tr>
        @endif
        <tr><th>Moyen de paiement</th><td>{{ $moyen }}</td></tr>
        <tr><th>{{ $facture->estAvoir() ? 'Montant remboursé' : 'Montant payé' }}</th><td class="montant">{{ $montant }}</td></tr>
    </table>

    <p class="pied">
        Document émis par MEDIHOME. Pièce numérotée sans interruption ; elle ne se modifie pas : une correction se fait par un avoir.
    </p>
</body>
</html>
