@extends('layouts.app')
@section('title', 'Statistiques')
@section('content')
@php
    // Barre horizontale proportionnelle : lisible sans aucune librairie.
    $barre = function ($valeur, $max, $couleur = 'bg-blue-600') {
        $largeur = $max > 0 ? max(2, round($valeur / $max * 100)) : 0;
        return '<div class="h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full ' . $couleur . '" style="width:' . $largeur . '%"></div></div>';
    };
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-3 no-print">
        <h2 class="text-2xl font-bold text-gray-800">📊 Statistiques de pilotage</h2>
    @if(auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'infirmier_chef', 'agent_admin']))
    <a href="{{ route('snis.index') }}"
       class="text-sm text-blue-700 hover:underline">📋 Rapport mensuel SNIS →</a>
    @endif
    @if(auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'infirmier_chef']))
    <a href="{{ route('parcours.attente') }}"
       class="ml-3 text-sm text-blue-700 hover:underline">⏳ L'attente à l'hôpital →</a>
    @endif
        <form method="GET" class="flex gap-2 items-center">
            <input type="hidden" name="onglet" value="{{ $onglet }}">
            <label for="debut" class="text-sm text-gray-600">Du</label>
            <input id="debut" type="date" name="debut" value="{{ $debut }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <label for="fin" class="text-sm text-gray-600">au</label>
            <input id="fin" type="date" name="fin" value="{{ $fin }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <button class="px-4 py-1.5 bg-blue-700 text-white rounded-lg text-sm">Afficher</button>
        </form>
    </div>

    {{-- Chiffres clés --}}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">
        @foreach([
            ['Admissions', $synthese['admissions'], 'text-blue-700', ''],
            ['Ambulatoires', $synthese['ambulatoires'], 'text-gray-700', ''],
            ['Urgences', $synthese['urgences'], 'text-red-600', ''],
            ['Hospitalisations', $synthese['hospitalisations'], 'text-indigo-700', ''],
            ['Taux d\'occupation', $synthese['taux_occupation'], 'text-amber-600', '%'],
            ['Durée moyenne', $synthese['duree_sejour_moyenne'], 'text-purple-700', ' j'],
            ['Admissions / jour', $synthese['admissions_par_jour'], 'text-green-700', ''],
        ] as [$libelle, $valeur, $couleur, $unite])
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold {{ $couleur }}">{{ $valeur }}{{ $unite }}</p>
            <p class="text-xs text-gray-500">{{ $libelle }}</p>
        </div>
        @endforeach
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-green-700">{{ number_format($synthese['recettes'], 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Recettes encaissées (CDF)</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-amber-700">{{ number_format($synthese['impayes'], 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Factures impayées (CDF)</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-purple-700">{{ $synthese['examens'] }}</p>
            <p class="text-xs text-gray-500">Examens prescrits</p>
        </div>
    </div>

    {{-- Onglets --}}
    <div class="flex flex-wrap gap-1 border-b border-gray-300 mb-4 text-sm no-print">
        @foreach([
            'activite' => 'Activité',
            'occupation' => 'Occupation des lits',
            'labo' => 'Laboratoire',
            'imagerie' => 'Imagerie',
            'pharmacie' => 'Pharmacie',
        ] as $cle => $libelle)
        <a href="{{ route('statistiques.index', ['onglet' => $cle, 'debut' => $debut, 'fin' => $fin]) }}"
           class="px-4 py-2 rounded-t-lg border border-b-0 {{ $onglet === $cle ? 'bg-white font-semibold text-blue-800 border-gray-300' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100' }}">
            {{ $libelle }}
        </a>
        @endforeach
    </div>

    @if($onglet === 'activite')
        {{-- Courbe des admissions --}}
        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <h3 class="font-semibold text-gray-700 mb-3">Admissions par jour</h3>
            @php $maxJour = $parJour->max() ?: 1; @endphp
            <div class="flex items-end gap-0.5 h-40 overflow-x-auto">
                @foreach($parJour as $jour => $nombre)
                <div class="flex-1 min-w-3 flex flex-col justify-end items-center group" title="{{ \Carbon\Carbon::parse($jour)->format('d/m/Y') }} : {{ $nombre }} admission(s)">
                    <span class="text-[9px] text-gray-500 mb-0.5">{{ $nombre > 0 ? $nombre : '' }}</span>
                    <div class="w-full bg-blue-600 rounded-t hover:bg-blue-800" style="height: {{ max(2, round($nombre / $maxJour * 130)) }}px"></div>
                </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-400 mt-2 text-center">
                {{ \Carbon\Carbon::parse($debut)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($fin)->format('d/m/Y') }}
            </p>
        </div>

        {{-- Répartitions --}}
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach([
                'type' => 'Par type de passage',
                'sexe' => 'Par sexe',
                'age' => 'Par tranche d\'âge',
                'service' => 'Par service',
                'specialite' => 'Par spécialité',
                'prise_en_charge' => 'Par prise en charge',
                'prestataire' => 'Par prestataire',
                'heure_admission' => 'Par heure d\'arrivée',
            ] as $cle => $titre)
            @php $donnees = $repartitions[$cle] ?? collect(); $max = $donnees->max() ?: 1; @endphp
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-4 py-3 border-b font-semibold text-gray-700 text-sm">{{ $titre }}</div>
                <div class="p-4 space-y-2 max-h-64 overflow-y-auto">
                    @forelse($donnees as $libelle => $nombre)
                    <div>
                        <div class="flex justify-between text-xs mb-0.5">
                            <span class="text-gray-700 capitalize">{{ str_replace('_', ' ', $libelle) }}</span>
                            <span class="font-semibold text-gray-900">{{ $nombre }}</span>
                        </div>
                        {!! $barre($nombre, $max) !!}
                    </div>
                    @empty
                    <p class="text-xs text-gray-400 text-center py-4">Aucune donnée</p>
                    @endforelse
                </div>
            </div>
            @endforeach
        </div>

    @elseif($onglet === 'occupation')
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Occupation par service</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Service</th>
                        <th class="px-4 py-2 text-center">Lits</th>
                        <th class="px-4 py-2 text-center">Occupés</th>
                        <th class="px-4 py-2 text-center">Libres</th>
                        <th class="px-4 py-2 text-left w-64">Taux d'occupation</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($occupation as $ligne)
                    <tr>
                        <td class="px-4 py-2 font-semibold">{{ $ligne['service']->nom }}</td>
                        <td class="px-4 py-2 text-center">{{ $ligne['lits'] }}</td>
                        <td class="px-4 py-2 text-center text-blue-700 font-semibold">{{ $ligne['occupes'] }}</td>
                        <td class="px-4 py-2 text-center text-green-700">{{ $ligne['lits'] - $ligne['occupes'] }}</td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full {{ $ligne['taux'] >= 90 ? 'bg-red-600' : ($ligne['taux'] >= 70 ? 'bg-amber-500' : 'bg-blue-600') }}"
                                         style="width: {{ $ligne['taux'] }}%"></div>
                                </div>
                                <span class="text-xs font-semibold w-12 text-right">{{ $ligne['taux'] }} %</span>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucun service avec des lits</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    @elseif($onglet === 'labo' || $onglet === 'imagerie')
        @php
            $plateau = $onglet === 'labo' ? $labo : $imagerie;
            $couleur = $onglet === 'labo' ? 'bg-purple-600' : 'bg-teal-600';
            $repartitions = $onglet === 'labo'
                ? ['unite' => 'Par unité d\'analyse', 'test' => 'Examens les plus demandés',
                   'statut' => 'Par étape', 'laborantin' => 'Par laborantin']
                : ['modalite' => 'Par modalité', 'test' => 'Examens les plus demandés',
                   'statut' => 'Par étape', 'radiologue' => 'Par radiologue'];
        @endphp

        {{-- Chiffres de tête du plateau technique --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            @foreach([
                ['Examens prescrits', $plateau['total'], ''],
                ['Dont urgents', $plateau['urgents'], ''],
                [$onglet === 'labo' ? 'Résultats rendus' : 'Comptes rendus signés',
                 $onglet === 'labo' ? ($plateau['statut']['valide'] ?? 0) : $plateau['comptes_rendus'], ''],
                ['Délai moyen de rendu', $plateau['delai_moyen'] ?? '—', $plateau['delai_moyen'] !== null ? ' h' : ''],
            ] as [$libelle, $valeur, $suffixe])
            <div class="bg-white rounded-xl shadow p-4">
                <p class="text-2xl font-bold text-gray-800">{{ $valeur }}{{ $suffixe }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ $libelle }}</p>
            </div>
            @endforeach
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            @foreach($repartitions as $cle => $titre)
            @php $donnees = collect($plateau[$cle] ?? []); $max = $donnees->max() ?: 1; @endphp
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-4 py-3 border-b font-semibold text-gray-700 text-sm">{{ $titre }}</div>
                <div class="p-4 space-y-2 max-h-72 overflow-y-auto">
                    @forelse($donnees as $libelle => $nombre)
                    <div>
                        <div class="flex justify-between text-xs mb-0.5">
                            <span class="text-gray-700 capitalize">{{ str_replace('_', ' ', $libelle) }}</span>
                            <span class="font-semibold">{{ $nombre }}</span>
                        </div>
                        {!! $barre($nombre, $max, $couleur) !!}
                    </div>
                    @empty
                    <p class="text-xs text-gray-400 text-center py-4">Aucune donnée</p>
                    @endforelse
                </div>
            </div>
            @endforeach
        </div>

    @elseif($onglet === 'pharmacie')
        <div class="grid md:grid-cols-3 gap-4">
            @foreach([
                'officine' => 'Sorties par officine',
                'produit' => 'Produits les plus consommés',
                'type_mouvement' => 'Mouvements par type',
            ] as $cle => $titre)
            @php $donnees = $pharmacie[$cle] ?? collect(); $max = $donnees->max() ?: 1; @endphp
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-4 py-3 border-b font-semibold text-gray-700 text-sm">{{ $titre }}</div>
                <div class="p-4 space-y-2 max-h-72 overflow-y-auto">
                    @forelse($donnees as $libelle => $nombre)
                    <div>
                        <div class="flex justify-between text-xs mb-0.5">
                            <span class="text-gray-700 capitalize">{{ str_replace('_', ' ', $libelle) }}</span>
                            <span class="font-semibold">{{ $nombre + 0 }}</span>
                        </div>
                        {!! $barre($nombre, $max, 'bg-green-600') !!}
                    </div>
                    @empty
                    <p class="text-xs text-gray-400 text-center py-4">Aucune donnée</p>
                    @endforelse
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>

<style>
@media print {
    .no-print, nav, header, footer { display: none !important; }
    body { background: #fff; }
}
</style>
@endsection
