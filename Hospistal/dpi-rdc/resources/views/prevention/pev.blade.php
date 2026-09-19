@extends('layouts.app')
@section('title', 'Vaccination (PEV)')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <h2 class="text-2xl font-bold text-gray-800">💉 Vaccination — PEV</h2>
        <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold">
            Canevas {{ $systeme['sigle'] }}
        </span>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Les doses administrées depuis le 1er {{ $depuis->translatedFormat('F Y') }}.
        Chaque dose est enregistrée sur un enfant nommé : le carnet se
        reconstitue quand la mère l'a perdu, et on voit lesquels décrochent
        entre la première et la troisième dose.
    </p>

    @if(! $produite)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 mb-5 text-sm text-amber-900">
        Le canevas {{ $systeme['sigle'] }} ne remonte pas la vaccination — c'est
        une section du centre de santé. Les doses saisies ici restent
        enregistrées dans le carnet de l'enfant, mais elles n'apparaîtront pas
        dans le rapport mensuel de cet établissement.
    </div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Doses du mois
            <span class="text-gray-400 font-normal text-sm">— {{ $doses->count() }} administrée(s)</span>
        </div>

        @if($doses->isEmpty())
        <p class="px-5 py-8 text-sm text-gray-400">
            Aucune dose enregistrée ce mois-ci. Les doses se saisissent depuis
            l'écran de prévention de l'enfant, accessible de sa fiche.
        </p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Enfant</th>
                        <th class="px-4 py-2 text-left">Antigène</th>
                        <th class="px-4 py-2 text-left">Stratégie</th>
                        <th class="px-4 py-2 text-left">Lot</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($doses as $dose)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $dose->date_vaccination->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 font-medium">{{ $dose->patient?->nom_complet }}</td>
                        <td class="px-4 py-2">{{ $dose->libelleAntigene() }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $dose->libelleStrategie() }}</td>
                        <td class="px-4 py-2 text-gray-500 text-xs">{{ $dose->lot ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if($dose->patient)
                            <a href="{{ route('prevention.patient', $dose->patient) }}"
                               class="text-blue-700 hover:underline whitespace-nowrap">Carnet →</a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
