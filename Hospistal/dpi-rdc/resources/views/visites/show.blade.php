@extends('layouts.app')
@section('title', 'Parcours patient')
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('visites.index') }}" class="text-blue-700 hover:underline text-sm">← Visites</a>
        <h2 class="text-2xl font-bold text-gray-800">Parcours — {{ $visit->patient->nom_complet }}</h2>
        <a href="{{ route('parcours.chronologie', $visit) }}"
           class="ml-auto text-sm text-blue-700 hover:underline">⏱️ Chronologie et temps d'attente →</a>
        @if($visit->date_sortie)
        <a href="{{ route('visites.bulletin', $visit) }}" target="_blank"
           class="text-sm text-blue-700 hover:underline">🖨️ Bulletin de sortie</a>
        @endif
    </div>

    @foreach(['success','error','info'] as $type)
        @if(session($type))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border
            {{ $type==='success' ? 'bg-green-50 border-green-200 text-green-800' : ($type==='error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-blue-50 border-blue-200 text-blue-800') }}">
            {{ session($type) }}
        </div>
        @endif
    @endforeach

    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
        <div><span class="text-gray-500">Dossier</span><p class="font-semibold">{{ $visit->patient->dossier_number }}</p></div>
        <div><span class="text-gray-500">Type visite</span><p class="font-semibold">{{ str_replace('_',' ', $visit->type) }}</p></div>
        <div><span class="text-gray-500">Entrée</span><p class="font-semibold">{{ $visit->date_entree->format('d/m/Y H:i') }}</p></div>
        <div><span class="text-gray-500">Statut</span><p class="font-semibold">{{ ucfirst($visit->statut) }}</p></div>
    </div>

    {{-- Actions rapides --}}
    <div class="flex flex-wrap gap-2 mb-6">
        @if($visit->consultations->first())
        <a href="{{ route('consultations.show', $visit->consultations->first()) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">Consultation</a>
        @endif
        <a href="{{ route('labo.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">🔬 Prescrire labo</a>
        <a href="{{ route('imagerie.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">📷 Imagerie</a>
        <a href="{{ route('examens-specialises.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">🩺 Examen spécialisé</a>
        @if($visit->consultations->first())
        <a href="{{ route('prescriptions.create', $visit->consultations->first()) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">💊 Prescrire</a>
        @endif
        <a href="{{ route('bloc.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">🏥 Bloc</a>
        <a href="{{ route('maternite.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">👶 Maternité</a>
        <a href="{{ route('dialyse.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">🩸 Dialyse</a>
        @if(in_array($visit->type, \App\Services\AcompteService::TYPES_VISITE, true))
        <a href="{{ route('acomptes.show', $visit) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">💰 Acomptes</a>
        @endif
    </div>

    {{-- Forfait du séjour --}}
    @if(in_array($visit->type, \App\Services\AcompteService::TYPES_VISITE, true) && $visit->peutRecevoirServices())
    @php $forfaitsDisponibles = app(\App\Services\ForfaitService::class)->disponiblesPour($visit); @endphp
    <div class="bg-white rounded-xl shadow p-4 mb-4">
        @if($visit->forfait)
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div>
                <span class="px-2 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800">
                    📦 Forfait {{ $visit->forfait->libelle }}
                </span>
                <span class="text-sm text-gray-600 ml-2">
                    {{ number_format((float) $visit->forfait_montant, 0, ',', ' ') }} {{ $visit->forfait->devise }}
                    — couvre {{ mb_strtolower(implode(', ', $visit->forfait->libellesCouverts())) }}
                </span>
                @unless($visit->forfait->couvreEncore($visit))
                <p class="text-xs text-amber-700 mt-1">
                    ⚠️ Les {{ $visit->forfait->jours_inclus }} journées incluses sont dépassées :
                    les prestations redeviennent facturées à l'acte.
                </p>
                @endunless
            </div>
            <form method="POST" action="{{ route('forfaits.retirer', $visit) }}">
                @csrf
                <button class="text-xs text-red-700 hover:underline">Retirer le forfait</button>
            </form>
        </div>
        @elseif($forfaitsDisponibles->isNotEmpty())
        <form method="POST" action="{{ route('forfaits.appliquer', $visit) }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <div class="flex-1 min-w-60">
                <label for="forfait-visite" class="block text-xs font-semibold text-gray-600 mb-1">Appliquer un forfait</label>
                <select id="forfait-visite" name="forfait_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach($forfaitsDisponibles as $forfait)
                    <option value="{{ $forfait->id }}">
                        {{ $forfait->libelle }} — {{ number_format((float) $forfait->montant, 0, ',', ' ') }} {{ $forfait->devise }}
                        ({{ $forfait->estGlobal() ? 'global' : 'partiel' }})
                    </option>
                    @endforeach
                </select>
            </div>
            <button class="bg-purple-700 hover:bg-purple-800 text-white rounded-lg px-4 py-2 text-sm font-semibold">
                Appliquer
            </button>
        </form>
        @endif
    </div>
    @endif

    {{-- Hospitalisation --}}
    @if($visit->statut === 'en_cours')
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h3 class="font-semibold text-gray-700 mb-4">Hospitalisation</h3>
        @if($visit->type !== 'hospitalisation')
        {{--
            Un seul choix : le lit, groupé par service.

            Les deux listes étaient liées par un onchange écrit dans la page,
            qui remplissait la seconde à partir de la première. Sur un poste
            dont la politique de sécurité interdit les scripts en ligne, la
            liste des lits restait vide : on ne pouvait pas admettre du tout.
        --}}
        <form method="POST" action="{{ route('visites.hospitaliser', $visit) }}" class="grid md:grid-cols-2 gap-4">
            @csrf
            <div>
                <label for="lit-admission" class="block text-xs text-gray-500 mb-1">Service et lit</label>
                <select id="lit-admission" name="lit_id" required
                        class="w-full min-h-[44px] border rounded-lg px-3 py-2 text-sm">
                    <option value="">— Choisir un lit libre —</option>
                    @foreach($services as $service)
                        @if($service->lits->isNotEmpty())
                        <optgroup label="{{ $service->nom }} ({{ $service->lits->count() }} libre(s))">
                            @foreach($service->lits as $lit)
                            <option value="{{ $lit->id }}">Lit {{ $lit->numero }}</option>
                            @endforeach
                        </optgroup>
                        @endif
                    @endforeach
                </select>
                @if($services->sum(fn ($s) => $s->lits->count()) === 0)
                <p class="text-xs text-amber-800 mt-1">Aucun lit libre dans l'hôpital.</p>
                @endif
            </div>
            <div class="flex items-end">
                <button type="submit" class="min-h-[44px] bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm w-full font-semibold">
                    Admettre en hospitalisation
                </button>
            </div>
        </form>
        @else
        <p class="text-sm text-gray-600 mb-3">
            Service : <strong>{{ $visit->service?->nom ?? '—' }}</strong> —
            Lit : <strong>{{ $visit->lit?->numero ?? '—' }}</strong>
            ({{ $visit->joursHospitalisation() }} jour(s))
        </p>
        <form method="POST" action="{{ route('visites.facturer-sejour', $visit) }}" class="inline">
            @csrf
            <button type="submit" class="bg-amber-600 text-white px-4 py-2 rounded-lg text-sm mr-2">Facturer séjour</button>
        </form>
        @endif
    </div>
    @endif

    {{-- Factures --}}
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h3 class="font-semibold text-gray-700 mb-3">Factures ({{ $impayees }} impayée(s))</h3>
        <ul class="space-y-2 text-sm">
            @forelse($visit->factures as $facture)
            <li class="flex justify-between items-center border-b pb-2">
                <span>{{ $facture->numero_facture }} — {{ number_format($facture->total_ttc, 0, ',', ' ') }} CDF</span>
                <span class="px-2 py-0.5 rounded text-xs {{ $facture->statut==='payee' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">{{ $facture->statut }}</span>
                <a href="{{ route('caisse.show', $facture) }}" class="text-blue-700 text-xs">Guichet →</a>
            </li>
            @empty
            <li class="text-gray-400">Aucune facture</li>
            @endforelse
        </ul>
    </div>

    {{-- Examens --}}
    @if($visit->examensLaboratoire->count())
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h3 class="font-semibold text-gray-700 mb-3">Examens labo / imagerie</h3>
        @foreach($visit->examensLaboratoire as $examen)
        <div class="flex justify-between text-sm border-b py-2">
            <span>{{ $examen->domaine }} — {{ $examen->statut }} ({{ $examen->resultats->count() }} actes)</span>
            <a href="{{ route('labo.show', $examen) }}" class="text-blue-700">Voir bilan →</a>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Sortie --}}
    {{-- Les urgences aussi doivent pouvoir clore un passage : un décès ou une
         sortie contre avis médical y arrive, et n'avait aucun écran pour
         s'écrire. L'ambulatoire, lui, se clôture seul en fin de journée. --}}
    @if($visit->statut === 'en_cours' && in_array($visit->type, ['hospitalisation', 'urgence'], true))
    <div class="bg-white rounded-xl shadow p-6 border-2 border-green-200">
        <h3 class="font-semibold text-green-800 mb-3">Sortie patient</h3>
        @if($impayees > 0)
        <p class="text-red-600 text-sm mb-3">⚠️ {{ $impayees }} facture(s) impayée(s) — régler au guichet avant sortie.</p>
        @endif
        <form method="POST" action="{{ route('visites.sortir', $visit) }}" class="grid md:grid-cols-3 gap-3">
            @csrf
            <div>
                <label for="s-mode" class="block text-xs text-gray-500 mb-1">Mode de sortie</label>
                {{-- Les huit modes, décès compris : un registre qui ne sait pas
                     l'écrire oblige à mentir sur l'issue du séjour. --}}
                <select id="s-mode" name="mode_sortie" class="w-full border rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Visit::MODES_SORTIE as $cle => $libelle)
                    <option value="{{ $cle }}" @selected(old('mode_sortie') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="s-rdv" class="block text-xs text-gray-500 mb-1">Rendez-vous de contrôle</label>
                <input id="s-rdv" name="rendez_vous_controle" type="date" value="{{ old('rendez_vous_controle') }}"
                       class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-green-700 hover:bg-green-800 text-white px-6 py-2 rounded-lg text-sm" @disabled($impayees > 0)>
                    Valider la sortie & imprimer le bulletin
                </button>
            </div>
            <div class="md:col-span-3">
                <label for="s-obs" class="block text-xs text-gray-500 mb-1">Évolution durant le séjour</label>
                <input id="s-obs" name="observations_sortie" maxlength="2000"
                       value="{{ old('observations_sortie', app(\App\Services\DiagnosticService::class)->pourIndication($visit)) }}"
                       placeholder="Apyrexie obtenue à J3, reprise de l'alimentation…"
                       class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-3">
                <label for="s-reco" class="block text-xs text-gray-500 mb-1">Recommandations à la sortie</label>
                <input id="s-reco" name="recommandations_sortie" maxlength="2000" value="{{ old('recommandations_sortie') }}"
                       placeholder="Poursuivre le traitement 5 jours, revenir si fièvre…"
                       class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
        </form>
    </div>
    @endif
</div>
@endsection
