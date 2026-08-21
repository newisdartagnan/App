@extends('layouts.app')
@section('title', 'Programme préopératoire')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🏥 Bloc opératoire</h2>
    <p class="text-sm text-gray-500 mb-5">
        Le chirurgien demande, le bloc planifie une salle et un créneau, l'équipe
        opère et clôture. Une salle déjà occupée à cette heure-là est refusée.
    </p>

    @include('bloc._onglets')
    @include('bloc._flash')

    {{-- Filtres --}}
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
        <div>
            <label for="f-vue" class="block text-xs font-semibold text-gray-600 mb-1">Afficher</label>
            <select id="f-vue" name="vue" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="preoperatoire" @selected($vue === 'preoperatoire')>Demandes à planifier</option>
                <option value="planifiees" @selected($vue === 'planifiees')>Interventions planifiées</option>
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700 pb-2">
            <input type="checkbox" name="mes_demandes" value="1" @checked($mesDemandes) class="rounded">
            Mes demandes seulement
        </label>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Appliquer</button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            {{ $vue === 'planifiees' ? 'Programme planifié' : 'Programme préopératoire' }}
            <span class="text-gray-400 font-normal text-sm">— {{ $interventions->count() }} intervention(s)</span>
        </div>

        <div class="divide-y divide-gray-100">
            @forelse($interventions as $acte)
            <div class="p-5">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-2">
                    <div>
                        <p class="font-semibold text-gray-800">
                            {{ $acte->patient->nom_complet }}
                            @if($acte->urgence)<span class="ml-2 text-xs bg-red-600 text-white px-2 py-0.5 rounded-full">URGENT</span>@endif
                            @unless($acte->consentement)
                            <span class="ml-1 text-xs bg-amber-100 text-amber-900 px-2 py-0.5 rounded-full">consentement à recueillir</span>
                            @endunless
                        </p>
                        <p class="text-sm text-gray-700">{{ $acte->libelle }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $acte->visit?->service?->nom ?? 'Service non précisé' }}
                            @if($acte->diagnostic_preop) · {{ $acte->diagnostic_preop }} @endif
                            · demandé par {{ $acte->demandeur?->nom_complet ?? $acte->prescripteur?->nom_complet ?? '—' }}
                        </p>
                    </div>
                    <div class="text-right text-sm">
                        @if($acte->date_prevue)
                        <p class="font-semibold text-blue-800">{{ $acte->date_prevue->format('d/m/Y à H:i') }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $acte->duree_minutes ?: 60 }} min
                            @if($acte->salle) · {{ $acte->salle->nom }} @endif
                        </p>
                        @else
                        <p class="text-xs text-amber-700 font-semibold">Sans date d'échéance</p>
                        @endif
                        @if($acte->operateur)
                        <p class="text-xs text-gray-500">Dr {{ $acte->operateur->nom }} {{ $acte->operateur->prenom }}</p>
                        @endif
                        <a href="{{ route('bloc.feuille', $acte) }}" target="_blank"
                           class="text-xs text-blue-700 hover:underline">🖨️ Feuille d'intervention</a>
                    </div>
                </div>

                <details class="mt-2" {{ $errors->any() ? 'open' : '' }}>
                    <summary class="cursor-pointer text-sm font-medium text-blue-700 select-none">
                        {{ $acte->estPlanifiee() ? '🔁 Replanifier' : '📅 Planifier cette intervention' }}
                    </summary>

                    <form method="POST" action="{{ route('bloc.planifier', $acte) }}"
                          class="grid md:grid-cols-4 gap-3 mt-3 pt-3 border-t">
                        @csrf
                        <div>
                            <label for="salle-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Salle d'opération <span class="text-red-500">*</span>
                            </label>
                            <select id="salle-{{ $acte->id }}" name="salle_id" required
                                    class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                                <option value="">—</option>
                                @foreach($salles as $salle)
                                <option value="{{ $salle->id }}" @selected($acte->salle_id === $salle->id)>
                                    {{ $salle->nom }}@if($salle->specialite) — {{ $salle->specialite }}@endif
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="date-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Date et heure <span class="text-red-500">*</span>
                            </label>
                            <input id="date-{{ $acte->id }}" name="date_prevue" type="datetime-local" required
                                   value="{{ $acte->date_prevue?->format('Y-m-d\TH:i') }}"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="duree-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Durée (min) <span class="text-red-500">*</span>
                            </label>
                            <input id="duree-{{ $acte->id }}" name="duree_minutes" type="number" min="15" max="1440" step="15" required
                                   value="{{ $acte->duree_minutes ?: 60 }}"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label for="chir-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Chirurgien <span class="text-red-500">*</span>
                            </label>
                            <select id="chir-{{ $acte->id }}" name="operateur_id" required
                                    class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                                <option value="">—</option>
                                @foreach($chirurgiens as $medecin)
                                <option value="{{ $medecin->id }}" @selected($acte->operateur_id === $medecin->id)>
                                    {{ $medecin->nom }} {{ $medecin->prenom }}
                                </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="anesth-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Anesthésiste</label>
                            <select id="anesth-{{ $acte->id }}" name="anesthesiste_id"
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
                            <label for="ta-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Type d'anesthésie</label>
                            <select id="ta-{{ $acte->id }}" name="type_anesthesie"
                                    class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                                <option value="">—</option>
                                @foreach($anesthesies as $cle => $libelle)
                                <option value="{{ $cle }}" @selected($acte->type_anesthesie === $cle)>{{ $libelle }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="instr-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Instrumentiste</label>
                            <input id="instr-{{ $acte->id }}" name="instrumentiste" maxlength="150"
                                   value="{{ $acte->instrumentiste }}"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>
                        <div class="flex flex-col justify-end gap-1 pb-1">
                            <label class="flex items-center gap-2 text-xs text-gray-700">
                                <input type="checkbox" name="consentement" value="1" @checked($acte->consentement) class="rounded">
                                Consentement signé
                            </label>
                            <label class="flex items-center gap-2 text-xs text-gray-700">
                                <input type="checkbox" name="urgence" value="1" @checked($acte->urgence) class="rounded">
                                Urgence
                            </label>
                        </div>

                        <div class="md:col-span-4">
                            <label for="dpre-{{ $acte->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Diagnostic préopératoire
                            </label>
                            <input id="dpre-{{ $acte->id }}" name="diagnostic_preop" maxlength="1000"
                                   value="{{ $acte->diagnostic_preop }}" placeholder="Bassin rétréci, hernie inguinale droite…"
                                   class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        </div>

                        <div class="md:col-span-4">
                            <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                                {{ $acte->estPlanifiee() ? 'Replanifier' : 'Inscrire au programme' }}
                            </button>
                        </div>
                    </form>
                </details>
            </div>
            @empty
            <p class="px-5 py-12 text-center text-gray-400">
                {{ $vue === 'planifiees'
                    ? 'Aucune intervention planifiée sur cette période.'
                    : 'Aucune demande en attente de planification sur cette période.' }}
            </p>
            @endforelse
        </div>
    </div>

    <p class="text-xs text-gray-500 mt-3">
        Une demande d'intervention se crée depuis le dossier du patient, à
        l'onglet des actes cliniques.
    </p>
</div>
@endsection
