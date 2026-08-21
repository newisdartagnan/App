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

    {{-- ── Transfert interne : le séjour ne se ferme pas ─────────── --}}
    <details class="bg-white rounded-xl shadow mb-4" {{ $errors->has('motif') || $errors->has('service_destination_id') ? 'open' : '' }}>
        <summary class="px-4 py-3 font-semibold text-gray-700 cursor-pointer select-none flex items-center justify-between">
            <span>🔄 Transfert vers un autre service</span>
            @if($visit->transferts->isNotEmpty())
            <span class="text-xs font-normal text-gray-500">{{ $visit->transferts->count() }} transfert(s) au cours du séjour</span>
            @endif
        </summary>

        <div class="px-4 pb-4 border-t pt-4">
            <p class="text-xs text-gray-500 mb-3">
                Le patient reste hospitalisé : même admission, même dossier, mêmes factures.
                Seuls le service et le lit changent. Pour orienter le patient vers un autre
                établissement, utilisez plutôt la sortie avec mode « transfert ».
            </p>

            @if($visit->peutRecevoirServices())
            @if($servicesAccueil->isEmpty())
            <p class="text-sm text-amber-700">Aucun autre service d'hospitalisation actif.</p>
            @else
            <form method="POST" action="{{ route('transferts.store', $visit) }}" class="grid md:grid-cols-2 gap-3">
                @csrf
                <div>
                    <label for="t-service" class="block text-xs font-semibold text-gray-600 mb-1">Service d'accueil <span class="text-red-500">*</span></label>
                    <select id="t-service" name="service_destination_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($servicesAccueil as $accueil)
                        <option value="{{ $accueil->id }}" @selected(old('service_destination_id') === $accueil->id)>
                            {{ $accueil->nom }} — {{ $accueil->lits->count() }} lit(s) libre(s)
                        </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="t-lit" class="block text-xs font-semibold text-gray-600 mb-1">Lit d'accueil</label>
                    <select id="t-lit" name="lit_destination_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">Sans lit assigné pour l'instant</option>
                        @foreach($servicesAccueil as $accueil)
                        @foreach($accueil->lits as $lit)
                        <option value="{{ $lit->id }}" @selected(old('lit_destination_id') === $lit->id)>
                            {{ $accueil->nom }} · lit {{ $lit->numero }}
                        </option>
                        @endforeach
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="t-demandeur" class="block text-xs font-semibold text-gray-600 mb-1">Demandé par</label>
                    <select id="t-demandeur" name="demandeur_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">— praticien hors application, à nommer ci-contre —</option>
                        @foreach($medecins as $medecin)
                        <option value="{{ $medecin->id }}" @selected(old('demandeur_id') === $medecin->id)>{{ $medecin->nom_complet }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="t-nom" class="block text-xs font-semibold text-gray-600 mb-1">Nom du demandeur (si hors liste)</label>
                    <input id="t-nom" name="demandeur_nom" maxlength="150" value="{{ old('demandeur_nom') }}"
                           placeholder="Dr NGOY, chef de service"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="t-motif" class="block text-xs font-semibold text-gray-600 mb-1">Raison du transfert <span class="text-red-500">*</span></label>
                    <textarea id="t-motif" name="motif" rows="2" required maxlength="1000"
                              placeholder="Ex. état stabilisé, poursuite des soins en médecine interne"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">{{ old('motif') }}</textarea>
                </div>
                <div class="md:col-span-2">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Transférer le patient
                    </button>
                </div>
            </form>
            @endif
            @else
            <p class="text-sm text-gray-500">Séjour terminé — plus aucun transfert possible.</p>
            @endif

            @if($visit->transferts->isNotEmpty())
            <div class="mt-4 pt-4 border-t">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Parcours dans l'hôpital</p>
                <ol class="space-y-2">
                    @foreach($visit->transferts->sortBy('transfere_a') as $transfert)
                    <li class="text-sm text-gray-700 border-l-2 border-blue-300 pl-3">
                        <span class="font-semibold">{{ $transfert->trajet() }}</span>
                        @if($transfert->litDestination)<span class="text-gray-500">· lit {{ $transfert->litDestination->numero }}</span>@endif
                        <p class="text-xs text-gray-500">
                            {{ $transfert->transfere_a->format('d/m/Y à H:i') }}
                            · demandé par {{ $transfert->demandeur_nom }}
                            · enregistré par {{ $transfert->auteur?->nom_complet }}
                        </p>
                        <p class="text-xs text-gray-600 italic">{{ $transfert->motif }}</p>
                    </li>
                    @endforeach
                </ol>
            </div>
            @endif
        </div>
    </details>

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
                    <a href="{{ route('acomptes.show', $visit) }}" class="text-xs text-blue-700 hover:underline">💰 Acomptes</a>
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

        {{-- ── Diète & ménage ─────────────────────────────────────── --}}
        @php
            $dietes = $visit->prescriptionsDiete()->with('typeDiete')->orderByDesc('debut')->get();
            $taches = $visit->tachesMenage()->orderByDesc('created_at')->limit(5)->get();
        @endphp
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700 flex justify-between items-center">
                <span>🍽️ Diète &amp; ménage</span>
                <a href="{{ route('diete.index') }}" class="text-xs text-blue-700 hover:underline">Prescrire →</a>
            </div>
            <div class="divide-y divide-gray-100">
                @forelse($dietes as $diete)
                <div class="px-4 py-2 text-sm">
                    <div class="flex justify-between items-center gap-2">
                        <span class="text-gray-700">{{ $diete->typeDiete->libelle }}</span>
                        <span class="font-semibold text-gray-800">
                            {{ number_format($diete->montant(), 0, ',', ' ') }} CDF
                        </span>
                    </div>
                    <p class="text-xs text-gray-500">
                        depuis le {{ $diete->debut->format('d/m/Y') }}
                        · {{ $diete->joursServis() }} jour(s) servi(s)
                        à {{ number_format((float) $diete->typeDiete->prix_journalier, 0, ',', ' ') }} CDF/jour
                        @if($diete->facture_id)
                        · <span class="text-green-700">portée sur la facture du séjour</span>
                        @else
                        · <span class="text-amber-700">à porter sur la prochaine facture du séjour</span>
                        @endif
                    </p>
                </div>
                @empty
                <p class="px-4 py-6 text-center text-sm text-gray-400">Aucune diète prescrite</p>
                @endforelse

                <div class="px-4 py-2 text-sm">
                    <p class="text-xs font-semibold text-gray-600 mb-1">Ménage de la chambre</p>
                    @forelse($taches as $tache)
                    <p class="text-xs {{ $tache->statut === 'fait' ? 'text-green-700' : 'text-amber-700' }}">
                        {{ $tache->statut === 'fait' ? '✓' : '○' }} {{ $tache->libelleType() }}
                        <span class="text-gray-400">— {{ $tache->created_at->format('d/m H:i') }}</span>
                    </p>
                    @empty
                    <p class="text-xs text-gray-400">Aucune tâche enregistrée</p>
                    @endforelse
                    <p class="text-xs text-gray-500 mt-1">
                        L'entretien de la chambre est compris dans le prix de la journée
                        d'hospitalisation : il ne se facture pas à part.
                    </p>
                </div>
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
                @php
                    // Un acte payé mais jamais programmé doit se voir : la
                    // facture ne prouve pas que le geste a été fait.
                    $aProgrammer = $acte->statut === 'prescrit';
                    $etat = match ($acte->statut) {
                        'prescrit' => ['À programmer', 'bg-amber-100 text-amber-900'],
                        'planifie' => ['Programmé', 'bg-blue-100 text-blue-800'],
                        'realise' => ['Réalisé', 'bg-green-100 text-green-800'],
                        'facture' => ['Réalisé et facturé', 'bg-green-100 text-green-800'],
                        default => [$acte->statut, 'bg-gray-100 text-gray-600'],
                    };
                @endphp
                <div class="px-4 py-2 text-sm">
                    <div class="flex justify-between items-center gap-2">
                        <span class="text-gray-700">{{ $acte->libelle }}
                            <span class="text-xs text-gray-400">({{ $acte->domaine }})</span>
                        </span>
                        <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded {{ $etat[1] }}">{{ $etat[0] }}</span>
                    </div>
                    <p class="text-xs text-gray-500">
                        @if($acte->date_prevue)
                            {{ $acte->date_prevue->format('d/m/Y à H:i') }}
                            @if($acte->salle) · {{ $acte->salle->nom }} @endif
                            @if($acte->operateur) · {{ $acte->operateur->nom_complet }} @endif
                        @elseif($aProgrammer)
                            Ni salle, ni créneau, ni opérateur —
                            <a href="{{ route('bloc.programme') }}" class="text-blue-700 hover:underline">programmer au bloc</a>
                        @endif
                        @if($acte->facture_id) · facturé @endif
                    </p>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
