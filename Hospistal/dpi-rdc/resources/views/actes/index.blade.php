@extends('layouts.app')
@php
    $titre = match($domaine) {
        'maternite' => 'Maternité',
        'examen_specialise' => 'Examens spécialisés',
        'dialyse' => 'Dialyse / Néphrologie',
        default => 'Bloc opératoire',
    };
    $routeCreate = match($domaine) {
        'maternite' => 'maternite.create',
        'examen_specialise' => 'examens-specialises.create',
        'dialyse' => 'dialyse.create',
        default => 'bloc.create',
    };
    $icone = match($domaine) {
        'maternite' => '👶',
        'examen_specialise' => '🩺',
        'dialyse' => '🩸',
        default => '🏥',
    };
    // Chirurgie et maternité passent par le bloc opératoire : leur
    // programmation demande une salle, que cet écran ne connaît pas.
    $passeParLeBloc = in_array($domaine, ['chirurgie', 'maternite'], true);
    $operateurLibelle = match($domaine) {
        'maternite' => 'Accoucheur',
        'dialyse' => 'Néphrologue',
        default => 'Chirurgien',
    };
@endphp
@section('title', $titre)
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    @if($domaine === 'chirurgie')
        @include('bloc._onglets')
    @elseif($domaine === 'maternite')
        @include('maternite._onglets')
    @elseif($domaine === 'dialyse')
        @include('dialyse._onglets')
    @endif

    <div class="flex items-center justify-between mb-1 flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-800">
            {{ $icone }} {{ $titre }} — actes
        </h2>
        @if($passeParLeBloc)
        <div class="flex flex-wrap gap-3 text-sm">
            <a href="{{ route('bloc.programme') }}" class="text-blue-700 hover:underline">Programme préopératoire →</a>
            <a href="{{ route('bloc.horaire') }}" class="text-blue-700 hover:underline">Horaire des salles</a>
            <a href="{{ route('bloc.registre') }}" class="text-blue-700 hover:underline">Registre</a>
        </div>
        @endif
    </div>
    <p class="text-sm text-gray-500 mb-5">
        @if($passeParLeBloc)
            Les demandes se programment au bloc : salle, créneau, chirurgien et
            anesthésiste, puis compte rendu et registre.
        @else
            Les demandes se programment ici : date, opérateur et durée, puis compte rendu.
        @endif
    </p>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    {{-- Demande d'acte : le point de départ du circuit --}}
    <details class="bg-white rounded-xl shadow mb-6" {{ $errors->any() ? 'open' : '' }}>
        <summary class="px-5 py-3 font-semibold text-gray-700 cursor-pointer select-none">
            ➕ Nouvelle demande d'acte
        </summary>
        <div class="px-5 pb-5 border-t pt-4">
            @include('actes._formulaire-demande', ['visit' => null])
        </div>
    </details>

    {{-- Compteurs --}}
    <div class="grid grid-cols-3 gap-3 mb-6">
        @foreach([
            ['À programmer', $programme['demandes']->count(), 'text-amber-600'],
            ['Programmés', $programme['planifies']->count(), 'text-blue-700'],
            ['Réalisés', $programme['realises']->count(), 'text-green-700'],
        ] as [$libelle, $valeur, $couleur])
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold {{ $couleur }}">{{ $valeur }}</p>
            <p class="text-xs text-gray-500">{{ $libelle }}</p>
        </div>
        @endforeach
    </div>

    {{-- ── Demandes à programmer ─────────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b bg-amber-50 font-semibold text-amber-900">
            ⏳ Demandes {{ $domaine === 'chirurgie' ? 'préopératoires' : '' }} à programmer
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($programme['demandes'] as $acte)
            <div class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm mb-2">
                    <span class="font-semibold text-gray-800">{{ $acte->patient->nom_complet }}</span>
                    <span class="text-gray-500 text-xs">{{ $acte->patient->dossier_number }} · {{ $acte->patient->sexe }} {{ $acte->patient->date_naissance?->age }} ans</span>
                    <span class="text-blue-800">{{ $acte->libelle }}</span>
                    <span class="text-gray-500 text-xs">Demandé par {{ $acte->prescripteur ? 'Dr ' . $acte->prescripteur->nom : '—' }}</span>
                    <span class="ml-auto font-semibold text-gray-700">{{ number_format($acte->montantTotal(), 0, ',', ' ') }} CDF</span>
                </div>
                @if($passeParLeBloc)
                {{-- La salle est obligatoire : la programmation se fait au bloc,
                     qui seul sait quels créneaux sont libres. --}}
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-900 flex flex-wrap gap-3 items-center">
                    <span>À programmer : salle, créneau, chirurgien et anesthésiste.</span>
                    <a href="{{ route('bloc.programme') }}" class="underline font-semibold">Programmer au bloc →</a>
                    @unless($acte->facture_id)
                    <form method="POST" action="{{ route('actes.facturer', $acte) }}" class="inline">
                        @csrf
                        <button class="underline">Facturer au guichet</button>
                    </form>
                    @endunless
                </div>
                @else
                <form method="POST" action="{{ route('actes.planifier', $acte) }}" class="flex flex-wrap gap-2 items-end bg-gray-50 rounded-lg p-3">
                    @csrf
                    <div>
                        <label for="dp-{{ $acte->id }}" class="block text-[11px] text-gray-500 mb-0.5">Date d'échéance</label>
                        <input id="dp-{{ $acte->id }}" type="datetime-local" name="date_prevue" required class="border border-gray-300 rounded px-2 py-1 text-sm">
                    </div>
                    <div>
                        <label for="op-{{ $acte->id }}" class="block text-[11px] text-gray-500 mb-0.5">{{ $operateurLibelle }}</label>
                        <select id="op-{{ $acte->id }}" name="operateur_id" class="border border-gray-300 rounded px-2 py-1 text-sm">
                            <option value="">— À désigner —</option>
                            @foreach($operateurs as $op)
                            <option value="{{ $op->id }}">{{ trim($op->prenom . ' ' . $op->nom) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="du-{{ $acte->id }}" class="block text-[11px] text-gray-500 mb-0.5">Durée (min)</label>
                        <input id="du-{{ $acte->id }}" type="number" name="duree_minutes" min="5" max="1440" value="60" class="border border-gray-300 rounded px-2 py-1 text-sm w-24">
                    </div>
                    <div class="flex-1 min-w-40">
                        <label for="in-{{ $acte->id }}" class="block text-[11px] text-gray-500 mb-0.5">Indication</label>
                        <input id="in-{{ $acte->id }}" name="indication" placeholder="Diagnostic / indication" class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                    </div>
                    <label class="flex items-center gap-1.5 text-xs text-gray-700 pb-1.5">
                        <input type="checkbox" name="consentement" value="1" class="rounded"> Consentement
                    </label>
                    <label class="flex items-center gap-1.5 text-xs text-red-700 pb-1.5">
                        <input type="checkbox" name="urgence" value="1" class="rounded"> Urgent
                    </label>
                    <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-4 py-1.5 rounded-lg font-semibold">Programmer</button>
                </form>
                @endif
            </div>
            @empty
            <p class="px-4 py-8 text-center text-sm text-gray-400">Aucune demande en attente de programmation</p>
            @endforelse
        </div>
    </div>

    {{-- ── Programme planifié ────────────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b bg-blue-50 font-semibold text-blue-900">📅 Programme {{ $domaine === 'chirurgie' ? 'opératoire' : '' }} planifié</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left">Date prévue</th>
                        <th class="px-3 py-2 text-left">Patient</th>
                        <th class="px-3 py-2 text-left">Intervention</th>
                        <th class="px-3 py-2 text-left">Indication</th>
                        <th class="px-3 py-2 text-left">{{ $operateurLibelle }}</th>
                        <th class="px-3 py-2 text-center">Durée</th>
                        <th class="px-3 py-2 text-center">Consent.</th>
                        <th class="px-3 py-2 text-center">Urgent</th>
                        <th class="px-3 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($programme['planifies'] as $acte)
                    <tr class="{{ $acte->urgence ? 'bg-red-50' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap font-semibold">
                            {{-- Un acte marqué programmé sans date reste possible
                                 (reprise d'anciennes données) : on le dit plutôt
                                 que de laisser la page tomber. --}}
                            {{ $acte->date_prevue?->format('d/m/Y H:i') ?? 'date à fixer' }}
                        </td>
                        <td class="px-3 py-2">
                            {{ $acte->patient->nom_complet }}
                            <span class="block text-xs text-gray-400">{{ $acte->patient->sexe }} {{ $acte->patient->date_naissance?->age }} ans · {{ $acte->visit?->service?->nom ?? 'Ambulatoire' }}</span>
                        </td>
                        <td class="px-3 py-2">{{ $acte->libelle }}</td>
                        <td class="px-3 py-2 text-xs text-gray-600">{{ $acte->indication ?: '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $acte->operateur ? 'Dr ' . trim($acte->operateur->prenom . ' ' . $acte->operateur->nom) : '— à désigner' }}</td>
                        <td class="px-3 py-2 text-center text-xs">{{ $acte->duree_minutes ? $acte->duree_minutes . ' min' : '—' }}</td>
                        <td class="px-3 py-2 text-center">{{ $acte->consentement ? '✅' : '⛔' }}</td>
                        <td class="px-3 py-2 text-center">{{ $acte->urgence ? '🚨' : '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            <details class="inline-block text-left">
                                <summary class="text-blue-700 text-xs cursor-pointer hover:underline">Clôturer</summary>
                                <form method="POST" action="{{ route('actes.realiser', $acte) }}" class="absolute right-4 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg p-3 w-80 z-10">
                                    @csrf
                                    <label for="cr-{{ $acte->id }}" class="block text-xs text-gray-600 mb-1">Compte-rendu opératoire</label>
                                    <textarea id="cr-{{ $acte->id }}" name="compte_rendu" rows="4" required minlength="10"
                                        class="w-full border border-gray-300 rounded px-2 py-1 text-sm mb-2"></textarea>
                                    <button class="bg-green-700 text-white text-xs px-3 py-1.5 rounded-lg w-full">Enregistrer &amp; clôturer</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-gray-400">Aucun acte au programme</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Registre des actes réalisés ───────────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b bg-green-50 font-semibold text-green-900">📗 Registre des actes réalisés</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-left">Patient</th>
                        <th class="px-3 py-2 text-left">Acte</th>
                        <th class="px-3 py-2 text-left">Opérateur</th>
                        <th class="px-3 py-2 text-left">Compte-rendu</th>
                        <th class="px-3 py-2 text-right">Montant</th>
                        <th class="px-3 py-2 text-right">Facture</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($programme['realises'] as $acte)
                    <tr>
                        <td class="px-3 py-2 whitespace-nowrap text-xs">{{ $acte->date_realisation?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            {{ $acte->patient->nom_complet }}
                            <span class="block text-xs text-gray-400">{{ $acte->patient->dossier_number }}</span>
                        </td>
                        <td class="px-3 py-2">{{ $acte->libelle }}</td>
                        <td class="px-3 py-2 text-xs">{{ $acte->operateur ? 'Dr ' . $acte->operateur->nom : ($acte->prescripteur ? 'Dr ' . $acte->prescripteur->nom : '—') }}</td>
                        <td class="px-3 py-2 text-xs text-gray-600 max-w-xs truncate" title="{{ $acte->compte_rendu }}">{{ $acte->compte_rendu ?: '—' }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format($acte->montantTotal(), 0, ',', ' ') }} CDF</td>
                        <td class="px-3 py-2 text-right">
                            @if($acte->facture_id)
                            <a href="{{ route('caisse.show', $acte->facture_id) }}" class="text-blue-700 text-xs hover:underline">Voir →</a>
                            @else
                            <form method="POST" action="{{ route('actes.facturer', $acte) }}" class="inline">@csrf<button class="text-amber-700 text-xs hover:underline">Facturer</button></form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">Aucun acte réalisé</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
