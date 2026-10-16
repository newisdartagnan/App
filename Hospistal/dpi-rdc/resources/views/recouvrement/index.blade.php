@extends('layouts.app')
@section('title', 'Tableau de recouvrement')
@section('content')
@php
    $dev = app(\App\Services\DeviseService::class);
    $cdf = fn ($m) => $dev->formater((float) $m, 'CDF');
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',').' %';
    $ind = $indicateurs;
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-1">
        <h2 class="text-2xl font-bold text-gray-800">Tableau de recouvrement</h2>
        <form method="GET" action="{{ route('recouvrement.index') }}" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="r-mois" class="block text-xs text-gray-500 mb-1">Mois</label>
                <input id="r-mois" type="month" name="mois" value="{{ $mois->format('Y-m') }}"
                       class="min-h-[40px] rounded-lg border border-gray-300 px-3 py-1 text-sm">
            </div>
            <button class="min-h-[40px] px-4 py-1 rounded-lg bg-blue-700 hover:bg-blue-800 text-white text-sm font-medium">Afficher</button>
        </form>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Du {{ $debut->format('d/m/Y') }} au {{ $fin->format('d/m/Y') }} ({{ $jours }} jours). Montants en francs
        congolais : une facture en dollars compte au taux de son émission. Les créances sont celles d'aujourd'hui.
    </p>

    {{-- Indicateurs --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 mb-6">
        <div class="bg-white rounded-xl shadow px-4 py-3">
            <p class="text-xs text-gray-500">Facturé sur la période (net des avoirs)</p>
            <p class="text-xl font-bold text-gray-800">{{ $cdf($ind['facture']) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow px-4 py-3">
            <p class="text-xs text-gray-500">Encaissé sur la période</p>
            <p class="text-xl font-bold text-green-700">{{ $cdf($ind['encaisse']['total']) }}</p>
            <p class="text-xs text-gray-500">guichet {{ $cdf($ind['encaisse']['guichet']) }} · acomptes {{ $cdf($ind['encaisse']['acomptes']) }}
                · assureurs {{ $cdf($ind['encaisse']['conventions']) }}</p>
        </div>
        <div class="bg-white rounded-xl shadow px-4 py-3">
            <p class="text-xs text-gray-500">Taux de recouvrement</p>
            <p class="text-xl font-bold text-blue-800">{{ $pct($ind['taux_recouvrement']) }}</p>
            <p class="text-xs text-gray-500">encaissé ÷ facturé</p>
        </div>
        <div class="bg-white rounded-xl shadow px-4 py-3">
            <p class="text-xs text-gray-500">Délai moyen de paiement (DSO)</p>
            <p class="text-xl font-bold text-gray-800">{{ $ind['dso'] === null ? '—' : $ind['dso'].' jours' }}</p>
            <p class="text-xs text-gray-500">créances {{ $cdf($ind['creances']) }} ÷ facturé × {{ $jours }} j</p>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-3 mb-6 text-sm">
        <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
            <p class="text-xs text-blue-800">Dû par les assureurs (bordereaux)</p>
            <p class="font-bold text-blue-900">{{ $cdf($ind['creance_conventions']) }}</p>
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
            <p class="text-xs text-amber-800">Part assureur pas encore en bordereau</p>
            <p class="font-bold text-amber-900">{{ $cdf($ind['creance_a_facturer']) }}</p>
            <a href="{{ route('conventions.index') }}" class="text-xs text-amber-800 underline">Émettre les bordereaux →</a>
        </div>
        <div class="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3">
            <p class="text-xs text-gray-600">Dû par les patients (guichet)</p>
            <p class="font-bold text-gray-900">{{ $cdf($ind['creance_patients']) }}</p>
        </div>
    </div>

    {{-- Suivi par assureur --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Suivi par assureur</div>
        @if($parAssureur->isEmpty())
        <p class="px-5 py-6 text-sm text-gray-400">Aucune convention n'a de créance ni de bordereau sur la période.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Assureur</th>
                        <th class="px-4 py-2 text-right">Facturé</th>
                        <th class="px-4 py-2 text-right">Rejeté</th>
                        <th class="px-4 py-2 text-right">Taux de rejet</th>
                        <th class="px-4 py-2 text-right">Encaissé</th>
                        <th class="px-4 py-2 text-right">Solde</th>
                        <th class="px-4 py-2 text-right">DSO</th>
                        <th class="px-4 py-2 text-left">Dernière relance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($parAssureur as $l)
                    <tr>
                        <td class="px-4 py-2 font-medium">
                            <a href="{{ route('conventions.index', ['assurance_id' => $l['assurance']->id]) }}" class="text-blue-700 hover:underline">{{ $l['assurance']->nom }}</a>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $cdf($l['facture']) }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap {{ $l['rejete'] > 0 ? 'text-red-700' : '' }}">{{ $cdf($l['rejete']) }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            {{ $pct($l['taux_rejet_valeur']) }}
                            @if($l['factures'] > 0)
                            <span class="block text-xs text-gray-500">{{ $l['rejetees'] }} / {{ $l['factures'] }} factures</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap text-green-700">{{ $cdf($l['encaisse']) }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap font-semibold">
                            {{ $cdf($l['solde']) }}
                            @if($l['en_retard'] > 0)
                            <span class="block text-xs text-red-700 font-normal">dont {{ $cdf($l['en_retard']) }} échus</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $l['dso'] === null ? '—' : $l['dso'].' j' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600">
                            @if($l['derniere_relance'])
                            {{ $l['derniere_relance']->date_relance->format('d/m/Y') }} — {{ $l['derniere_relance']->libelleMode() }}
                            @else
                            —
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- Balance âgée --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Balance âgée
            <span class="text-gray-400 font-normal text-sm">— retard sur l'échéance (dépôt + délai du contrat)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Débiteur</th>
                        <th class="px-4 py-2 text-right">Pas encore en bordereau</th>
                        @foreach($tranches as $libelle)
                        <th class="px-4 py-2 text-right">{{ $libelle }}</th>
                        @endforeach
                        <th class="px-4 py-2 text-right">Total dû</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($balanceConventions['lignes'] as $l)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $l['assurance']->nom }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap text-amber-800">{{ $l['a_facturer'] > 0 ? $cdf($l['a_facturer']) : '—' }}</td>
                        @foreach(array_keys($tranches) as $t)
                        <td class="px-4 py-2 text-right whitespace-nowrap {{ $t === 'plus_90' && $l[$t] > 0 ? 'text-red-700 font-semibold' : '' }}">{{ $l[$t] > 0 ? $cdf($l[$t]) : '—' }}</td>
                        @endforeach
                        <td class="px-4 py-2 text-right whitespace-nowrap font-semibold">{{ $cdf($l['total']) }}</td>
                    </tr>
                    @endforeach
                    <tr class="bg-gray-50">
                        <td class="px-4 py-2 font-medium">Patients (guichet)</td>
                        <td class="px-4 py-2 text-right">—</td>
                        @foreach(array_keys($tranches) as $t)
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $balancePatients[$t] > 0 ? $cdf($balancePatients[$t]) : '—' }}</td>
                        @endforeach
                        <td class="px-4 py-2 text-right whitespace-nowrap font-semibold">{{ $cdf($balancePatients['total']) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t bg-gray-50 font-semibold">
                    <tr>
                        <td class="px-4 py-2">Total</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $cdf($balanceConventions['total']['a_facturer']) }}</td>
                        @foreach(array_keys($tranches) as $t)
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $cdf($balanceConventions['total'][$t] + $balancePatients[$t]) }}</td>
                        @endforeach
                        <td class="px-4 py-2 text-right whitespace-nowrap">{{ $cdf($balanceConventions['total']['total'] + $balancePatients['total']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2 mb-6">
        {{-- Relances à faire --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">
                Relances à faire
                <span class="text-gray-400 font-normal text-sm">— bordereaux échus, sans relance depuis {{ \App\Services\RecouvrementService::RELANCE_TOUS_LES }} jours</span>
            </div>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse($relancesAFaire as $r)
                <li class="px-5 py-2 flex flex-wrap items-center justify-between gap-2">
                    <span>
                        <a href="{{ route('conventions.show', $r['bordereau']) }}" class="text-blue-700 hover:underline font-medium">{{ $r['bordereau']->numero }}</a>
                        — {{ $r['assurance']?->nom }}
                        <span class="block text-xs text-gray-500">
                            échu depuis {{ $r['retard'] }} j · reste {{ $cdf($r['reste_cdf']) }}
                            · {{ $r['derniere_relance'] ? 'relancé le '.$r['derniere_relance']->date_relance->format('d/m/Y') : 'jamais relancé' }}
                        </span>
                    </span>
                    <a href="{{ route('conventions.show', $r['bordereau']) }}#relances" class="text-xs border border-blue-300 text-blue-700 rounded px-2 py-1">Relancer</a>
                </li>
                @empty
                <li class="px-5 py-6 text-gray-400">Rien à relancer aujourd'hui.</li>
                @endforelse
            </ul>
        </div>

        {{-- Rejets --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">Rejets de la période, par motif</div>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse($rejetsParMotif as $m)
                <li class="px-5 py-2 flex justify-between gap-3">
                    <span>{{ $m['libelle'] }}</span>
                    <span class="whitespace-nowrap text-gray-700">{{ $m['nombre'] }} · {{ $cdf($m['montant']) }}</span>
                </li>
                @empty
                <li class="px-5 py-6 text-gray-400">Aucun rejet sur la période.</li>
                @endforelse
            </ul>
            @if($rejetsATraiter->isNotEmpty())
            <div class="px-5 py-3 border-t">
                <p class="text-sm font-semibold text-red-800 mb-1">{{ $rejetsATraiter->count() }} rejet(s) en cours de traitement</p>
                <ul class="text-xs text-gray-700 space-y-1">
                    @foreach($rejetsATraiter->take(8) as $rejet)
                    <li>
                        <a href="{{ route('conventions.show', $rejet->facture_convention_id) }}#rejets" class="text-blue-700 hover:underline">{{ $rejet->factureConvention?->numero }}</a>
                        — {{ $rejet->facture?->patient?->nom_complet }} · {{ $rejet->libelleMotif() }}
                        · {{ $rejet->libelleStatut() }} depuis le {{ $rejet->date_rejet->format('d/m/Y') }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
    </div>

    {{-- Patients --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Plus gros débiteurs au guichet</div>
        @if($topDebiteurs->isEmpty())
        <p class="px-5 py-6 text-sm text-gray-400">Aucun patient ne doit rien.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Patient</th>
                        <th class="px-4 py-2 text-right">Factures ouvertes</th>
                        <th class="px-4 py-2 text-right">Plus ancienne</th>
                        <th class="px-4 py-2 text-right">Dû</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($topDebiteurs as $d)
                    <tr>
                        <td class="px-4 py-2">
                            @if($d['patient'])
                            <a href="{{ route('patients.show', $d['patient']) }}" class="text-blue-700 hover:underline">{{ $d['patient']->nom_complet }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">{{ $d['factures'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $d['plus_ancienne'] }} j</td>
                        <td class="px-4 py-2 text-right font-semibold whitespace-nowrap">{{ $cdf($d['solde']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
