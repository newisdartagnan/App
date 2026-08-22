@extends('layouts.app')
@section('title', 'Registre transfusionnel')
@section('content')
<div class="max-w-full mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">📖 Registre transfusionnel</h2>
    <p class="text-sm text-gray-500 mb-5">
        Chaque poche posée, du donneur au receveur. Une transfusion n'est
        achevée que lorsque son heure de fin, son hémoglobine de contrôle et
        son incident éventuel sont écrits : c'est cette clôture qui fait la
        traçabilité, pas la sortie de stock.
    </p>

    @include('banque-sang._onglets')
    @include('partials._flash')

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $transfusions->count() }}</p>
            <p class="text-xs text-gray-500 mt-1">Poches posées sur la période</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold {{ $enCours > 0 ? 'text-amber-700' : 'text-gray-800' }}">{{ $enCours }}</p>
            <p class="text-xs text-gray-500 mt-1">Non clôturées</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold {{ $avecIncident > 0 ? 'text-red-700' : 'text-gray-800' }}">{{ $avecIncident }}</p>
            <p class="text-xs text-gray-500 mt-1">Avec incident déclaré</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">
                {{ number_format($transfusions->sum(fn ($t) => (int) $t->quantite), 0, ',', ' ') }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Millilitres transfusés</p>
        </div>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-debut" class="block text-xs font-semibold text-gray-600 mb-1">Du</label>
            <input id="f-debut" name="debut" type="date" value="{{ $filtres['debut'] }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="f-fin" class="block text-xs font-semibold text-gray-600 mb-1">Au</label>
            <input id="f-fin" name="fin" type="date" value="{{ $filtres['fin'] }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="f-groupe" class="block text-xs font-semibold text-gray-600 mb-1">Groupe du receveur</label>
            <select id="f-groupe" name="groupe" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Tous</option>
                @foreach($groupes as $g)
                <option value="{{ $g }}" @selected($filtres['groupe'] === $g)>{{ $g }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-etat" class="block text-xs font-semibold text-gray-600 mb-1">État</label>
            <select id="f-etat" name="etat" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Toutes</option>
                <option value="en_cours" @selected($filtres['etat'] === 'en_cours')>Non clôturées</option>
                <option value="incident" @selected($filtres['etat'] === 'incident')>Avec incident</option>
            </select>
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Appliquer</button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Jour</th>
                        <th class="px-4 py-3">Receveur</th>
                        <th class="px-4 py-3">Poche</th>
                        <th class="px-4 py-3">Donneur</th>
                        <th class="px-4 py-3 text-center">ABO</th>
                        <th class="px-4 py-3 text-center">Horaire</th>
                        <th class="px-4 py-3 text-center">Hb avant → après</th>
                        <th class="px-4 py-3">Suite</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($transfusions as $t)
                    <tr class="align-top {{ $t->avecIncident() ? 'bg-red-50/50' : '' }}">
                        <td class="px-4 py-3 whitespace-nowrap">{{ $t->jour?->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $t->patient?->nom_complet ?? '—' }}</p>
                            <p class="text-xs text-gray-400">
                                {{ $t->patient?->dossier_number }}
                                @if($t->demande) · {{ $t->demande->numero }} @endif
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-mono text-xs">{{ $t->numero_poche }}</span>
                            <p class="text-xs text-gray-400">{{ $t->libelleProduit() }} · {{ $t->quantite }} ml</p>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ $t->poche?->donneur?->nomComplet() ?? '—' }}
                            @if($t->poche?->donneur?->code)
                            <p class="text-gray-400 font-mono">{{ $t->poche->donneur->code }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap font-semibold">
                            {{ $t->groupe_donneur }} → {{ $t->groupe_receveur }}
                            @if($t->controle_ultime)
                            <p class="text-[11px] text-green-700 font-normal">✓ contrôle ultime</p>
                            @else
                            <p class="text-[11px] text-red-700 font-normal">contrôle ultime non tracé</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs whitespace-nowrap">
                            {{ $t->heure_debut }} → {{ $t->heure_fin ?: '…' }}
                            @if($t->dureeMinutes() !== null)
                            <p class="text-gray-400">{{ $t->dureeMinutes() }} min</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs whitespace-nowrap">
                            {{ $t->hemoglobine_avant !== null ? ($t->hemoglobine_avant + 0) : '—' }}
                            →
                            {{ $t->hemoglobine_apres !== null ? ($t->hemoglobine_apres + 0) : '—' }}
                            @if($t->rendement() !== null)
                            <p class="{{ $t->rendementInsuffisant() ? 'text-amber-700 font-semibold' : 'text-gray-400' }}">
                                {{ $t->rendement() >= 0 ? '+' : '' }}{{ $t->rendement() }} g/dL
                                @if($t->rendementInsuffisant()) — gain faible @endif
                            </p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if(! $t->estCloturee())
                            <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-900 font-semibold">À clôturer</span>
                            @elseif($t->avecIncident())
                            <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-800 font-semibold">
                                {{ $t->libelleIncident() }}
                            </span>
                            @else
                            <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-800">Sans incident</span>
                            @endif
                            @if($t->observation)
                            <p class="text-gray-500 mt-1">{{ $t->observation }}</p>
                            @endif
                            @if($t->facture)
                            <p class="text-gray-400 mt-1">Facturée — {{ $t->facture->numero_facture }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-xs whitespace-nowrap">
                            @if($t->demande)
                            <a href="{{ route('banque-sang.demande', $t->demande) }}"
                               class="text-blue-700 hover:underline">Ouvrir →</a>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="px-4 py-12 text-center text-gray-400">
                        Aucune transfusion sur cette période.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
