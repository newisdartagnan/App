@extends('layouts.app')
@section('title', 'Dialyse — séances du jour')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🩸 Unité de dialyse</h2>
    <p class="text-sm text-gray-500 mb-5">
        Les séances du jour. Clôturer une séance, c'est enregistrer les poids
        d'entrée et de sortie : c'est d'eux que se déduit l'eau retirée.
    </p>

    @include('dialyse._onglets')
    @include('partials._flash')

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-date" class="block text-xs font-semibold text-gray-600 mb-1">Journée</label>
            <input id="f-date" name="date" type="date" value="{{ $date }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Afficher</button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            {{ \Carbon\Carbon::parse($date)->translatedFormat('l d F Y') }}
            <span class="text-gray-400 font-normal text-sm">— {{ $seances->count() }} séance(s)</span>
        </div>

        <div class="divide-y divide-gray-100">
            @forelse($seances as $seance)
            <div class="p-5">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                    <div>
                        <p class="font-semibold text-gray-800">
                            {{ $seance->patient->nom_complet }}
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full
                                {{ $seance->estRealisee() ? 'bg-green-100 text-green-800'
                                   : ($seance->statut === 'absente' ? 'bg-gray-200 text-gray-600' : 'bg-purple-100 text-purple-900') }}">
                                {{ $seance->libelleStatut() }}
                            </span>
                        </p>
                        <p class="text-xs text-gray-500">
                            {{ $seance->date_seance->format('H:i') }} → {{ $seance->finPrevue()->format('H:i') }}
                            · {{ $seance->generateur?->nom ?? 'poste non attribué' }}
                            · {{ $seance->libelleType() }}
                            @if($seance->abord) · {{ $seance->libelleAbord() }} @endif
                            @if($seance->poids_sec_kg) · poids sec {{ $seance->poids_sec_kg + 0 }} kg @endif
                        </p>
                    </div>

                    @if(! $seance->estRealisee() && $seance->statut !== 'absente')
                    <form method="POST" action="{{ route('dialyse.absence', $seance) }}">
                        @csrf
                        <button class="text-xs text-gray-600 hover:underline">Patient absent</button>
                    </form>
                    @endif
                </div>

                @if($seance->estRealisee())
                <div class="grid md:grid-cols-4 gap-3 text-sm bg-gray-50 rounded-lg p-3">
                    <div>
                        <p class="text-xs text-gray-500">Poids</p>
                        <p class="font-medium">
                            {{ $seance->poids_avant_kg + 0 }} → {{ $seance->poids_apres_kg + 0 }} kg
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Ultrafiltration</p>
                        <p class="font-medium">{{ $seance->ultrafiltration_ml ?? '—' }} ml</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Tension entrée → sortie</p>
                        <p class="font-medium">
                            {{ $seance->ta_avant_systolique ?? '—' }}/{{ $seance->ta_avant_diastolique ?? '—' }}
                            →
                            <span class="{{ $seance->ta_apres_systolique !== null && $seance->ta_apres_systolique < 90 ? 'text-red-700' : '' }}">
                                {{ $seance->ta_apres_systolique ?? '—' }}/{{ $seance->ta_apres_diastolique ?? '—' }}
                            </span>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Écart au poids sec</p>
                        <p class="font-medium">
                            {{ $seance->ecartAuPoidsSecKg() !== null ? ($seance->ecartAuPoidsSecKg() + 0).' kg' : '—' }}
                        </p>
                    </div>
                </div>
                @php $alertes = $seance->alertes(); @endphp
                @if($alertes !== [])
                <div class="mt-2 bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-xs text-red-800">
                    @foreach($alertes as $alerte)<p>• {{ $alerte }}</p>@endforeach
                </div>
                @endif

                @elseif($seance->statut === 'planifiee')
                <details class="mt-2">
                    <summary class="cursor-pointer text-sm font-medium text-green-700 select-none">
                        ✓ Clôturer la séance
                    </summary>

                    <form method="POST" action="{{ route('dialyse.realiser', $seance) }}"
                          class="grid md:grid-cols-4 gap-3 mt-3 pt-3 border-t">
                        @csrf
                        <div>
                            <label for="pa-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Poids d'entrée (kg) <span class="text-red-500">*</span>
                            </label>
                            <input id="pa-{{ $seance->id }}" name="poids_avant_kg" type="number" step="0.1" min="10" max="250" required
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="pp-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Poids de sortie (kg) <span class="text-red-500">*</span>
                            </label>
                            <input id="pp-{{ $seance->id }}" name="poids_apres_kg" type="number" step="0.1" min="10" max="250" required
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="ps-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Poids sec (kg)
                            </label>
                            <input id="ps-{{ $seance->id }}" name="poids_sec_kg" type="number" step="0.1" min="10" max="250"
                                   value="{{ $seance->poids_sec_kg }}"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="uf-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Ultrafiltration (ml)
                            </label>
                            <input id="uf-{{ $seance->id }}" name="ultrafiltration_ml" type="number" min="0" max="10000" step="50"
                                   placeholder="calculée du poids"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>

                        <div>
                            <label for="tas-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">TA entrée (syst.)</label>
                            <input id="tas-{{ $seance->id }}" name="ta_avant_systolique" type="number" min="40" max="300"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="tad-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">TA entrée (diast.)</label>
                            <input id="tad-{{ $seance->id }}" name="ta_avant_diastolique" type="number" min="20" max="200"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="tps-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">TA sortie (syst.)</label>
                            <input id="tps-{{ $seance->id }}" name="ta_apres_systolique" type="number" min="40" max="300"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="tpd-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">TA sortie (diast.)</label>
                            <input id="tpd-{{ $seance->id }}" name="ta_apres_diastolique" type="number" min="20" max="200"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>

                        <div>
                            <label for="ab-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Abord</label>
                            <select id="ab-{{ $seance->id }}" name="abord" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                                <option value="">—</option>
                                @foreach($abords as $cle => $libelle)
                                <option value="{{ $cle }}" @selected($seance->abord === $cle)>{{ $libelle }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="anti-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Anticoagulation</label>
                            <input id="anti-{{ $seance->id }}" name="anticoagulation" maxlength="50" placeholder="HBPM, héparine, sans"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="ne-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Néphrologue</label>
                            <select id="ne-{{ $seance->id }}" name="nephrologue_id" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                                <option value="">—</option>
                                @foreach($nephrologues as $medecin)
                                <option value="{{ $medecin->id }}" @selected($seance->nephrologue_id === $medecin->id)>
                                    {{ $medecin->nom }} {{ $medecin->prenom }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="erythropoietine" value="1" class="rounded">
                                Érythropoïétine
                            </label>
                        </div>

                        <div class="md:col-span-2">
                            <label for="inc-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Incidents en séance
                            </label>
                            <input id="inc-{{ $seance->id }}" name="incidents" maxlength="1000" placeholder="Aucun"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div class="md:col-span-2">
                            <label for="obs-{{ $seance->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Observations</label>
                            <input id="obs-{{ $seance->id }}" name="observations" maxlength="2000"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>

                        <div class="md:col-span-4">
                            <button class="bg-green-700 hover:bg-green-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                                ✓ Clôturer la séance
                            </button>
                        </div>
                    </form>
                </details>
                @endif
            </div>
            @empty
            <p class="px-5 py-12 text-center text-gray-400">Aucune séance programmée ce jour.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
