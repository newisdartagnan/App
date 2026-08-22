@extends('layouts.app')
@section('title', 'L\'attente à l\'hôpital')
@section('content')
@php use App\Services\ParcoursTemporelService as PT; @endphp
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <a href="{{ route('statistiques.index') }}" class="text-blue-700 hover:underline text-sm">← Statistiques</a>
        <h2 class="text-2xl font-bold text-gray-800">⏳ L'attente à l'hôpital</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Du {{ $analyse['debut']->format('d/m/Y') }} au {{ $analyse['fin']->format('d/m/Y') }} —
        {{ $analyse['visites'] }} passages, dont {{ $analyse['mesurables'] }} avec au moins
        une attente mesurable.
        @if($analyse['plafonne'])
        <span class="text-amber-700">Analyse plafonnée aux {{ \App\Services\StatistiquesAttenteService::PLAFOND }} premiers passages.</span>
        @endif
    </p>

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-5 flex flex-wrap gap-3 items-end">
        <div>
            <label for="a-debut" class="block text-xs font-semibold text-gray-600 mb-1">Du</label>
            <input id="a-debut" name="debut" type="date" value="{{ $debut }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="a-fin" class="block text-xs font-semibold text-gray-600 mb-1">Au</label>
            <input id="a-fin" name="fin" type="date" value="{{ $fin }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="a-type" class="block text-xs font-semibold text-gray-600 mb-1">Type de passage</label>
            <select id="a-type" name="type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Tous</option>
                @foreach(['consultation_externe' => 'Ambulatoire', 'urgence' => 'Urgences', 'hospitalisation' => 'Hospitalisation'] as $cle => $libelle)
                <option value="{{ $cle }}" @selected($type === $cle)>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Analyser</button>
    </form>

    @if($analyse['global']['nombre'] === 0)
    <div class="bg-white rounded-xl shadow px-5 py-12 text-center text-gray-500">
        Aucune attente mesurable sur cette période. Une attente ne se mesure
        qu'entre deux étapes datées : tant qu'un passage n'a qu'une seule
        heure, il n'y a rien à compter.
    </div>
    @else

    {{-- L'essentiel --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ PT::duree($analyse['global']['mediane']) }}</p>
            <p class="text-xs text-gray-500 mt-1">Attente médiane — ce que vit le patient ordinaire</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold {{ $analyse['global']['moyenne'] > $analyse['global']['mediane'] * 2 ? 'text-amber-700' : 'text-gray-800' }}">
                {{ PT::duree($analyse['global']['moyenne']) }}
            </p>
            <p class="text-xs text-gray-500 mt-1">
                Moyenne
                @if($analyse['global']['moyenne'] > $analyse['global']['mediane'] * 2)
                — tirée par quelques cas extrêmes
                @endif
            </p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-red-700">{{ PT::duree($analyse['global']['pire']) }}</p>
            <p class="text-xs text-gray-500 mt-1">La plus longue de la période</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ PT::duree($analyse['total_attente']) }}</p>
            <p class="text-xs text-gray-500 mt-1">Temps d'attente cumulé, tous patients</p>
        </div>
    </div>

    {{-- Les créneaux à renforcer : la réponse cherchée --}}
    @if($analyse['creneaux_noirs']->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Créneaux à renforcer
            <span class="text-gray-400 font-normal text-sm">
                — poste, jour et heure où l'attente s'accumule
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-2">Poste</th>
                        <th class="px-4 py-2">Quand</th>
                        <th class="px-4 py-2 text-right">Patients</th>
                        <th class="px-4 py-2 text-right">Attente médiane</th>
                        <th class="px-4 py-2 text-right">Attente cumulée</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($analyse['creneaux_noirs'] as $creneau)
                    <tr class="{{ $loop->first ? 'bg-red-50/60' : '' }}">
                        <td class="px-4 py-2 font-medium">{{ $creneau['poste'] }}</td>
                        <td class="px-4 py-2">{{ $creneau['jour'] }} vers {{ $creneau['heure'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $creneau['patients'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ PT::duree($creneau['mediane']) }}</td>
                        <td class="px-4 py-2 text-right text-red-700">{{ PT::duree($creneau['total']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="px-5 py-2 text-xs text-gray-500 border-t">
            Un créneau n'apparaît qu'à partir de deux patients : deux personnes
            qui attendent dix minutes ne font pas un problème, huit qui en
            attendent quarante, si.
        </p>
    </div>
    @endif

    {{-- Par poste --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Où l'on attend</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-2">Poste</th>
                        <th class="px-4 py-2 text-right">Attentes</th>
                        <th class="px-4 py-2 text-right">Médiane</th>
                        <th class="px-4 py-2 text-right">Moyenne</th>
                        <th class="px-4 py-2 text-right">Pire cas</th>
                        <th class="px-4 py-2 w-1/4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($analyse['par_poste'] as $ligne)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $ligne['libelle'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $ligne['nombre'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ PT::duree($ligne['mediane']) }}</td>
                        <td class="px-4 py-2 text-right text-gray-600">{{ PT::duree($ligne['moyenne']) }}</td>
                        <td class="px-4 py-2 text-right text-red-700">{{ PT::duree($ligne['pire']) }}</td>
                        <td class="px-4 py-2">
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-2 bg-amber-500 rounded-full"
                                     style="width: {{ $analyse['total_attente'] > 0 ? round($ligne['total'] * 100 / $analyse['total_attente']) : 0 }}%"></div>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-5 mb-5">
        {{-- Par jour de la semaine --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">Quel jour de la semaine</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach($analyse['par_jour_semaine'] as $ligne)
                    <tr>
                        <td class="px-4 py-2 font-medium w-28">{{ $ligne['libelle'] }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500 w-20">{{ $ligne['nombre'] }} attentes</td>
                        <td class="px-4 py-2">
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-2 bg-blue-600 rounded-full"
                                     style="width: {{ $analyse['par_jour_semaine']->max('mediane') > 0 ? round($ligne['mediane'] * 100 / $analyse['par_jour_semaine']->max('mediane')) : 0 }}%"></div>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-right font-semibold w-20">{{ PT::duree($ligne['mediane']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Par heure d'arrivée --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">À quelle heure</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach($analyse['par_heure'] as $ligne)
                    <tr>
                        <td class="px-4 py-2 font-medium w-16">{{ $ligne['libelle'] }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500 w-20">{{ $ligne['nombre'] }} attentes</td>
                        <td class="px-4 py-2">
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-2 bg-blue-600 rounded-full"
                                     style="width: {{ $analyse['par_heure']->max('mediane') > 0 ? round($ligne['mediane'] * 100 / $analyse['par_heure']->max('mediane')) : 0 }}%"></div>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-right font-semibold w-20">{{ PT::duree($ligne['mediane']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Les cas extrêmes, nommés --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Les dix plus longues attentes
            <span class="text-gray-400 font-normal text-sm">— celles qu'il faut aller regarder une par une</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-2">Patient</th>
                        <th class="px-4 py-2">Poste</th>
                        <th class="px-4 py-2">Quand</th>
                        <th class="px-4 py-2 text-right">Durée</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($analyse['pires'] as $pire)
                    <tr>
                        <td class="px-4 py-2">
                            {{ $pire['patient']?->nom_complet ?? 'Patient inconnu' }}
                            <span class="text-xs text-gray-400">{{ $pire['patient']?->dossier_number }}</span>
                        </td>
                        <td class="px-4 py-2">{{ $pire['poste'] }}</td>
                        <td class="px-4 py-2 text-xs">{{ $pire['quand']->format('d/m/Y à H:i') }}</td>
                        <td class="px-4 py-2 text-right font-semibold text-red-700">{{ PT::duree($pire['minutes']) }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('parcours.chronologie', $pire['visite']) }}"
                               class="text-xs text-blue-700 hover:underline">Chronologie →</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-gray-500 mt-3">
        Une attente n'est comptée que lorsqu'elle est encadrée par deux heures
        connues, et elle n'est imputée à personne : c'est du temps où le
        patient n'était avec aucun soignant.
    </p>
    @endif
</div>
@endsection
