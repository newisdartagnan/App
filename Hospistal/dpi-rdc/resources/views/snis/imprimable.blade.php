@extends('layouts.app')
@section('title', 'Rapport SNIS — impression')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    <div class="mb-4 flex flex-wrap items-center gap-3 dpi-sans-impression">
        <a href="{{ route('snis.index', ['annee' => $rapport['periode']['annee'], 'mois' => $rapport['periode']['mois']]) }}"
           class="text-blue-700 hover:underline text-sm">← Le rapport</a>
        <button type="button" data-imprimer
                class="ml-auto inline-flex items-center gap-2 bg-blue-700 hover:bg-blue-800 text-white
                       font-semibold rounded-lg px-5 py-2.5 text-sm min-h-[44px]">
            🖨️ Imprimer
        </button>
    </div>

    <div class="bg-white rounded-xl shadow p-8 text-sm">

        <div class="text-center border-b-2 border-blue-800 pb-3 mb-5">
            <p class="text-xs uppercase tracking-wide text-gray-600">République Démocratique du Congo</p>
            <p class="text-xs text-gray-600">Ministère de la Santé Publique — Système National d'Information Sanitaire</p>
            <p class="text-lg font-bold text-blue-900 uppercase mt-2">{{ $etablissement }}</p>
            <p class="text-base font-bold mt-1">
                RAPPORT MENSUEL — {{ mb_strtoupper($rapport['periode']['libelle']) }}
            </p>
        </div>

        @php
            $sections = [
                '1. CONSULTATIONS CURATIVES' => collect($rapport['consultations']['lignes'])
                    ->mapWithKeys(fn ($l) => [$l['libelle'] => $l['total']])
                    ->merge([
                        'TOTAL des passages' => $rapport['consultations']['total'],
                        'dont nouveaux cas' => $rapport['consultations']['nouveaux'],
                        'dont anciens cas' => $rapport['consultations']['anciens'],
                        'dont passages aux urgences' => $rapport['consultations']['urgences'],
                    ]),
                '2. MORBIDITÉ' => collect($rapport['morbidite']['toutes_lignes'])
                    ->mapWithKeys(fn ($l) => [$l['libelle'] => $l['total']])
                    ->merge(['TOTAL des diagnostics' => $rapport['morbidite']['total_diagnostics']]),
                '3. HOSPITALISATION' => collect([
                    'Admissions' => $rapport['hospitalisation']['admissions'],
                    'Sorties' => $rapport['hospitalisation']['sorties'],
                    'Journées d\'hospitalisation' => $rapport['hospitalisation']['journees'],
                    'Durée moyenne de séjour (jours)' => $rapport['hospitalisation']['duree_moyenne'],
                ])->merge($rapport['hospitalisation']['par_issue']->mapWithKeys(
                    fn ($n, $issue) => ['Sorties — '.$issue => $n]
                )),
                '4. SANTÉ DE LA MÈRE ET DU NOUVEAU-NÉ' => $rapport['maternite']['cpn_par_rang']->merge([
                    'Vaccin antitétanique administré' => $rapport['maternite']['vat_administres'],
                    'SP (paludisme)' => $rapport['maternite']['sp_administres'],
                    'Fer et acide folique' => $rapport['maternite']['fer_folates'],
                    'Moustiquaires remises' => $rapport['maternite']['moustiquaires'],
                    'Accouchements' => $rapport['maternite']['accouchements'],
                    'dont césariennes' => $rapport['maternite']['cesariennes'],
                    'dont hémorragies de la délivrance' => $rapport['maternite']['hemorragies'],
                    'Naissances vivantes' => $rapport['maternite']['naissances_vivantes'],
                    'Mort-nés' => $rapport['maternite']['mort_nes'],
                    'Décès néonatals' => $rapport['maternite']['deces_neonatals'],
                    'Petit poids de naissance (< 2500 g)' => $rapport['maternite']['petits_poids'],
                    'Décès maternels' => $rapport['maternite']['deces_maternels'],
                ]),
                '5. LABORATOIRE ET IMAGERIE' => collect([
                    'Demandes de laboratoire' => $rapport['laboratoire']['demandes_labo'],
                    'Demandes d\'imagerie' => $rapport['laboratoire']['demandes_imagerie'],
                    'Bilans validés' => $rapport['laboratoire']['validees'],
                ]),
                '6. TRANSFUSION SANGUINE' => collect([
                    'Poches collectées' => $rapport['sang']['poches_collectees'],
                    'Poches détruites au dépistage' => $rapport['sang']['poches_detruites'],
                    'Poches périmées' => $rapport['sang']['poches_perimees'],
                    'Transfusions réalisées' => $rapport['sang']['transfusions'],
                    'Incidents transfusionnels' => $rapport['sang']['incidents'],
                ]),
                '7. PHARMACIE' => collect([
                    'Références au catalogue' => $rapport['pharmacie']['references'],
                    'Produits en rupture' => $rapport['pharmacie']['ruptures'],
                    'Produits sous seuil d\'alerte' => $rapport['pharmacie']['sous_alerte'],
                ]),
                '8. DÉCÈS' => collect(['Total' => $rapport['deces']['total'],
                    'dont dans les 48 premières heures' => $rapport['deces']['moins_48h']])
                    ->merge($rapport['deces']['par_tranche']),
            ];
        @endphp

        @foreach($sections as $titre => $valeurs)
        <div class="mb-4">
            <p class="font-bold text-blue-900 border-b border-gray-300 pb-1 mb-1">{{ $titre }}</p>
            <table class="w-full">
                <tbody>
                    @foreach($valeurs as $libelle => $valeur)
                    <tr class="border-b border-gray-100">
                        <td class="py-1">{{ $libelle }}</td>
                        <td class="py-1 text-right font-semibold w-24">{{ $valeur }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endforeach

        <div class="mb-4">
            <p class="font-bold text-amber-900 border-b border-gray-300 pb-1 mb-1">
                RUBRIQUES À COMPLÉTER DEPUIS LE REGISTRE PAPIER
            </p>
            <p class="text-xs text-gray-600 mb-1">
                L'application ne suit pas encore ces activités et ne les invente pas.
            </p>
            @foreach($rapport['non_suivi'] as $rubrique)
            <p class="border-b border-dotted border-gray-300 py-2">
                {{ $rubrique }} <span class="float-right text-gray-400">…………</span>
            </p>
            @endforeach
        </div>

        <div class="flex justify-between items-end mt-8 pt-4 border-t">
            <p class="text-xs text-gray-500">
                Édité le {{ now()->format('d/m/Y à H:i') }} par {{ auth()->user()?->nom_complet }}.
            </p>
            <div class="text-center">
                <p class="text-sm font-medium">Le Médecin Directeur</p>
                <p class="text-xs text-gray-500 border-t border-gray-400 pt-1 mt-8 px-10">Signature et cachet</p>
            </div>
        </div>
    </div>
</div>
@endsection
