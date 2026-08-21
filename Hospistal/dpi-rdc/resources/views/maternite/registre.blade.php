@extends('layouts.app')
@section('title', 'Registre des accouchements')
@section('content')
<div class="max-w-full mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">👶 Maternité</h2>
    <p class="text-sm text-gray-500 mb-5">
        Registre des accouchements : mode, terme, issue pour la mère et pour
        l'enfant. Les indicateurs qui suivent sont ceux que réclame le rapport
        mensuel de la zone de santé.
    </p>

    @include('maternite._onglets')
    @include('partials._flash')

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-debut" class="block text-xs font-semibold text-gray-600 mb-1">Du</label>
            <input id="f-debut" name="debut" type="date" value="{{ $debut }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="f-fin" class="block text-xs font-semibold text-gray-600 mb-1">Au</label>
            <input id="f-fin" name="fin" type="date" value="{{ $fin }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Appliquer</button>
    </form>

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
        @foreach([
            ['Accouchements', $indicateurs['accouchements'], ''],
            ['Césariennes', $indicateurs['cesariennes'],
             $indicateurs['accouchements'] > 0
                ? round($indicateurs['cesariennes'] * 100 / $indicateurs['accouchements']).' %' : ''],
            ['Naissances vivantes', $indicateurs['vivants'], ''],
            ['Mort-nés', $indicateurs['mort_nes'], ''],
            ['Hémorragies', $indicateurs['hemorragies'], ''],
        ] as [$libelle, $valeur, $detail])
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $valeur }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $libelle }}</p>
            @if($detail)<p class="text-xs text-gray-400">{{ $detail }}</p>@endif
        </div>
        @endforeach
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-3 py-3">Date</th>
                        <th class="px-3 py-3">Mère</th>
                        <th class="px-3 py-3">Âge</th>
                        <th class="px-3 py-3">Formule</th>
                        <th class="px-3 py-3">Prise en charge</th>
                        <th class="px-3 py-3">Mode</th>
                        <th class="px-3 py-3 text-center">Terme</th>
                        <th class="px-3 py-3 text-right">Saignement</th>
                        <th class="px-3 py-3">Enfant(s)</th>
                        <th class="px-3 py-3">Accoucheur</th>
                        <th class="px-3 py-3">Issue mère</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($accouchements as $acc)
                    <tr class="{{ $acc->estHemorragique() || $acc->etat_mere === 'deces' ? 'bg-red-50/50' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $acc->date_accouchement->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 font-medium">{{ $acc->patient->nom_complet }}</td>
                        <td class="px-3 py-2">{{ $acc->patient->date_naissance?->age ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono">{{ $acc->grossesse?->formuleObstetricale() ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $acc->patient->libellePriseEnCharge() }}</td>
                        <td class="px-3 py-2">{{ $acc->libelleMode() }}</td>
                        <td class="px-3 py-2 text-center">
                            {{ $acc->terme_semaines ? $acc->terme_semaines.' SA' : '—' }}
                            @if($acc->estPremature())<span class="text-amber-800">·prém.</span>@endif
                        </td>
                        <td class="px-3 py-2 text-right {{ $acc->estHemorragique() ? 'text-red-700 font-semibold' : '' }}">
                            {{ $acc->saignement_ml !== null ? $acc->saignement_ml.' ml' : '—' }}
                        </td>
                        <td class="px-3 py-2">
                            @foreach($acc->nouveauNes as $enfant)
                            <span class="{{ $enfant->estVivant() ? '' : 'text-gray-500 line-through' }}">
                                {{ $enfant->libelleSexe() }}{{ $enfant->poids_g ? ' '.$enfant->poids_g.'g' : '' }}
                            </span>@if(! $loop->last), @endif
                            @endforeach
                        </td>
                        <td class="px-3 py-2">{{ $acc->accoucheur?->nom_complet ?? $acc->sage_femme ?? '—' }}</td>
                        <td class="px-3 py-2 {{ $acc->etat_mere === 'deces' ? 'text-red-700 font-semibold' : '' }}">
                            {{ \App\Models\Accouchement::ETATS_MERE[$acc->etat_mere] ?? $acc->etat_mere }}
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="px-4 py-12 text-center text-gray-400">
                        Aucun accouchement sur cette période.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700 text-sm">Par mode d'accouchement</div>
            <div class="p-4 space-y-1">
                @forelse($indicateurs['par_mode'] as $libelle => $nombre)
                <div class="flex justify-between text-xs">
                    <span class="text-gray-700">{{ $libelle }}</span>
                    <span class="font-semibold">{{ $nombre }}</span>
                </div>
                @empty
                <p class="text-xs text-gray-400 text-center py-3">Aucune donnée</p>
                @endforelse
            </div>
        </div>
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700 text-sm">Nouveau-nés</div>
            <div class="p-4 space-y-1 text-xs">
                <div class="flex justify-between"><span>Naissances totales</span><span class="font-semibold">{{ $indicateurs['naissances'] }}</span></div>
                <div class="flex justify-between"><span>Vivantes</span><span class="font-semibold">{{ $indicateurs['vivants'] }}</span></div>
                <div class="flex justify-between"><span>Mort-nés</span><span class="font-semibold">{{ $indicateurs['mort_nes'] }}</span></div>
                <div class="flex justify-between"><span>Petit poids (moins de 2 500 g)</span><span class="font-semibold">{{ $indicateurs['petit_poids'] }}</span></div>
                <div class="flex justify-between"><span>Prématurés</span><span class="font-semibold">{{ $indicateurs['prematures'] }}</span></div>
                <div class="flex justify-between text-red-700"><span>Décès maternels</span><span class="font-semibold">{{ $indicateurs['deces_maternels'] }}</span></div>
            </div>
        </div>
    </div>
</div>
@endsection
