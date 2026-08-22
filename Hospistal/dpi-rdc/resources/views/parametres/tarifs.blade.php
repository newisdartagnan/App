@extends('layouts.app')
@section('title', 'Tarifs et catalogues')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🏷️ Tarifs et catalogues</h2>
    <p class="text-sm text-gray-500 mb-5">
        Ce que coûte chaque prestation. Une révision ne touche que les
        prestations à venir : les factures déjà émises gardent leur montant.
        Retirer du catalogue n'efface rien — le produit disparaît des écrans de
        prescription et reste lisible sur les factures qui le portent.
    </p>

    @include('parametres._onglets')
    @include('partials._flash')

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    {{-- Familles de tarifs --}}
    <div class="flex flex-wrap gap-1 mb-4 text-sm">
        @foreach([
            'consultations' => '🩺 Consultations',
            'examens' => '🔬 Examens et imagerie',
            'medicaments' => '💊 Produits pharmaceutiques',
            'dietes' => '🍽️ Diètes',
        ] as $cle => $libelle)
        <a href="{{ route('tarifs.index', ['onglet' => $cle]) }}"
           class="px-4 py-2 rounded-lg border {{ $onglet === $cle ? 'bg-blue-700 text-white border-blue-700 font-semibold' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">
            {{ $libelle }}
        </a>
        @endforeach
    </div>

    {{-- ══ Consultations ══ --}}
    @if($onglet === 'consultations')
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-4 text-sm text-blue-900">
        Les consultations sont tarifées en dollars et facturées en francs au taux
        en vigueur — <strong>{{ number_format($tauxUsd, 2, ',', ' ') }} CDF pour 1 $</strong>.
        Réviser le taux depuis l'onglet « Taux de change » déplace donc tous ces
        montants d'un coup, sans y revenir un par un.
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Consultation</th>
                        <th class="px-4 py-3">Catégorie</th>
                        <th class="px-4 py-3 text-right">En francs</th>
                        <th class="px-4 py-3">Tarif ($)</th>
                        <th class="px-4 py-3 text-right">Catalogue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($consultations as $type)
                    <tr class="{{ $type->est_actif ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $type->libelle }}</p>
                            <p class="text-xs text-gray-400 font-mono">{{ $type->code }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ ucfirst($type->categorie) }}
                            @if($type->specialite)<p class="text-gray-400">{{ $type->specialite }}</p>@endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold">
                            {{ number_format($type->prixCdf(), 0, ',', ' ') }} CDF
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('tarifs.consultation', $type) }}" class="flex gap-1">
                                @csrf
                                <label for="c-{{ $type->id }}" class="sr-only">Tarif en dollars</label>
                                <input id="c-{{ $type->id }}" name="prix_usd" type="number" step="0.5" min="0" required
                                       value="{{ $type->prix_usd + 0 }}"
                                       class="w-24 border border-gray-300 rounded px-2 py-1 text-sm">
                                <button class="bg-blue-700 hover:bg-blue-800 text-white rounded px-3 py-1 text-xs font-semibold">
                                    Réviser
                                </button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @include('parametres._bascule-tarif', ['famille' => 'consultation', 'element' => $type, 'actif' => $type->est_actif])
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ══ Examens ══ --}}
    @if($onglet === 'examens')
    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <input type="hidden" name="onglet" value="examens">
        <div class="flex-1 min-w-64">
            <label for="r-ex" class="block text-xs font-semibold text-gray-600 mb-1">Chercher un examen</label>
            <input id="r-ex" name="recherche" value="{{ $recherche }}" placeholder="Hémogramme, échographie…"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Chercher</button>
        <span class="text-xs text-gray-500 pb-2">{{ $examens->count() }} examen(s)</span>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Examen</th>
                        <th class="px-4 py-3">Plateau</th>
                        <th class="px-4 py-3">Tarif (CDF)</th>
                        <th class="px-4 py-3">Rendu (h)</th>
                        <th class="px-4 py-3"></th>
                        <th class="px-4 py-3 text-right">Catalogue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($examens as $type)
                    <tr class="{{ $type->est_actif ? '' : 'opacity-50' }}">
                        <form method="POST" action="{{ route('tarifs.examen', $type) }}" id="f-ex-{{ $type->id }}">@csrf</form>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $type->libelle }}</p>
                            <p class="text-xs text-gray-400 font-mono">{{ $type->code }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ $type->domaine === 'imagerie' ? '📷 Imagerie' : '🔬 Laboratoire' }}
                            <p class="text-gray-400">{{ ucfirst(str_replace('_', ' ', $type->categorie)) }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <label for="p-{{ $type->id }}" class="sr-only">Tarif</label>
                            <input id="p-{{ $type->id }}" form="f-ex-{{ $type->id }}" name="prix" type="number" step="500" min="0" required
                                   value="{{ (int) $type->prix }}"
                                   class="w-28 border border-gray-300 rounded px-2 py-1 text-sm">
                        </td>
                        <td class="px-4 py-3">
                            <label for="d-{{ $type->id }}" class="sr-only">Délai de rendu</label>
                            <input id="d-{{ $type->id }}" form="f-ex-{{ $type->id }}" name="delai_heures" type="number" min="0" max="720"
                                   value="{{ $type->delai_heures }}"
                                   class="w-20 border border-gray-300 rounded px-2 py-1 text-sm">
                        </td>
                        <td class="px-4 py-3">
                            <button form="f-ex-{{ $type->id }}"
                                    class="bg-blue-700 hover:bg-blue-800 text-white rounded px-3 py-1 text-xs font-semibold">
                                Réviser
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @include('parametres._bascule-tarif', ['famille' => 'examen', 'element' => $type, 'actif' => $type->est_actif])
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">Aucun examen pour cette recherche.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ══ Produits pharmaceutiques ══ --}}
    @if($onglet === 'medicaments')
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-4 text-sm text-blue-900">
        Le prix porte sur <strong>une unité</strong> — un comprimé, une ampoule —
        et non sur le contenant : une plaquette de 10 à 500 CDF fait 50 CDF le
        comprimé. Il s'applique au dépôt comme à toutes les officines, pour que
        le patient paie le même prix quel que soit le guichet.
    </div>

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <input type="hidden" name="onglet" value="medicaments">
        <div class="flex-1 min-w-64">
            <label for="r-med" class="block text-xs font-semibold text-gray-600 mb-1">Chercher un produit</label>
            <input id="r-med" name="recherche" value="{{ $recherche }}" placeholder="Paracétamol, Clamoxyl…"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Chercher</button>
        <span class="text-xs text-gray-500 pb-2">{{ $medicaments->count() }} produit(s)</span>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Produit</th>
                        <th class="px-4 py-3">Présentation</th>
                        <th class="px-4 py-3">Prix de l'unité (CDF)</th>
                        <th class="px-4 py-3">Seuil d'alerte</th>
                        <th class="px-4 py-3"></th>
                        <th class="px-4 py-3 text-right">Catalogue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($medicaments as $medicament)
                    <tr class="{{ $medicament->est_actif ? '' : 'opacity-50' }}">
                        <form method="POST" action="{{ route('tarifs.medicament', $medicament) }}" id="f-md-{{ $medicament->id }}">@csrf</form>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $medicament->designation() }}</p>
                            @if($medicament->nom_commercial)
                            <p class="text-xs text-gray-400">{{ $medicament->nom_commercial }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">
                            {{ $medicament->libelleVoie() }} / {{ $medicament->libelleConditionnement() }}
                        </td>
                        <td class="px-4 py-3">
                            <label for="pv-{{ $medicament->id }}" class="sr-only">Prix de l'unité</label>
                            <input id="pv-{{ $medicament->id }}" form="f-md-{{ $medicament->id }}"
                                   name="prix_unitaire_vente" type="number" step="10" min="0" required
                                   value="{{ (int) ($medicament->stock?->prix_unitaire_vente ?? 0) }}"
                                   class="w-28 border border-gray-300 rounded px-2 py-1 text-sm">
                        </td>
                        <td class="px-4 py-3">
                            <label for="qa-{{ $medicament->id }}" class="sr-only">Seuil d'alerte</label>
                            <input id="qa-{{ $medicament->id }}" form="f-md-{{ $medicament->id }}"
                                   name="quantite_alerte" type="number" min="0"
                                   value="{{ (int) ($medicament->stock?->quantite_alerte ?? 10) }}"
                                   class="w-20 border border-gray-300 rounded px-2 py-1 text-sm">
                        </td>
                        <td class="px-4 py-3">
                            <button form="f-md-{{ $medicament->id }}"
                                    class="bg-blue-700 hover:bg-blue-800 text-white rounded px-3 py-1 text-xs font-semibold">
                                Réviser
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @include('parametres._bascule-tarif', ['famille' => 'medicament', 'element' => $medicament, 'actif' => $medicament->est_actif])
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">Aucun produit pour cette recherche.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ══ Diètes ══ --}}
    @if($onglet === 'dietes')
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-4 text-sm text-blue-900">
        Coût d'une journée servie, porté sur la facture du séjour. Le ménage,
        lui, fait partie de la chambre et ne se tarife pas ici.
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Régime</th>
                        <th class="px-4 py-3">Coût par jour (CDF)</th>
                        <th class="px-4 py-3"></th>
                        <th class="px-4 py-3 text-right">Catalogue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($dietes as $type)
                    <tr class="{{ $type->is_active ? '' : 'opacity-50' }}">
                        <form method="POST" action="{{ route('tarifs.diete', $type) }}" id="f-di-{{ $type->id }}">@csrf</form>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $type->libelle }}</p>
                            @if($type->description)<p class="text-xs text-gray-400">{{ $type->description }}</p>@endif
                        </td>
                        <td class="px-4 py-3">
                            <label for="dj-{{ $type->id }}" class="sr-only">Coût journalier</label>
                            <input id="dj-{{ $type->id }}" form="f-di-{{ $type->id }}"
                                   name="prix_journalier" type="number" step="500" min="0" required
                                   value="{{ (int) $type->prix_journalier }}"
                                   class="w-28 border border-gray-300 rounded px-2 py-1 text-sm">
                        </td>
                        <td class="px-4 py-3">
                            <button form="f-di-{{ $type->id }}"
                                    class="bg-blue-700 hover:bg-blue-800 text-white rounded px-3 py-1 text-xs font-semibold">
                                Réviser
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @include('parametres._bascule-tarif', ['famille' => 'diete', 'element' => $type, 'actif' => $type->is_active])
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endsection
