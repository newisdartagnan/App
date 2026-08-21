@extends('layouts.app')
@section('title', 'Dialyse — registre')
@section('content')
<div class="max-w-full mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🩸 Unité de dialyse</h2>
    <p class="text-sm text-gray-500 mb-5">
        Registre des séances : ce qui a été retiré, sur quel poste, par quel abord.
    </p>

    @include('dialyse._onglets')
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
            ['Séances programmées', $indicateurs['planifiees'], ''],
            ['Réalisées', $indicateurs['realisees'], ''],
            ['Absences', $indicateurs['absences'], ''],
            ['Patients suivis', $indicateurs['patients'], ''],
            ['UF moyenne', $indicateurs['ultrafiltration_moyenne'] ? round($indicateurs['ultrafiltration_moyenne']) : '—',
             $indicateurs['ultrafiltration_moyenne'] ? ' ml' : ''],
        ] as [$libelle, $valeur, $suffixe])
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $valeur }}{{ $suffixe }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $libelle }}</p>
        </div>
        @endforeach
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-3 py-3">Date</th>
                        <th class="px-3 py-3">Patient</th>
                        <th class="px-3 py-3">Prise en charge</th>
                        <th class="px-3 py-3">Type</th>
                        <th class="px-3 py-3">Abord</th>
                        <th class="px-3 py-3">Générateur</th>
                        <th class="px-3 py-3 text-right">Poids</th>
                        <th class="px-3 py-3 text-right">UF</th>
                        <th class="px-3 py-3 text-center">TA sortie</th>
                        <th class="px-3 py-3">Incidents</th>
                        <th class="px-3 py-3">État</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($seances as $seance)
                    <tr class="{{ filled($seance->incidents) ? 'bg-red-50/50' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $seance->date_seance->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 font-medium">{{ $seance->patient->nom_complet }}</td>
                        <td class="px-3 py-2">{{ $seance->patient->libellePriseEnCharge() }}</td>
                        <td class="px-3 py-2">{{ $seance->libelleType() }}</td>
                        <td class="px-3 py-2">{{ $seance->libelleAbord() }}</td>
                        <td class="px-3 py-2">{{ $seance->generateur?->nom ?? '—' }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            @if($seance->poids_avant_kg !== null)
                                {{ $seance->poids_avant_kg + 0 }} → {{ $seance->poids_apres_kg + 0 }}
                            @else — @endif
                        </td>
                        <td class="px-3 py-2 text-right">{{ $seance->ultrafiltration_ml !== null ? $seance->ultrafiltration_ml.' ml' : '—' }}</td>
                        <td class="px-3 py-2 text-center {{ $seance->ta_apres_systolique !== null && $seance->ta_apres_systolique < 90 ? 'text-red-700 font-semibold' : '' }}">
                            {{ $seance->ta_apres_systolique ? $seance->ta_apres_systolique.'/'.$seance->ta_apres_diastolique : '—' }}
                        </td>
                        <td class="px-3 py-2">{{ $seance->incidents ?: '—' }}</td>
                        <td class="px-3 py-2">{{ $seance->libelleStatut() }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="px-4 py-12 text-center text-gray-400">
                        Aucune séance sur cette période.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
        @foreach(['Par générateur' => $indicateurs['par_generateur'], 'Par abord vasculaire' => $indicateurs['par_abord']] as $titre => $donnees)
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700 text-sm">{{ $titre }}</div>
            <div class="p-4 space-y-1">
                @forelse($donnees as $libelle => $nombre)
                <div class="flex justify-between text-xs">
                    <span class="text-gray-700">{{ $libelle }}</span>
                    <span class="font-semibold">{{ $nombre }}</span>
                </div>
                @empty
                <p class="text-xs text-gray-400 text-center py-3">Aucune donnée</p>
                @endforelse
            </div>
        </div>
        @endforeach
    </div>
</div>
@endsection
