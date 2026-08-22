@extends('layouts.app')
@section('title', 'Agenda')
@section('content')
@php $jourCarbon = \Carbon\Carbon::parse($jour); @endphp
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-4 flex-wrap">
        <h2 class="text-2xl font-bold text-gray-800">📅 Agenda des rendez-vous</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
            {{ $jourCarbon->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
        </span>
    </div>

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

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <a href="{{ route('agenda.index', ['jour' => $jourCarbon->copy()->subDay()->toDateString(), 'prestataire_id' => $prestataire?->id]) }}"
           class="px-3 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Veille</a>
        <div>
            <label for="jour" class="block text-xs text-gray-500 mb-1">Jour</label>
            <input id="jour" type="date" name="jour" value="{{ $jour }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="flex-1 min-w-48">
            <label for="prestataire_id" class="block text-xs text-gray-500 mb-1">Prestataire</label>
            <select id="prestataire_id" name="prestataire_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                @foreach($prestataires as $p)
                <option value="{{ $p->id }}" @selected($prestataire?->id === $p->id)>
                    Dr {{ trim($p->prenom . ' ' . $p->nom) }}
                </option>
                @endforeach
            </select>
        </div>
        <button class="px-4 py-2 bg-blue-700 text-white rounded-lg text-sm font-semibold">Afficher</button>
        <a href="{{ route('agenda.index', ['jour' => $jourCarbon->copy()->addDay()->toDateString(), 'prestataire_id' => $prestataire?->id]) }}"
           class="px-3 py-2 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Lendemain →</a>
        <a href="{{ route('agenda.index', ['jour' => now()->toDateString(), 'prestataire_id' => $prestataire?->id]) }}"
           class="px-3 py-2 border border-blue-300 text-blue-700 rounded-lg text-sm hover:bg-blue-50">Aujourd'hui</a>
    </form>

    @if(! $prestataire)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-8 text-center text-amber-800">
        Aucun prestataire disponible — affectez le rôle « médecin » à au moins un utilisateur.
    </div>
    @else

    <div class="grid lg:grid-cols-3 gap-4">
        {{-- ── Journée du prestataire ───────────────────────────── --}}
        <div class="lg:col-span-2 bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">
                Journée de Dr {{ trim($prestataire->prenom . ' ' . $prestataire->nom) }}
                <span class="text-xs font-normal text-gray-400">— {{ $rendezVous->count() }} entrée(s)</span>
            </div>
            <div class="divide-y divide-gray-100 max-h-[30rem] overflow-y-auto">
                @forelse($rendezVous as $rv)
                <div class="px-4 py-3 flex items-start gap-3 {{ $rv->statut === 'annule' ? 'opacity-50' : '' }}
                    {{ $rv->estBloque() ? 'bg-gray-50' : '' }}">
                    <div class="text-center shrink-0 w-16">
                        <p class="font-bold text-blue-900">{{ $rv->debut->format('H:i') }}</p>
                        <p class="text-[10px] text-gray-400">{{ $rv->duree_minutes }} min</p>
                    </div>
                    <div class="flex-1 min-w-0">
                        @if($rv->estBloque())
                        <p class="font-semibold text-gray-600">🚫 {{ $rv->motif ?: 'Créneau bloqué' }}</p>
                        @else
                        <p class="font-semibold text-gray-900">{{ $rv->patient?->nom_complet ?? '—' }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $rv->typeConsultation?->libelle ?? 'Consultation' }}
                            @if($rv->contact) · ☎ {{ $rv->contact }}@endif
                            @if($rv->motif) · {{ $rv->motif }}@endif
                        </p>
                        @endif
                    </div>
                    <div class="text-right shrink-0">
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded
                            {{ match($rv->statut) {
                                'honore' => 'bg-green-100 text-green-800',
                                'annule' => 'bg-gray-100 text-gray-500',
                                'absent' => 'bg-red-100 text-red-800',
                                'bloque' => 'bg-gray-200 text-gray-700',
                                default => 'bg-blue-100 text-blue-800',
                            } }}">{{ $rv->libelleStatut() }}</span>

                        @if($rv->estBloque())
                        <form method="POST" action="{{ route('agenda.destroy', $rv) }}" class="mt-1">
                            @csrf @method('DELETE')
                            <button class="text-[11px] text-blue-700 hover:underline">Débloquer</button>
                        </form>
                        @else
                        {{-- Un rendez-vous que le patient ne repart pas avec
                             sur un papier est un rendez-vous oublié. Le bouton
                             doit se voir depuis l'autre bout du guichet. --}}
                        <a href="{{ route('agenda.convocation', $rv) }}" target="_blank"
                           title="Ouvre le papier à remettre au patient, prêt à imprimer"
                           class="mt-1 inline-flex items-center gap-1 bg-blue-700 hover:bg-blue-800 text-white
                                  text-xs font-semibold rounded-lg px-3 py-2 min-h-[36px]">
                            🖨️ Imprimer
                        </a>
                        @endif

                        @if($rv->statut === 'fixe')
                        <div class="flex gap-1 mt-1 justify-end">
                            @foreach(['honore' => 'Honoré', 'absent' => 'Absent', 'annule' => 'Annuler'] as $statut => $libelle)
                            <form method="POST" action="{{ route('agenda.statut', $rv) }}">
                                @csrf
                                <input type="hidden" name="statut" value="{{ $statut }}">
                                <button class="text-[11px] border border-gray-300 rounded px-1.5 py-0.5 hover:bg-gray-50">{{ $libelle }}</button>
                            </form>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
                @empty
                <p class="px-4 py-12 text-center text-sm text-gray-400">Aucun rendez-vous ce jour</p>
                @endforelse
            </div>
        </div>

        {{-- ── Créneaux libres ──────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">
                Créneaux libres
                <span class="text-xs font-normal text-gray-400">— {{ count($creneauxLibres) }}</span>
            </div>
            <div class="p-4 max-h-72 overflow-y-auto">
                @if(count($creneauxLibres) === 0)
                <p class="text-xs text-gray-400 text-center py-6">
                    Aucun créneau libre — journée complète, passée, ou hors amplitude
                    ({{ \App\Services\AgendaService::HEURE_OUVERTURE }} h – {{ \App\Services\AgendaService::HEURE_FERMETURE }} h).
                </p>
                @else
                <div class="grid grid-cols-3 gap-1.5">
                    @foreach($creneauxLibres as $creneau)
                    <span class="text-center text-xs border border-green-300 bg-green-50 text-green-800 rounded py-1.5 font-semibold">
                        {{ $creneau->format('H:i') }}
                    </span>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Fixer un rendez-vous ─────────────────────────────────── --}}
    <div class="grid md:grid-cols-2 gap-4 mt-4">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Fixer un rendez-vous</div>
            <form method="POST" action="{{ route('agenda.store') }}" class="p-4 space-y-3">
                @csrf
                <input type="hidden" name="prestataire_id" value="{{ $prestataire->id }}">
                <div>
                    <label for="rv-dossier" class="block text-xs text-gray-500 mb-1">Patient (n° de dossier)</label>
                    <input id="rv-dossier" name="dossier_number" required list="liste-dossiers"
                        value="{{ old('dossier_number') }}" placeholder="PAT-2026-000001"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <datalist id="liste-dossiers">
                        @foreach($patientsRecents as $pat)
                        <option value="{{ $pat->dossier_number }}">{{ $pat->nom_complet }}</option>
                        @endforeach
                    </datalist>
                    <p class="text-[11px] text-gray-400 mt-1">Saisissez le numéro, ou choisissez parmi les patients récents.</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rv-debut" class="block text-xs text-gray-500 mb-1">Date et heure</label>
                        <input id="rv-debut" type="datetime-local" name="debut" required
                            value="{{ old('debut', $jourCarbon->copy()->setTime(9, 0)->format('Y-m-d\TH:i')) }}"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="rv-duree" class="block text-xs text-gray-500 mb-1">Durée (min)</label>
                        <input id="rv-duree" type="number" name="duree_minutes" min="10" max="480" step="5" value="30"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label for="rv-type" class="block text-xs text-gray-500 mb-1">Type de consultation</label>
                    <select id="rv-type" name="type_consultation_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">— Non précisé —</option>
                        @foreach($typesConsultation as $type)
                        <option value="{{ $type->id }}">{{ $type->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rv-contact" class="block text-xs text-gray-500 mb-1">Contact pour rappel</label>
                        <input id="rv-contact" name="contact" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="rv-motif" class="block text-xs text-gray-500 mb-1">Motif</label>
                        <input id="rv-motif" name="motif" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
                <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-5 py-2 rounded-lg font-semibold">
                    Fixer le rendez-vous
                </button>
            </form>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Bloquer un créneau</div>
            <form method="POST" action="{{ route('agenda.bloquer') }}" class="p-4 space-y-3">
                @csrf
                <p class="text-xs text-gray-500">
                    Congé, réunion, garde : le créneau devient indisponible sans être rattaché à un patient.
                </p>
                <div>
                    <label for="bl-prestataire" class="block text-xs text-gray-500 mb-1">Prestataire</label>
                    <select id="bl-prestataire" name="prestataire_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($prestataires as $p)
                        <option value="{{ $p->id }}" @selected($prestataire->id === $p->id)>Dr {{ trim($p->prenom . ' ' . $p->nom) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="bl-debut" class="block text-xs text-gray-500 mb-1">Date et heure</label>
                        <input id="bl-debut" type="datetime-local" name="debut" required
                            value="{{ $jourCarbon->copy()->setTime(12, 0)->format('Y-m-d\TH:i') }}"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="bl-duree" class="block text-xs text-gray-500 mb-1">Durée (min)</label>
                        <input id="bl-duree" type="number" name="duree_minutes" min="10" max="600" step="15" value="60"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label for="bl-motif" class="block text-xs text-gray-500 mb-1">Motif</label>
                    <input id="bl-motif" name="motif" placeholder="Réunion de service, congé…"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <button class="bg-gray-700 hover:bg-gray-800 text-white text-sm px-5 py-2 rounded-lg font-semibold">
                    Bloquer le créneau
                </button>
            </form>
        </div>
    </div>

    {{-- ── Tous prestataires ────────────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mt-4">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Tous les rendez-vous du jour</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Heure</th>
                    <th class="px-4 py-2 text-left">Patient</th>
                    <th class="px-4 py-2 text-left">Contact</th>
                    <th class="px-4 py-2 text-left">Prestataire</th>
                    <th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-left">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($tousDuJour as $rv)
                <tr class="{{ $rv->statut === 'annule' ? 'opacity-50' : '' }}">
                    <td class="px-4 py-2 font-semibold">{{ $rv->debut->format('H:i') }}</td>
                    <td class="px-4 py-2">{{ $rv->patient?->nom_complet ?? ($rv->motif ?: 'Créneau bloqué') }}</td>
                    <td class="px-4 py-2 text-xs text-gray-500">{{ $rv->contact ?: '—' }}</td>
                    <td class="px-4 py-2 text-xs">Dr {{ $rv->prestataire?->nom }}</td>
                    <td class="px-4 py-2 text-xs">{{ $rv->typeConsultation?->libelle ?? '—' }}</td>
                    <td class="px-4 py-2 text-xs">{{ $rv->libelleStatut() }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucun rendez-vous ce jour</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
