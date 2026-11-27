<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><title>Carte {{ $patient->dossier_number }}</title>
<style>
    @page { margin: 0; }
    body { font-family: DejaVu Sans, sans-serif; margin: 0; font-size: 7px; color: #111; }
    .carte { width: 243pt; height: 153pt; position: relative; }
    .bandeau { background: #1e3a8a; color: #fff; padding: 5pt 8pt; }
    .bandeau h1 { font-size: 8.5px; margin: 0; }
    .bandeau p { font-size: 6px; margin: 1pt 0 0; color: #c7d2fe; }
    .photo { position: absolute; left: 8pt; top: 34pt; width: 52pt; height: 64pt; border: 1px solid #94a3b8; background: #f1f5f9; text-align: center; }
    .photo img { width: 52pt; height: 64pt; }
    .photo span { display: block; padding-top: 24pt; color: #94a3b8; font-size: 6px; }
    .infos { position: absolute; left: 68pt; top: 34pt; right: 8pt; }
    .infos .nom { font-size: 9.5px; font-weight: bold; margin-bottom: 3pt; }
    .infos td { padding: 0.5pt 2pt 0.5pt 0; vertical-align: top; }
    .infos td.l { color: #555; }
    .dossier { position: absolute; left: 8pt; right: 8pt; bottom: 7pt; border-top: 1px solid #cbd5e1; padding-top: 3pt; }
    .dossier strong { font-family: DejaVu Sans Mono, monospace; font-size: 11px; color: #1e3a8a; }
</style></head>
<body><div class="carte">
    <div class="bandeau"><h1>{{ mb_strtoupper($etablissement) }}</h1><p>Carte du patient — à présenter à chaque visite</p></div>
    <div class="photo">@if($photo)<img src="{{ $photo }}" alt="">@else<span>PHOTO</span>@endif</div>
    <div class="infos">
        <div class="nom">{{ $patient->nom }} {{ $patient->postnom }} {{ $patient->prenom }}</div>
        <table>
            <tr><td class="l">Sexe</td><td>{{ ['M' => 'Masculin', 'F' => 'Féminin'][$patient->sexe] ?? 'Inconnu' }}</td></tr>
            <tr><td class="l">Né(e) le</td><td>{{ $patient->date_naissance?->format('d/m/Y') ?? '—' }}</td></tr>
            <tr><td class="l">Nationalité</td><td>{{ $patient->nationalite ?? '—' }}</td></tr>
            <tr><td class="l">Groupe sanguin</td><td>{{ $patient->groupe_sanguin ?? '—' }}</td></tr>
            <tr><td class="l">Délivrée le</td><td>{{ now()->format('d/m/Y') }}</td></tr>
        </table>
    </div>
    <div class="dossier">N° dossier : <strong>{{ $patient->dossier_number }}</strong></div>
</div></body></html>
