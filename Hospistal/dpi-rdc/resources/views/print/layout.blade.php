<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('dpi.establishment_name', config('app.name')) }}</title>
    <style>
        /* Modèle d'impression inspiré des documents CSK (Cliniques Spécialisées de Kinshasa) */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 24px auto; max-width: 780px; padding: 0 16px; }
        .en-tete { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0056b3; padding-bottom: 10px; margin-bottom: 14px; }
        .en-tete h1 { color: #0056b3; font-size: 17px; letter-spacing: .5px; }
        .en-tete .sous-titre { color: #555; font-size: 11px; margin-top: 2px; }
        .doc-numero { text-align: right; font-size: 11px; color: #444; }
        .doc-numero .numero { font-family: 'Courier New', monospace; font-size: 15px; font-weight: bold; color: #0056b3; }
        h2.titre-doc { text-align: center; color: #0056b3; font-size: 15px; text-transform: uppercase; margin: 10px 0 14px; }
        .bloc { margin-bottom: 12px; }
        .bloc-titre { background: #0056b3; color: #fff; font-weight: bold; font-size: 11px; text-transform: uppercase; padding: 5px 9px; border-radius: 3px; margin-bottom: 6px; }
        .bloc-titre.vert { background: #198754; }
        .info-patient { background: #f5f5f5; border-radius: 6px; padding: 9px 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 3px 18px; }
        table.donnees { width: 100%; border-collapse: collapse; }
        table.donnees th { background: #eef4fb; color: #0056b3; text-align: left; padding: 6px 8px; border: 1px solid #cfd8e3; font-size: 11px; }
        table.donnees td { padding: 6px 8px; border: 1px solid #dbe2ea; }
        table.donnees .num { text-align: right; font-variant-numeric: tabular-nums; }
        .anormal { color: #c1121f; font-weight: bold; }
        .normal { color: #198754; }
        .total-row td { font-weight: bold; background: #f5f8fc; }
        .signature { display: flex; justify-content: space-between; margin-top: 34px; }
        .signature .cadre { width: 45%; text-align: center; font-size: 11px; color: #333; }
        .signature .ligne { border-top: 1px solid #999; margin-top: 46px; padding-top: 4px; }
        .pied { margin-top: 26px; border-top: 1px solid #ddd; padding-top: 6px; text-align: center; font-size: 9.5px; color: #888; }
        .badge-urgent { display: inline-block; background: #c1121f; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; }
        .conclusion { border: 1px solid #b7dfc4; background: #f0f9f2; border-radius: 6px; padding: 9px 12px; white-space: pre-line; }
        .no-print { text-align: center; margin: 18px 0; }
        .no-print button { background: #0056b3; color: #fff; border: 0; padding: 10px 26px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="window.print()">🖨️ Imprimer</button></div>

    <div class="en-tete">
        <div>
            <h1>{{ mb_strtoupper(config('dpi.establishment_name', 'DPI-RDC')) }}</h1>
            <p class="sous-titre">@yield('service')</p>
        </div>
        <div class="doc-numero">
            @yield('numero')
            <div>{{ now()->format('d/m/Y H:i') }}</div>
        </div>
    </div>

    @yield('contenu')

    <div class="pied">
        Document généré le {{ now()->format('d/m/Y à H:i') }} — {{ config('dpi.establishment_name', 'DPI-RDC') }}
    </div>
</body>
</html>
