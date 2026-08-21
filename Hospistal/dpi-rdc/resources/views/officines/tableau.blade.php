@extends('layouts.app')
@section('title', 'Officines — vue d\'ensemble')
@section('content')
<div class="max-w-full mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">💊 Officines pharmaceutiques</h2>
    <p class="text-sm text-gray-500 mb-5">
        Ce que chaque officine détient, ce qui lui manque, ce qu'elle a demandé
        au dépôt central et ce qu'elle a sorti. Le dépôt ne délivre pas aux
        patients : il réapprovisionne les officines, qui seules servent.
    </p>

    @include('pharmacie._onglets')
    @include('partials._flash')

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-debut" class="block text-xs font-semibold text-gray-600 mb-1">Mouvements du</label>
            <input id="f-debut" name="debut" type="date" value="{{ $debut }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="f-fin" class="block text-xs font-semibold text-gray-600 mb-1">au</label>
            <input id="f-fin" name="fin" type="date" value="{{ $fin }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Appliquer</button>
        @if($active)
        <span class="text-xs text-gray-500 pb-2 ml-auto">
            Officine de travail : <strong>{{ $active->nom }}</strong>
        </span>
        @endif
    </form>

    {{-- Le tableau de contrôle --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Officine</th>
                        <th class="px-4 py-3 text-right">Références</th>
                        <th class="px-4 py-3 text-right">Unités en stock</th>
                        <th class="px-4 py-3 text-right">Valeur (CDF)</th>
                        <th class="px-4 py-3 text-center">Ruptures</th>
                        <th class="px-4 py-3 text-center">Sous alerte</th>
                        <th class="px-4 py-3 text-right">Entrées</th>
                        <th class="px-4 py-3 text-right">Sorties</th>
                        <th class="px-4 py-3 text-center">Réquisitions</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($lignes as $ligne)
                    @php $officine = $ligne['officine']; @endphp
                    <tr class="{{ $ligne['ruptures'] > 0 ? 'bg-red-50/40' : '' }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $officine->nom }}</p>
                            <p class="text-xs text-gray-400">
                                {{ $officine->estDepotCentral() ? 'Réserve — ne délivre pas aux patients' : 'Délivre aux patients' }}
                                @if($officine->service) · {{ $officine->service->nom }} @endif
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right">{{ $ligne['references'] }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($ligne['unites'], 0, ',', ' ') }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($ligne['valeur'], 0, ',', ' ') }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $ligne['ruptures'] > 0 ? 'bg-red-100 text-red-800 font-semibold' : 'bg-gray-100 text-gray-500' }}">
                                {{ $ligne['ruptures'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $ligne['alertes'] > 0 ? 'bg-amber-100 text-amber-900' : 'bg-gray-100 text-gray-500' }}">
                                {{ $ligne['alertes'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-xs text-green-700">
                            {{ $ligne['entrees'] > 0 ? '+'.number_format($ligne['entrees'], 0, ',', ' ') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right text-xs text-blue-700">
                            {{ $ligne['sorties'] > 0 ? '−'.number_format($ligne['sorties'], 0, ',', ' ') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs">
                            @if($ligne['requisitions_ouvertes'] > 0)
                            <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-semibold">
                                {{ $ligne['requisitions_ouvertes'] }} en attente
                            </span>
                            @else
                            <span class="text-gray-400">{{ $ligne['requisitions_periode'] }} au total</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <form method="POST" action="{{ route('officines.activer', $officine) }}" class="inline">
                                @csrf
                                <button class="text-xs text-blue-700 hover:underline">
                                    {{ $active?->id === $officine->id ? 'Ouvrir →' : 'Contrôler →' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @if($ligne['produits_en_rupture']->isNotEmpty())
                    <tr class="bg-red-50/40">
                        <td colspan="10" class="px-4 pb-3 pt-0 text-xs text-red-800">
                            <strong>En rupture :</strong> {{ $ligne['produits_en_rupture']->implode(' · ') }}
                            @if($ligne['ruptures'] > $ligne['produits_en_rupture']->count())
                                et {{ $ligne['ruptures'] - $ligne['produits_en_rupture']->count() }} autre(s)
                            @endif
                            @unless($officine->estDepotCentral())
                            — à demander au dépôt central par réquisition.
                            @endunless
                        </td>
                    </tr>
                    @endif
                    @empty
                    <tr><td colspan="10" class="px-4 py-12 text-center text-gray-400">
                        Aucune officine active.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Réquisitions que le dépôt doit servir --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700 flex flex-wrap items-center justify-between gap-2">
            <span>
                Réquisitions en attente au dépôt central
                <span class="text-gray-400 font-normal text-sm">— {{ $requisitionsOuvertes->count() }}</span>
            </span>
            <a href="{{ route('officines.depot') }}" class="text-sm text-blue-700 hover:underline">
                Traiter au dépôt central →
            </a>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($requisitionsOuvertes as $requisition)
            <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium">
                        <span class="font-mono">{{ $requisition->numero }}</span>
                        — {{ $requisition->officine?->nom ?? 'Officine inconnue' }}
                    </p>
                    <p class="text-xs text-gray-500">
                        {{ $requisition->date_demande?->format('d/m/Y H:i') }}
                        · {{ $requisition->lignes->count() }} produit(s) demandé(s)
                        @if($requisition->demandeur) · {{ $requisition->demandeur->nom_complet }} @endif
                        @if($requisition->motif) · {{ $requisition->motif }} @endif
                    </p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                    {{ $requisition->statut === 'envoyee' ? 'À servir' : 'Partiellement servie' }}
                </span>
            </div>
            @empty
            <p class="px-5 py-8 text-center text-gray-400 text-sm">
                Aucune réquisition en attente : les officines sont approvisionnées.
            </p>
            @endforelse
        </div>
    </div>
</div>
@endsection
