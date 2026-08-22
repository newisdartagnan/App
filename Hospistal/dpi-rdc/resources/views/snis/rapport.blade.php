@extends('layouts.app')
@section('title', 'Rapport mensuel SNIS')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <a href="{{ route('statistiques.index') }}" class="text-blue-700 hover:underline text-sm">← Statistiques</a>
        <h2 class="text-2xl font-bold text-gray-800">📋 Rapport mensuel SNIS</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        {{ $etablissement }} — {{ ucfirst($rapport['periode']['libelle']) }}.
        Tous ces chiffres sont comptés dans la base, à la ligne près. Vérifiez-les
        avant de les remonter : c'est vous qui signez.
    </p>

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-5 flex flex-wrap gap-3 items-end">
        {{-- Aucun script : deux champs et un bouton, plus des liens directs
             vers les douze derniers mois. --}}
        <div>
            <label for="annee" class="block text-xs font-semibold text-gray-600 mb-1">Année</label>
            <input id="annee" name="annee" type="number" min="2000" max="2100" value="{{ $annee }}"
                   class="w-24 border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="mois" class="block text-xs font-semibold text-gray-600 mb-1">Mois</label>
            <input id="mois" name="mois" type="number" min="1" max="12" value="{{ $mois }}"
                   class="w-20 border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Afficher</button>

        <div class="w-full flex flex-wrap gap-1 pt-1">
            @foreach($moisDisponibles as $m)
            <a href="{{ route('snis.index', ['annee' => $m['annee'], 'mois' => $m['mois']]) }}"
               class="px-2 py-1 rounded text-xs border {{ $m['annee'] === $annee && $m['mois'] === $mois ? 'bg-blue-700 text-white border-blue-700 font-semibold' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' }}">
                {{ $m['libelle'] }}
            </a>
            @endforeach
        </div>

        <div class="ml-auto flex gap-2">
            <a href="{{ route('snis.csv', ['annee' => $annee, 'mois' => $mois]) }}"
               class="bg-green-700 hover:bg-green-800 text-white px-4 py-2 rounded-lg text-sm font-semibold">
                ⬇️ Tableur (CSV)
            </a>
            <a href="{{ route('snis.imprimer', ['annee' => $annee, 'mois' => $mois]) }}" target="_blank"
               class="bg-white border border-blue-300 text-blue-700 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-50">
                🖨️ Imprimer
            </a>
        </div>
    </form>

    {{-- 1. Consultations --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            1. Consultations curatives
            <span class="text-gray-400 font-normal text-sm">
                — {{ $rapport['consultations']['total'] }} passages,
                dont {{ $rapport['consultations']['nouveaux'] }} nouveaux cas
                et {{ $rapport['consultations']['urgences'] }} aux urgences
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Tranche d'âge</th>
                        <th class="px-4 py-2 text-right">Nouveaux H</th>
                        <th class="px-4 py-2 text-right">Nouveaux F</th>
                        <th class="px-4 py-2 text-right">Anciens H</th>
                        <th class="px-4 py-2 text-right">Anciens F</th>
                        <th class="px-4 py-2 text-right font-bold">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rapport['consultations']['lignes'] as $ligne)
                    <tr class="{{ $ligne['total'] === 0 ? 'text-gray-400' : '' }}">
                        <td class="px-4 py-2">{{ $ligne['libelle'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $ligne['nouveaux_m'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $ligne['nouveaux_f'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $ligne['anciens_m'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $ligne['anciens_f'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $ligne['total'] }}</td>
                    </tr>
                    @endforeach
                    <tr class="bg-gray-50 font-bold">
                        <td class="px-4 py-2">TOTAL</td>
                        <td colspan="4"></td>
                        <td class="px-4 py-2 text-right">{{ $rapport['consultations']['total'] }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- 2. Morbidité --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            2. Morbidité
            <span class="text-gray-400 font-normal text-sm">
                — {{ $rapport['morbidite']['total_diagnostics'] }} diagnostics posés
                sur {{ $rapport['morbidite']['consultations'] }} consultations
            </span>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-600">
                <tr>
                    <th class="px-4 py-2 text-left">Pathologie</th>
                    <th class="px-4 py-2 text-right">Moins de 5 ans</th>
                    <th class="px-4 py-2 text-right">5 ans et plus</th>
                    <th class="px-4 py-2 text-right font-bold">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rapport['morbidite']['lignes'] as $cle => $ligne)
                <tr class="{{ $cle === 'autres' ? 'text-gray-500 italic' : '' }}">
                    <td class="px-4 py-2">{{ $ligne['libelle'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $ligne['moins_5ans'] }}</td>
                    <td class="px-4 py-2 text-right">{{ $ligne['plus_5ans'] }}</td>
                    <td class="px-4 py-2 text-right font-semibold">{{ $ligne['total'] }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">
                    Aucun diagnostic posé sur ce mois.
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @if(($rapport['morbidite']['lignes']['autres']['total'] ?? 0) > 0)
        <p class="px-5 py-2 text-xs text-gray-500 border-t">
            Les « autres pathologies » sont les diagnostics que le canevas ne
            nomme pas, ou dont le libellé ne correspond à aucune de ses rubriques.
            Le total des lignes égale toujours le total des diagnostics : rien ne se perd.
        </p>
        @endif
    </div>

    {{-- 3 et 4 côte à côte --}}
    <div class="grid lg:grid-cols-2 gap-5 mb-5">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">3. Hospitalisation</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach([
                        'Admissions' => $rapport['hospitalisation']['admissions'],
                        'Sorties' => $rapport['hospitalisation']['sorties'],
                        'Journées d\'hospitalisation' => $rapport['hospitalisation']['journees'],
                        'Durée moyenne de séjour (jours)' => $rapport['hospitalisation']['duree_moyenne'],
                    ] as $libelle => $valeur)
                    <tr>
                        <td class="px-4 py-2">{{ $libelle }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $valeur }}</td>
                    </tr>
                    @endforeach
                    @foreach($rapport['hospitalisation']['par_issue'] as $issue => $nombre)
                    <tr class="text-gray-600">
                        <td class="px-4 py-2 pl-8 text-xs">Sorties — {{ $issue }}</td>
                        <td class="px-4 py-2 text-right">{{ $nombre }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">4. Santé de la mère et du nouveau-né</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach($rapport['maternite']['cpn_par_rang'] as $rang => $nombre)
                    <tr><td class="px-4 py-2">{{ $rang }}</td><td class="px-4 py-2 text-right font-semibold">{{ $nombre }}</td></tr>
                    @endforeach
                    @foreach([
                        'Vaccin antitétanique administré' => 'vat_administres',
                        'SP (paludisme)' => 'sp_administres',
                        'Fer et acide folique' => 'fer_folates',
                        'Moustiquaires remises' => 'moustiquaires',
                        'Accouchements' => 'accouchements',
                        'dont césariennes' => 'cesariennes',
                        'dont hémorragies' => 'hemorragies',
                        'Naissances vivantes' => 'naissances_vivantes',
                        'Mort-nés' => 'mort_nes',
                        'Décès néonatals' => 'deces_neonatals',
                        'Petit poids de naissance' => 'petits_poids',
                        'Décès maternels' => 'deces_maternels',
                    ] as $libelle => $cle)
                    <tr class="{{ in_array($cle, ['mort_nes', 'deces_neonatals', 'deces_maternels'], true) && $rapport['maternite'][$cle] > 0 ? 'bg-red-50' : '' }}">
                        <td class="px-4 py-2">{{ $libelle }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $rapport['maternite'][$cle] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- 5, 6, 7, 8 --}}
    <div class="grid lg:grid-cols-2 gap-5 mb-5">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">5. Laboratoire et imagerie</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr><td class="px-4 py-2">Demandes de laboratoire</td><td class="px-4 py-2 text-right font-semibold">{{ $rapport['laboratoire']['demandes_labo'] }}</td></tr>
                    <tr><td class="px-4 py-2">Demandes d'imagerie</td><td class="px-4 py-2 text-right font-semibold">{{ $rapport['laboratoire']['demandes_imagerie'] }}</td></tr>
                    <tr><td class="px-4 py-2">Bilans validés</td><td class="px-4 py-2 text-right font-semibold">{{ $rapport['laboratoire']['validees'] }}</td></tr>
                    @foreach($rapport['laboratoire']['par_examen'] as $examen => $nombre)
                    <tr class="text-gray-600"><td class="px-4 py-2 pl-8 text-xs">{{ $examen }}</td><td class="px-4 py-2 text-right">{{ $nombre }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>
            <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
                <div class="px-5 py-3 border-b font-semibold text-gray-700">6. Transfusion sanguine</div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @foreach([
                            'Poches collectées' => 'poches_collectees',
                            'Poches détruites au dépistage' => 'poches_detruites',
                            'Poches périmées' => 'poches_perimees',
                            'Transfusions réalisées' => 'transfusions',
                            'Incidents transfusionnels' => 'incidents',
                        ] as $libelle => $cle)
                        <tr class="{{ $cle === 'incidents' && $rapport['sang'][$cle] > 0 ? 'bg-red-50' : '' }}">
                            <td class="px-4 py-2">{{ $libelle }}</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ $rapport['sang'][$cle] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
                <div class="px-5 py-3 border-b font-semibold text-gray-700">7. Pharmacie</div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <tr><td class="px-4 py-2">Références au catalogue</td><td class="px-4 py-2 text-right font-semibold">{{ $rapport['pharmacie']['references'] }}</td></tr>
                        <tr class="{{ $rapport['pharmacie']['ruptures'] > 0 ? 'bg-red-50' : '' }}">
                            <td class="px-4 py-2">Produits en rupture</td>
                            <td class="px-4 py-2 text-right font-semibold">{{ $rapport['pharmacie']['ruptures'] }}</td>
                        </tr>
                        <tr><td class="px-4 py-2">Sous seuil d'alerte</td><td class="px-4 py-2 text-right font-semibold">{{ $rapport['pharmacie']['sous_alerte'] }}</td></tr>
                    </tbody>
                </table>
                @if($rapport['pharmacie']['produits_en_rupture']->isNotEmpty())
                <p class="px-5 py-2 text-xs text-red-800 border-t bg-red-50/40">
                    <strong>En rupture :</strong> {{ $rapport['pharmacie']['produits_en_rupture']->implode(' · ') }}
                </p>
                @endif
            </div>

            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-5 py-3 border-b font-semibold text-gray-700">8. Décès</div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        <tr class="{{ $rapport['deces']['total'] > 0 ? 'bg-red-50' : '' }}">
                            <td class="px-4 py-2 font-medium">Total</td>
                            <td class="px-4 py-2 text-right font-bold">{{ $rapport['deces']['total'] }}</td>
                        </tr>
                        <tr><td class="px-4 py-2 text-xs pl-8">dont dans les 48 premières heures</td><td class="px-4 py-2 text-right">{{ $rapport['deces']['moins_48h'] }}</td></tr>
                        @foreach($rapport['deces']['par_tranche'] as $tranche => $nombre)
                        <tr class="text-gray-600"><td class="px-4 py-2 pl-8 text-xs">{{ $tranche }}</td><td class="px-4 py-2 text-right">{{ $nombre }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Ce que l'application ne sait pas compter --}}
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4">
        <p class="font-semibold text-amber-900 mb-1">Rubriques à reprendre du registre papier</p>
        <p class="text-sm text-amber-900 mb-2">
            L'application ne suit pas encore ces activités : elle ne les invente pas.
            Complétez-les à la main avant de remonter le rapport.
        </p>
        <ul class="text-sm text-amber-900 list-disc list-inside">
            @foreach($rapport['non_suivi'] as $rubrique)
            <li>{{ $rubrique }}</li>
            @endforeach
        </ul>
    </div>
</div>
@endsection
