@extends('layouts.app')
@section('title', 'Dossier — ' . $visit->patient->nom_complet)
@section('content')
@php
    $evolutions = $visit->notesEvolution->sortByDesc('created_at');
    $constantes = $visit->signesVitaux->sortByDesc('mesure_at');
    $derniere = $constantes->first();
@endphp
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="{{ route('services.show', $service) }}" class="text-blue-700 hover:underline text-sm">← {{ $service->nom }}</a>
        <h2 class="text-2xl font-bold text-gray-800">{{ $visit->patient->nom_complet }}</h2>
        <a href="{{ route('visites.show', $visit) }}" class="ml-auto text-sm text-blue-700 hover:underline">Parcours &amp; sortie →</a>
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

    {{-- Signalétique --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 text-sm">
        <div><span class="text-gray-500 text-xs">Dossier</span><p class="font-semibold">{{ $visit->patient->dossier_number }}</p></div>
        <div><span class="text-gray-500 text-xs">Sexe / Âge</span><p class="font-semibold">{{ $visit->patient->sexe }} · {{ $visit->patient->date_naissance?->age }} ans</p></div>
        <div><span class="text-gray-500 text-xs">Lit</span><p class="font-semibold">{{ $visit->lit?->numero ?? 'Sans lit' }}</p></div>
        <div><span class="text-gray-500 text-xs">Entrée</span><p class="font-semibold">{{ $visit->date_entree->format('d/m/Y H:i') }}</p></div>
        <div><span class="text-gray-500 text-xs">Durée séjour</span><p class="font-semibold">{{ $visit->joursHospitalisation() }} jour(s)</p></div>
        <div><span class="text-gray-500 text-xs">Prise en charge</span><p class="font-semibold">{{ $visit->patient->type_prise_en_charge === 'assurance' ? ($visit->patient->assurance_nom ?: 'Assurance') : 'Privé' }}</p></div>
    </div>

    @if($impayees->count() > 0)
    <div class="bg-amber-50 border border-amber-300 rounded-xl px-4 py-3 mb-4 text-sm text-amber-900">
        ⚠️ {{ $impayees->count() }} facture(s) impayée(s) — {{ number_format($impayees->sum('total_ttc'), 0, ',', ' ') }} CDF à régler avant la sortie.
    </div>
    @endif

    @if($derniere && count($derniere->alertes()) > 0)
    <div class="bg-red-50 border border-red-300 rounded-xl px-4 py-3 mb-4 text-sm text-red-800">
        🚨 Dernières constantes hors normes : {{ implode(' · ', $derniere->alertes()) }}
    </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-4">

        {{-- ── Évolution & transmissions ─────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">📈 Évolution &amp; transmissions</div>
            <div class="max-h-72 overflow-y-auto divide-y divide-gray-100">
                @forelse($evolutions as $note)
                <div class="px-4 py-2.5">
                    <div class="flex items-center gap-2 mb-0.5">
                        <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded {{ $note->type === 'transmission' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }}">
                            {{ $note->type === 'transmission' ? 'Transmission' : 'Évolution' }}
                        </span>
                        @if($note->etat_general)
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded
                            {{ $note->etat_general === 'critique' ? 'bg-red-600 text-white' : ($note->etat_general === 'degradee' ? 'bg-amber-400 text-amber-950' : 'bg-green-100 text-green-800') }}">
                            {{ ucfirst($note->etat_general) }}
                        </span>
                        @endif
                        <span class="text-[11px] text-gray-400 ml-auto">{{ $note->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ $note->note }}</p>
                    <p class="text-[11px] text-gray-400 mt-0.5">{{ $note->auteur?->prenom }} {{ $note->auteur?->nom }}</p>
                </div>
                @empty
                <p class="px-4 py-8 text-center text-sm text-gray-400">Aucune note au dossier</p>
                @endforelse
            </div>
            <form method="POST" action="{{ route('visites.evolution', $visit) }}" class="px-4 py-3 border-t bg-gray-50 space-y-2">
                @csrf
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label for="note-type" class="sr-only">Type de note</label>
                        <select id="note-type" name="type" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                            <option value="evolution">Évolution médicale</option>
                            <option value="transmission">Transmission infirmière</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label for="note-etat" class="sr-only">État général</label>
                        <select id="note-etat" name="etat_general" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                            <option value="">État général…</option>
                            <option value="bonne">Bonne</option>
                            <option value="stationnaire">Stationnaire</option>
                            <option value="degradee">Dégradée</option>
                            <option value="critique">Critique</option>
                        </select>
                    </div>
                </div>
                <label for="note-texte" class="sr-only">Note</label>
                <textarea id="note-texte" name="note" rows="2" required placeholder="État du jour, conduite à tenir, soins effectués…"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">{{ old('note') }}</textarea>
                <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-4 py-1.5 rounded-lg font-semibold">+ Enregistrer la note</button>
            </form>
        </div>

        {{-- ── Surveillance des constantes ────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">🌡 Surveillance des constantes</div>
            <div class="max-h-72 overflow-y-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-2 py-2 text-left">Date</th><th class="px-2 py-2 text-center">T°</th>
                            <th class="px-2 py-2 text-center">TA</th><th class="px-2 py-2 text-center">FC</th>
                            <th class="px-2 py-2 text-center">FR</th><th class="px-2 py-2 text-center">SpO₂</th>
                            <th class="px-2 py-2 text-center">Glyc.</th><th class="px-2 py-2 text-left">Par</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($constantes as $sv)
                        <tr class="{{ count($sv->alertes()) > 0 ? 'bg-red-50' : '' }}">
                            <td class="px-2 py-1.5 whitespace-nowrap">{{ $sv->mesure_at->format('d/m H:i') }}</td>
                            <td class="px-2 py-1.5 text-center">{{ $sv->temperature ? ($sv->temperature + 0) : '—' }}</td>
                            <td class="px-2 py-1.5 text-center">{{ $sv->tension_systolique ? $sv->tension_systolique . '/' . $sv->tension_diastolique : '—' }}</td>
                            <td class="px-2 py-1.5 text-center">{{ $sv->frequence_cardiaque ?: '—' }}</td>
                            <td class="px-2 py-1.5 text-center">{{ $sv->frequence_respiratoire ?: '—' }}</td>
                            <td class="px-2 py-1.5 text-center">{{ $sv->saturation_o2 ?: '—' }}</td>
                            <td class="px-2 py-1.5 text-center">{{ $sv->glycemie ? ($sv->glycemie + 0) : '—' }}</td>
                            <td class="px-2 py-1.5 text-gray-500">{{ $sv->auteur?->nom }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="px-2 py-8 text-center text-gray-400">Aucun relevé</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <form method="POST" action="{{ route('visites.signes-vitaux', $visit) }}" class="px-4 py-3 border-t bg-gray-50">
                @csrf
                <div class="grid grid-cols-3 md:grid-cols-4 gap-2 mb-2">
                    @foreach([
                        'temperature' => ['T° (°C)', '0.1'],
                        'tension_systolique' => ['TA systo.', '1'],
                        'tension_diastolique' => ['TA diasto.', '1'],
                        'frequence_cardiaque' => ['FC (bpm)', '1'],
                        'frequence_respiratoire' => ['FR (/min)', '1'],
                        'saturation_o2' => ['SpO₂ (%)', '1'],
                        'poids_kg' => ['Poids (kg)', '0.1'],
                        'glycemie' => ['Glycémie', '0.01'],
                    ] as $champ => [$libelle, $pas])
                    <div>
                        <label for="sv-{{ $champ }}" class="block text-[11px] text-gray-500 mb-0.5">{{ $libelle }}</label>
                        <input id="sv-{{ $champ }}" type="number" step="{{ $pas }}" name="{{ $champ }}"
                            class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                    </div>
                    @endforeach
                </div>
                <label for="sv-observation" class="sr-only">Observation</label>
                <input id="sv-observation" name="observation" placeholder="Observation (facultatif)"
                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-2">
                <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-4 py-1.5 rounded-lg font-semibold">+ Enregistrer les constantes</button>
            </form>
        </div>

        {{-- ── Produits & prescriptions ───────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700 flex justify-between items-center">
                <span>💊 Produits &amp; prescriptions</span>
                <span class="flex gap-3">
                    <a href="{{ route('mar.index', ['visit' => $visit->id]) }}" class="text-xs text-blue-700 hover:underline">💉 Plan 24 h</a>
                    <a href="{{ route('bilan-hydrique.index', ['visit' => $visit->id]) }}" class="text-xs text-blue-700 hover:underline">💧 Bilan hydrique</a>
                    <a href="{{ route('infirmier.index', ['visit' => $visit->id]) }}" class="text-xs text-blue-700 hover:underline">🩺 Dossier infirmier</a>
                    @if($visit->consultations->first())
                    <a href="{{ route('prescriptions.create', $visit->consultations->first()) }}" class="text-xs text-blue-700 hover:underline">+ Prescrire</a>
                    @endif
                </span>
            </div>
            <div class="divide-y divide-gray-100 max-h-72 overflow-y-auto">
                @forelse($prescriptions as $prescription)
                <div class="px-4 py-2.5">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-xs text-gray-500">{{ $prescription->date_prescription?->format('d/m/Y H:i') }}</span>
                        <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded
                            {{ $prescription->statut === 'dispensee' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ str_replace('_', ' ', $prescription->statut) }}
                        </span>
                    </div>
                    @foreach($prescription->lignes as $ligne)
                    <p class="text-sm text-gray-700">• {{ $ligne->medicament->denomination_commune }} {{ $ligne->medicament->dosage }}
                        <span class="text-xs text-gray-500">— {{ $ligne->dose }}, {{ $ligne->frequence }} · servi {{ $ligne->quantite_dispensee + 0 }}/{{ $ligne->quantite_totale + 0 }}</span>
                    </p>
                    @endforeach
                </div>
                @empty
                <p class="px-4 py-8 text-center text-sm text-gray-400">Aucune prescription</p>
                @endforelse
            </div>
        </div>

        {{-- ── Examens & actes ────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700 flex justify-between items-center">
                <span>🔬 Examens &amp; actes</span>
                <span class="flex gap-3">
                    <a href="{{ route('labo.create', ['visit_id' => $visit->id]) }}" class="text-xs text-blue-700 hover:underline">+ Labo</a>
                    <a href="{{ route('imagerie.create', ['visit_id' => $visit->id]) }}" class="text-xs text-blue-700 hover:underline">+ Imagerie</a>
                    <a href="{{ route('bloc.create', ['visit_id' => $visit->id]) }}" class="text-xs text-blue-700 hover:underline">+ Bloc</a>
                </span>
            </div>
            <div class="divide-y divide-gray-100 max-h-72 overflow-y-auto">
                @forelse($visit->examensLaboratoire->sortByDesc('date_prescription') as $examen)
                <div class="px-4 py-2 flex justify-between items-center text-sm">
                    <span>
                        <a href="{{ route('labo.show', $examen) }}" class="text-blue-700 font-mono hover:underline">{{ $examen->numero_bon }}</a>
                        <span class="text-gray-500 text-xs">— {{ $examen->domaine }} · {{ $examen->resultats->unique('type_examen_id')->count() }} examen(s)</span>
                    </span>
                    <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded {{ $examen->statut === 'valide' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                        {{ str_replace('_', ' ', $examen->statut) }}
                    </span>
                </div>
                @empty
                <p class="px-4 py-6 text-center text-sm text-gray-400">Aucun examen</p>
                @endforelse

                @foreach($visit->actesCliniques as $acte)
                <div class="px-4 py-2 flex justify-between items-center text-sm">
                    <span class="text-gray-700">{{ $acte->libelle }}
                        <span class="text-xs text-gray-400">({{ $acte->domaine }})</span>
                    </span>
                    <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ $acte->statut }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
