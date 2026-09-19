@extends('layouts.app')
@section('title', 'Registre nutritionnel')
@section('content')
@php $unites = \App\Models\PriseEnChargeNutritionnelle::UNITES; @endphp
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <h2 class="text-2xl font-bold text-gray-800">🥣 Registre nutritionnel</h2>
        <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold">
            Canevas {{ $systeme['sigle'] }}
        </span>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Les enfants et les adultes actuellement suivis. Le canevas compte les
        entrées et les issues du mois : ce sont les admissions et les décharges
        saisies ici qui le remplissent.
    </p>

    @if(! $unitesDeLetablissement)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 text-sm text-amber-900">
        Le canevas {{ $systeme['sigle'] }} ne suit aucune unité nutritionnelle.
        Changez de système de santé depuis l'écran SNIS si cet établissement en fait tourner une.
    </div>
    @else

    @foreach($unitesDeLetablissement as $cle)
    @php $liste = $suivis->get($cle, collect()); @endphp
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b">
            <p class="font-semibold text-gray-700">
                {{ $unites[$cle]['sigle'] }}
                <span class="font-normal text-gray-400">— {{ $unites[$cle]['nom'] }}</span>
                <span class="ml-2 text-sm font-normal text-gray-500">{{ $liste->count() }} en cours</span>
            </p>
            <p class="text-xs text-gray-500 mt-0.5">{{ $unites[$cle]['pourquoi'] }}</p>
        </div>

        @if($liste->isEmpty())
        <p class="px-5 py-6 text-sm text-gray-400">Aucun suivi en cours dans cette unité.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Patient</th>
                        <th class="px-4 py-2 text-left">Dossier</th>
                        <th class="px-4 py-2 text-left">Admis le</th>
                        <th class="px-4 py-2 text-right">Jours</th>
                        <th class="px-4 py-2 text-left">Motif d'entrée</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($liste as $suivi)
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $suivi->patient?->nom_complet }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $suivi->patient?->dossier_number }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $suivi->date_admission->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-right">{{ (int) $suivi->date_admission->diffInDays(now()) }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $suivi->libelleCritere() }}</td>
                        <td class="px-4 py-2 text-right">
                            @if($suivi->patient)
                            <a href="{{ route('nutrition.patient', $suivi->patient) }}"
                               class="text-blue-700 hover:underline whitespace-nowrap">Mesurer / décharger →</a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endforeach

    @if($sortiesRecentes->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Sorties des trente derniers jours
            <span class="text-gray-400 font-normal text-sm">— ce que la relève a clos</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach($sortiesRecentes as $sortie)
                    <tr class="{{ $sortie->issue === 'deces' ? 'bg-red-50' : '' }}">
                        <td class="px-4 py-2 font-medium">{{ $sortie->patient?->nom_complet }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $sortie->sigle() }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $sortie->date_sortie?->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ $sortie->libelleIssue() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
    @endif
</div>
@endsection
