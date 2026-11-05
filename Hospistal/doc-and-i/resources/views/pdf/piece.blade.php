<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $piece['type'] }} {{ $piece['numero'] }}</title>
    {{-- Feuille de style dans le document : aucune ressource externe (CSP, dompdf sans accès distant). --}}
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1e293b; }
        .entete { border-bottom: 2px solid #0060c8; padding-bottom: 8px; margin-bottom: 18px; }
        .marque { font-size: 18pt; font-weight: bold; }
        .medi { color: #0060c8; }
        .home { color: #009860; }
        h1 { font-size: 15pt; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th, td { text-align: left; padding: 6px 4px; border-bottom: 1px solid #cbd5e1; }
        .montant { text-align: right; font-weight: bold; }
        .note { margin-top: 24px; font-size: 9pt; color: #64748b; }
    </style>
</head>
<body>
    <div class="entete">
        @if ($embleme)
            <img src="{{ $embleme }}" width="32" height="32" alt="">
        @endif
        <span class="marque"><span class="medi">MEDI</span><span class="home">HOME</span></span>
        <div>La médecine vient à vous.</div>
    </div>

    <h1>{{ $piece['type'] }} n° {{ $piece['numero'] }}</h1>
    <div>Émis le {{ $emise }} (heure de Kinshasa)</div>
    @if ($origine)
        <div>Sur la facture n° {{ $origine }}</div>
    @endif

    <table>
        <tr><th>Patient</th><td>{{ $piece['patient'] }}</td></tr>
        @if ($medecin)
            <tr><th>Médecin</th><td>{{ $medecin }}</td></tr>
        @endif
        <tr><th>Objet</th><td>{{ $piece['libelle'] }}</td></tr>
        <tr><th>Moyen de paiement</th><td>{{ $piece['moyen'] }}</td></tr>
        <tr><th>{{ $piece['type'] === 'Avoir' ? 'Montant remboursé' : 'Montant payé' }}</th><td class="montant">{{ $piece['montant'] }}</td></tr>
    </table>

    <p class="note">Pièce comptable non modifiable : toute correction fait l'objet d'un avoir.</p>
</body>
</html>
