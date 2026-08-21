@extends('layouts.app')
@section('title', 'Interventions')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🏥 Bloc opératoire</h2>
    <p class="text-sm text-gray-500 mb-5">
        Ce que l'équipe a réellement fait. La clôture verse l'intervention au
        registre et la rend facturable.
    </p>

    @include('bloc._onglets')
    @include('bloc._flash')

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

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Interventions planifiées
            <span class="text-gray-400 font-normal text-sm">— {{ $interventions->count() }} à clôturer</span>
        </div>

        <div class="divide-y divide-gray-100">
            @forelse($interventions as $acte)
            <div class="p-5">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                    <div>
                        <p class="font-semibold text-gray-800">
                            {{ $acte->patient->nom_complet }}
                            @if($acte->urgence)<span class="ml-2 text-xs bg-red-600 text-white px-2 py-0.5 rounded-full">URGENT</span>@endif
                        </p>
                        <p class="text-sm text-gray-700">{{ $acte->libelle }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $acte->salle?->nom ?? 'Salle non attribuée' }}
                            · {{ $acte->date_prevue?->format('d/m/Y à H:i') }}
                            · {{ $acte->operateur ? 'Dr '.$acte->operateur->nom.' '.$acte->operateur->prenom : 'chirurgien à désigner' }}
                            @if($acte->diagnostic_preop) · {{ $acte->diagnostic_preop }} @endif
                        </p>
                    </div>
                    <a href="{{ route('bloc.feuille', $acte) }}" target="_blank"
                       class="text-xs text-blue-700 hover:underline">🖨️ Feuille d'intervention</a>
                </div>

                <details class="mt-2">
                    <summary class="cursor-pointer text-sm font-medium text-green-700 select-none">
                        ✓ Clôturer l'intervention
                    </summary>

                    <form method="POST" action="{{ route('bloc.cloturer', $acte) }}"
                          class="grid md:grid-cols-4 gap-3 mt-3 pt-3 border-t">
                        @csrf
                        <div>
                            <label for="entree-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Entrée en salle <span class="text-red-500">*</span>
                            </label>
                            <input id="entree-{{ $acte->id }}" name="heure_entree_salle" type="datetime-local" required
                                   value="{{ $acte->date_prevue?->format('Y-m-d\TH:i') }}"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="sortie-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Sortie de salle <span class="text-red-500">*</span>
                            </label>
                            <input id="sortie-{{ $acte->id }}" name="heure_sortie_salle" type="datetime-local" required
                                   value="{{ $acte->finPrevue()?->format('Y-m-d\TH:i') }}"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="ca-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Anesthésiste</label>
                            <select id="ca-{{ $acte->id }}" name="anesthesiste_id"
                                    class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                                <option value="">—</option>
                                @foreach($anesthesistes as $medecin)
                                <option value="{{ $medecin->id }}" @selected($acte->anesthesiste_id === $medecin->id)>
                                    {{ $medecin->nom }} {{ $medecin->prenom }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="cta-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Type d'anesthésie</label>
                            <select id="cta-{{ $acte->id }}" name="type_anesthesie"
                                    class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                                <option value="">—</option>
                                @foreach($anesthesies as $cle => $libelle)
                                <option value="{{ $cle }}" @selected($acte->type_anesthesie === $cle)>{{ $libelle }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="md:col-span-4">
                            <p class="block text-xs font-semibold text-gray-600 mb-1">Kits ouverts</p>
                            <div class="flex flex-wrap gap-3">
                                @foreach($kits as $kit)
                                <label class="flex items-start gap-2 text-xs text-gray-700 border border-gray-200 rounded-lg px-3 py-2">
                                    <input type="checkbox" name="kits[]" value="{{ $kit->id }}"
                                           @checked(in_array($kit->id, $acte->kits ?? [], true)) class="mt-0.5 rounded">
                                    <span>
                                        <strong>{{ $kit->libelle }}</strong>
                                        <span class="block text-gray-500">{{ $kit->libelleContenu() }}</span>
                                        <span class="block text-gray-500">{{ number_format((float) $kit->prix, 0, ',', ' ') }} CDF</span>
                                    </span>
                                </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label for="dpost-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Diagnostic postopératoire
                            </label>
                            <input id="dpost-{{ $acte->id }}" name="diagnostic_postop" maxlength="1000"
                                   value="{{ $acte->diagnostic_postop }}"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div class="md:col-span-2">
                            <label for="inc-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Incidents peropératoires
                            </label>
                            <input id="inc-{{ $acte->id }}" name="incidents" maxlength="2000"
                                   value="{{ $acte->incidents }}" placeholder="Aucun"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>

                        <div class="md:col-span-4">
                            <label for="cr-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Compte rendu opératoire <span class="text-red-500">*</span>
                            </label>
                            <textarea id="cr-{{ $acte->id }}" name="compte_rendu" rows="4" required maxlength="5000"
                                      placeholder="Voie d'abord, gestes réalisés, drainage, fermeture, suites immédiates…"
                                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">{{ $acte->compte_rendu }}</textarea>
                        </div>

                        <div class="md:col-span-4">
                            <button class="bg-green-700 hover:bg-green-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                                ✓ Clôturer et verser au registre
                            </button>
                        </div>
                    </form>
                </details>
            </div>
            @empty
            <p class="px-5 py-12 text-center text-gray-400">
                Aucune intervention planifiée à clôturer sur cette période.
            </p>
            @endforelse
        </div>
    </div>
</div>
@endsection
