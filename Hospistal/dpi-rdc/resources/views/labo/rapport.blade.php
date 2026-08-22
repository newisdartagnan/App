@extends('layouts.app')
@section('title', 'Rapport journalier')
@section('content')
@php
    // Un radiologue ne fait pas d'analyses et n'est pas un laborantin :
    // le registre parle la langue du plateau qu'il sert.
    $mots = \App\Support\Plateau::mots($domaine);
    $chiffre = \App\Support\Plateau::aDesValeursDeReference($domaine);
    $titreService = $mots['service'];
    $jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
    $mois = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    $d = \Carbon\Carbon::parse($date);
    $dateFr = $jours[$d->dayOfWeek] . ' ' . $d->format('d') . ' ' . $mois[(int) $d->format('n')] . ' ' . $d->format('Y');
    $totalLignes = $registre->flatten(1)->count();
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3 no-print">
        <div class="flex items-center gap-3">
            {{-- Chaque page doit savoir d'où l'on vient : ici, du plateau
                 dont on lit le registre. --}}
            <a href="{{ route($mots['retour']) }}" class="text-blue-700 hover:underline text-sm">← {{ $mots['service_court'] }}</a>
            <h2 class="text-2xl font-bold text-gray-800">Rapport journalier — {{ $mots['service_court'] }}</h2>
        </div>
        <form method="GET" class="flex gap-2 items-center">
            <label for="rapport-date" class="text-sm text-gray-600">Date</label>
            <input id="rapport-date" type="date" name="date" value="{{ $date }}" class="min-h-[40px] rounded-lg border border-gray-300 px-3 py-1">
            <label for="rapport-domaine" class="sr-only">Domaine</label>
            <select id="rapport-domaine" name="domaine" class="min-h-[40px] rounded-lg border border-gray-300 px-2 py-1 text-sm">
                <option value="labo" @selected($domaine === 'labo')>Laboratoire</option>
                <option value="imagerie" @selected($domaine === 'imagerie')>Imagerie</option>
            </select>
            <button type="submit" class="min-h-[40px] px-4 py-1 bg-blue-700 text-white rounded-lg text-sm">Afficher</button>
        </form>
    </div>

    {{-- En-tête du registre officiel --}}
    <div class="text-center border-b-2 border-blue-800 pb-3 mb-5">
        <p class="text-lg font-bold text-blue-900 uppercase">{{ config('app.name', 'DPI-RDC') }}</p>
        <p class="text-sm text-gray-600">{{ $titreService }}</p>
        <p class="text-base font-bold mt-1">{{ strtoupper($mots['registre']) }} — {{ strtoupper($dateFr) }}</p>
    </div>

    {{-- Statistiques du jour --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
        @foreach([
            ['Demandes', $stats['total'], 'text-blue-700'],
            ['Validées', $stats['valides'], 'text-green-700'],
            ['En cours', $stats['en_cours'], 'text-amber-600'],
            ['Urgents', $stats['urgents'], 'text-red-600'],
            ['Critiques', $stats['critiques'], 'text-red-700'],
        ] as [$libelle, $valeur, $couleur])
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold {{ $couleur }}">{{ $valeur }}</p>
            <p class="text-xs text-gray-500">{{ $libelle }}</p>
        </div>
        @endforeach
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-indigo-700">{{ number_format($stats['recettes'], 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Recettes (CDF)</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-4 mb-6 text-sm">
        <span class="text-gray-600">Taux de complétion :</span>
        <span class="font-bold text-green-700">{{ $stats['taux_completion'] }} %</span>
        <div class="h-2 bg-gray-100 rounded-full overflow-hidden mt-2">
            <div class="h-full bg-green-600" style="width: {{ $stats['taux_completion'] }}%"></div>
        </div>
    </div>

    {{-- Registre par unité d'analyse --}}
    @forelse($registre as $categorie => $lignes)
    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b bg-gray-50">
            <h3 class="font-bold text-blue-900 uppercase">{{ $categorie }}</h3>
            <p class="text-xs text-gray-500">{{ $titreService }} &nbsp;|&nbsp; {{ strtoupper($dateFr) }} &nbsp;|&nbsp; {{ count($lignes) }} ligne(s)</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="bg-blue-100 text-blue-900">
                        <th class="border border-gray-300 px-2 py-1.5 text-center w-10">N°</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Nom &amp; post-nom</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-center w-10">Sexe</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-center w-14">Âge</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Examen</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Résultats</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">Dr Prescripteur</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-left">{{ $mots['operateur'] }}</th>
                        @if($chiffre)<th class="border border-gray-300 px-2 py-1.5 text-center w-20">Interp.</th>@endif
                        <th class="border border-gray-300 px-2 py-1.5 text-right w-24">Montant</th>
                        <th class="border border-gray-300 px-2 py-1.5 text-center w-14">Heure</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lignes as $i => $ligne)
                    @php
                        $examen = $ligne['examen'];
                        $interps = $ligne['resultats']->pluck('interpretation')->filter();
                        $pire = $interps->contains('critique') ? 'critique'
                            : ($interps->contains(fn ($v) => in_array($v, ['bas', 'eleve', 'positif'], true)) ? 'anormal'
                            : ($interps->isNotEmpty() ? 'normal' : null));
                    @endphp
                    <tr class="{{ $pire === 'critique' ? 'bg-red-50' : ($pire === 'anormal' ? 'bg-amber-50' : '') }}">
                        <td class="border border-gray-300 px-2 py-1.5 text-center font-bold">{{ str_pad($i + 1, 3, '0', STR_PAD_LEFT) }}</td>
                        <td class="border border-gray-300 px-2 py-1.5">
                            <span class="font-semibold">{{ strtoupper($examen->patient->nom_complet) }}</span>
                            <span class="block text-gray-400 text-[10px]">Doss. {{ $examen->patient->dossier_number }} · Bon {{ $examen->numero_bon }}</span>
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5 text-center">{{ $examen->patient->sexe ?: '—' }}</td>
                        <td class="border border-gray-300 px-2 py-1.5 text-center">{{ $examen->patient->date_naissance?->age ?? '—' }} <span class="text-[9px]">ans</span></td>
                        <td class="border border-gray-300 px-2 py-1.5 font-semibold">
                            {{ $ligne['type']->libelle }}
                            @if($ligne['partiel'])<span class="block text-gray-500 font-normal text-[10px]">{{ $ligne['partiel'] }}</span>@endif
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5">
                            @forelse($ligne['resultats'] as $resultat)
                            <div class="mb-0.5">
                                @if($resultat->parametre)<span class="text-gray-500">{{ $resultat->parametre }} :</span>@endif
                                <span class="font-semibold">{{ $resultat->valeur_numerique !== null ? ($resultat->valeur_numerique + 0) : ($resultat->valeur_brute ?: '—') }}</span>
                                @if($resultat->unite)<span class="text-gray-400">{{ $resultat->unite }}</span>@endif
                                @if($chiffre && ($resultat->valeur_reference_min !== null || $resultat->valeur_reference_max !== null))
                                <span class="text-gray-400 text-[10px]">(réf. {{ $resultat->valeur_reference_min + 0 }}–{{ $resultat->valeur_reference_max + 0 }})</span>
                                @endif
                            </div>
                            @empty
                            <span class="text-gray-400">—</span>
                            @endforelse
                            @if($examen->conclusion)<p class="italic text-gray-500 text-[10px] mt-0.5">{{ $examen->conclusion }}</p>@endif
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5">
                            {{ $examen->prescripteur ? 'Dr ' . trim($examen->prescripteur->prenom . ' ' . $examen->prescripteur->nom) : '—' }}
                        </td>
                        <td class="border border-gray-300 px-2 py-1.5">
                            {{ $examen->laborantin ? trim($examen->laborantin->prenom . ' ' . $examen->laborantin->nom) : '—' }}
                        </td>
                        @if($chiffre)
                        <td class="border border-gray-300 px-2 py-1.5 text-center">
                            @if($pire === 'critique')<span class="bg-red-600 text-white px-1.5 py-0.5 rounded text-[10px] font-bold">Critique</span>
                            @elseif($pire === 'anormal')<span class="bg-amber-400 text-amber-950 px-1.5 py-0.5 rounded text-[10px] font-bold">Anormal</span>
                            @elseif($pire === 'normal')<span class="bg-green-600 text-white px-1.5 py-0.5 rounded text-[10px] font-bold">Normal</span>
                            @else<span class="text-gray-400">—</span>@endif
                        </td>
                        @endif
                        <td class="border border-gray-300 px-2 py-1.5 text-right whitespace-nowrap">{{ number_format($ligne['montant'], 0, ',', ' ') }} CDF</td>
                        <td class="border border-gray-300 px-2 py-1.5 text-center text-gray-500">{{ $ligne['heure']?->format('H:i') ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="11" class="border-t-2 border-gray-800 px-2 py-3 text-right text-[11px] text-gray-600">
                            Total unité : <strong>{{ number_format(collect($lignes)->sum('montant'), 0, ',', ' ') }} CDF</strong>
                            &nbsp;&nbsp;·&nbsp;&nbsp; Technicien responsable : _______________________
                            &nbsp;&nbsp; Signature : _______________
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    @empty
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-8 text-center text-blue-800 text-sm">
        Aucun résultat enregistré le {{ $dateFr }}.
    </div>
    @endforelse

    {{-- Activité par opérateur du plateau --}}
    @if($parLaborantin->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">{{ $mots['activite_operateurs'] }}</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">{{ $mots['operateur'] }}</th>
                    <th class="px-4 py-2 text-center">Bilans traités</th>
                    <th class="px-4 py-2 text-center">Examens analysés</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($parLaborantin as $labo)
                <tr>
                    <td class="px-4 py-2 font-semibold">{{ $labo['nom'] ?: '—' }}</td>
                    <td class="px-4 py-2 text-center">{{ $labo['bilans'] }}</td>
                    <td class="px-4 py-2 text-center">{{ $labo['examens'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <p class="text-right text-xs text-gray-400 border-t pt-2">
        {{ $registre->count() }} unité(s) — {{ $totalLignes }} ligne(s) · Imprimé le {{ now()->format('d/m/Y H:i') }}
    </p>
</div>

<style>
@media print {
    .no-print, nav, header, footer { display: none !important; }
    body { background: #fff; }
    tr { page-break-inside: avoid; }
}
</style>
@endsection
