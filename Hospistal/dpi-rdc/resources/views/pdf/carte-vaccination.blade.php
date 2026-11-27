<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Carte de vaccination {{ $patient->dossier_number }}</title>
<style>
    @page { margin: 14mm 12mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
    .entete { border-bottom: 3px solid #0f766e; padding-bottom: 6px; margin-bottom: 10px; }
    .entete h1 { color: #0f766e; font-size: 14px; margin: 0; }
    .entete p { margin: 2px 0 0; font-size: 9px; color: #555; }
    h2 { text-align: center; font-size: 13px; color: #0f766e; text-transform: uppercase; margin: 6px 0 10px; }
    table { width: 100%; border-collapse: collapse; }
    .infos td { padding: 2px 4px; }
    .donnees th { background: #ecfdf5; color: #0f766e; text-align: left; border: 1px solid #a7f3d0; padding: 5px; font-size: 9px; }
    .donnees td { border: 1px solid #d1fae5; padding: 5px; }
    .pied { margin-top: 16px; font-size: 8px; color: #888; text-align: center; }
</style></head>
<body>
<div class="entete"><h1>{{ mb_strtoupper($etablissement) }}</h1><p>Programme élargi de vaccination</p></div>
<h2>Carte de vaccination</h2>
<table class="infos">
    <tr><td><strong>Nom :</strong> {{ $patient->nom }} {{ $patient->postnom }} {{ $patient->prenom }}</td><td><strong>N° dossier :</strong> {{ $patient->dossier_number }}</td></tr>
    <tr><td><strong>Sexe :</strong> {{ ['M' => 'Masculin', 'F' => 'Féminin'][$patient->sexe] ?? 'Inconnu' }}</td><td><strong>Date de naissance :</strong> {{ $patient->date_naissance?->format('d/m/Y') ?? '—' }}</td></tr>
</table>
<br>
<table class="donnees">
    <thead><tr><th>#</th><th>Vaccin</th><th>Date d'administration</th><th>Lot</th><th>Observation</th></tr></thead>
    <tbody>
    @forelse($vaccinations as $i => $v)
        <tr><td>{{ $i + 1 }}</td><td>{{ $v->libelleVaccin() }}</td><td>{{ $v->date_vaccination?->format('d/m/Y') }}</td><td>{{ $v->lot }}</td><td>{{ $v->observation }}</td></tr>
    @empty
        <tr><td colspan="5" style="text-align:center;color:#888">Aucune vaccination enregistrée.</td></tr>
    @endforelse
    </tbody>
</table>
<p class="pied">Document édité le {{ now()->format('d/m/Y à H:i') }} — à conserver et présenter à chaque vaccination.</p>
</body></html>
