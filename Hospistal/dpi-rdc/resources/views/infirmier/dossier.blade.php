@extends('layouts.app')
@section('title', 'Dossier infirmier — ' . $visit->patient->nom_complet)
@section('content')
@php
    $onglets = \App\Http\Controllers\DossierInfirmierController::ONGLETS;
    $lien = fn ($cle) => route('infirmier.index', ['visit' => $visit->id, 'onglet' => $cle]);
    $clos = ! $visit->peutRecevoirServices();
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-4 flex-wrap">
        @if($visit->service)
        <a href="{{ route('services.dossier', [$visit->service, $visit]) }}" class="text-blue-700 hover:underline text-sm">← Dossier de séjour</a>
        @else
        <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline text-sm">← Parcours</a>
        @endif
        <h2 class="text-2xl font-bold text-gray-800">🩺 Dossier infirmier</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
            {{ $visit->patient->nom_complet }} · Lit {{ $visit->lit?->numero ?? '—' }} · J{{ $visit->joursHospitalisation() }}
        </span>
        <span class="ml-auto flex gap-3 text-sm">
            <a href="{{ route('mar.index', ['visit' => $visit->id]) }}" class="text-blue-700 hover:underline">💉 Plan 24 h</a>
            <a href="{{ route('bilan-hydrique.index', ['visit' => $visit->id]) }}" class="text-blue-700 hover:underline">💧 Bilan hydrique</a>
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

    @if($clos)
    <div class="bg-gray-100 border border-gray-300 text-gray-700 rounded-lg px-4 py-3 mb-4 text-sm">
        Séjour terminé — le dossier est clos, la consultation reste possible mais plus aucune saisie.
    </div>
    @endif

    {{-- Onglets --}}
    <div class="bg-white rounded-t-xl shadow-sm border-b flex flex-wrap">
        @foreach($onglets as $cle => $libelle)
        <a href="{{ $lien($cle) }}"
           class="px-5 py-3 text-sm font-semibold border-b-2 {{ $onglet === $cle ? 'border-blue-700 text-blue-800 bg-blue-50' : 'border-transparent text-gray-600 hover:bg-gray-50' }}">
            {{ $libelle }}
            @php $n = match($cle) { 'pansement' => $pansements->count(), 'gavage' => $gavages->count(), 'neuro' => $neuros->count(), default => $transfusions->count() }; @endphp
            @if($n)<span class="ml-1 text-xs bg-gray-200 text-gray-700 rounded-full px-2">{{ $n }}</span>@endif
        </a>
        @endforeach
    </div>

    <div class="bg-white rounded-b-xl shadow p-5">

    {{-- ══════════════════ PANSEMENT ══════════════════ --}}
    @if($onglet === 'pansement')
        @php $due = $pansements->first(fn ($p) => $p->refectionDue()); @endphp
        @if($due)
        <div class="bg-amber-50 border border-amber-300 rounded-lg px-4 py-3 mb-4 text-sm text-amber-900">
            ⏰ Réfection programmée le {{ $due->date_refaire->format('d/m/Y') }}
            ({{ $due->localisation }})
            @if($due->joursRetard() > 0) — <strong>{{ $due->joursRetard() }} jour(s) de retard</strong>@else — à faire aujourd'hui @endif
        </div>
        @endif

        @unless($clos)
        <form method="POST" action="{{ route('infirmier.pansement', $visit) }}" class="grid md:grid-cols-3 gap-3 mb-6 bg-gray-50 rounded-lg p-4">
            @csrf
            <div>
                <label for="p-realise" class="block text-xs font-semibold text-gray-600 mb-1">Réalisé le</label>
                <input id="p-realise" type="datetime-local" name="realise_a" required
                       value="{{ old('realise_a', now()->format('Y-m-d\TH:i')) }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="p-loc" class="block text-xs font-semibold text-gray-600 mb-1">Localisation de la plaie</label>
                <input id="p-loc" name="localisation" required maxlength="150" value="{{ old('localisation') }}"
                       placeholder="Ex. talon droit, cicatrice sus-ombilicale"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="p-etat" class="block text-xs font-semibold text-gray-600 mb-1">État de la plaie</label>
                <select id="p-etat" name="etat_plaie" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\SoinPansement::ETATS as $c => $l)
                    <option value="{{ $c }}" @selected(old('etat_plaie') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label for="p-proto" class="block text-xs font-semibold text-gray-600 mb-1">Protocole appliqué</label>
                <input id="p-proto" name="protocole" required maxlength="1000" value="{{ old('protocole') }}"
                       placeholder="Ex. nettoyage sérum physiologique, tulle gras, compresses stériles"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="p-refaire" class="block text-xs font-semibold text-gray-600 mb-1">À refaire le</label>
                <input id="p-refaire" type="date" name="date_refaire" value="{{ old('date_refaire') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label for="p-obs" class="block text-xs font-semibold text-gray-600 mb-1">Observation</label>
                <input id="p-obs" name="observation" maxlength="500" value="{{ old('observation') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex items-end">
                <button class="w-full bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-4 py-2 text-sm font-semibold">
                    Enregistrer le pansement
                </button>
            </div>
        </form>
        @endunless

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-left">Localisation</th>
                        <th class="px-3 py-2 text-left">État</th>
                        <th class="px-3 py-2 text-left">Protocole</th>
                        <th class="px-3 py-2 text-left">À refaire</th>
                        <th class="px-3 py-2 text-left">Réalisé par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($pansements as $p)
                    <tr class="{{ $p->estPreoccupant() ? 'bg-red-50' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $p->realise_a->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2">{{ $p->localisation }}</td>
                        <td class="px-3 py-2">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $p->estPreoccupant() ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                {{ $p->libelleEtat() }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-gray-600">{{ $p->protocole }}</td>
                        <td class="px-3 py-2 whitespace-nowrap {{ $p->refectionDue() ? 'font-bold text-amber-700' : 'text-gray-500' }}">
                            {{ $p->date_refaire?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-gray-500 text-xs">{{ $p->auteur?->nom }}</td>
                    </tr>
                    @if($p->observation)
                    <tr><td colspan="6" class="px-3 pb-2 text-xs text-gray-500 italic">{{ $p->observation }}</td></tr>
                    @endif
                    @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-gray-400">Aucun pansement enregistré</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ══════════════════ GAVAGE ══════════════════ --}}
    @if($onglet === 'gavage')
        @unless($clos)
        <form method="POST" action="{{ route('infirmier.gavage', $visit) }}" class="grid md:grid-cols-4 gap-3 mb-6 bg-gray-50 rounded-lg p-4">
            @csrf
            <div>
                <label for="g-realise" class="block text-xs font-semibold text-gray-600 mb-1">Réalisé le</label>
                <input id="g-realise" type="datetime-local" name="realise_a" required
                       value="{{ old('realise_a', now()->format('Y-m-d\TH:i')) }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="g-sonde" class="block text-xs font-semibold text-gray-600 mb-1">Sonde</label>
                <select id="g-sonde" name="sonde" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\SoinGavage::SONDES as $c => $l)
                    <option value="{{ $c }}" @selected(old('sonde') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="g-residu" class="block text-xs font-semibold text-gray-600 mb-1">Résidu gastrique (mL)</label>
                <input id="g-residu" type="number" min="0" max="5000" step="5" name="residu_gastrique"
                       value="{{ old('residu_gastrique', 0) }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="g-tol" class="block text-xs font-semibold text-gray-600 mb-1">Tolérance</label>
                <select id="g-tol" name="tolerance" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\SoinGavage::TOLERANCES as $c => $l)
                    <option value="{{ $c }}" @selected(old('tolerance') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label for="g-aliment" class="block text-xs font-semibold text-gray-600 mb-1">Type d'aliment</label>
                <input id="g-aliment" name="type_aliment" required maxlength="150" value="{{ old('type_aliment') }}"
                       placeholder="Ex. lait maternel, bouillie enrichie, nutrition entérale"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="g-qte" class="block text-xs font-semibold text-gray-600 mb-1">Quantité administrée (mL)</label>
                <input id="g-qte" type="number" min="0" max="5000" step="5" name="quantite_aliment" required
                       value="{{ old('quantite_aliment') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="g-elim" class="block text-xs font-semibold text-gray-600 mb-1">Quantité rejetée (mL)</label>
                <input id="g-elim" type="number" min="0" max="5000" step="5" name="quantite_eliminee"
                       value="{{ old('quantite_eliminee', 0) }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-3">
                <label for="g-obs" class="block text-xs font-semibold text-gray-600 mb-1">Observation</label>
                <input id="g-obs" name="observation" maxlength="500" value="{{ old('observation') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex items-end">
                <button class="w-full bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-4 py-2 text-sm font-semibold">
                    Enregistrer le gavage
                </button>
            </div>
        </form>
        @endunless

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-left">Sonde</th>
                        <th class="px-3 py-2 text-right">Résidu</th>
                        <th class="px-3 py-2 text-left">Aliment</th>
                        <th class="px-3 py-2 text-right">Administré</th>
                        <th class="px-3 py-2 text-right">Rejeté</th>
                        <th class="px-3 py-2 text-right">Retenu</th>
                        <th class="px-3 py-2 text-left">Tolérance</th>
                        <th class="px-3 py-2 text-left">Par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($gavages as $g)
                    <tr class="{{ $g->alerte() ? 'bg-amber-50' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $g->realise_a->format('d/m H:i') }}</td>
                        <td class="px-3 py-2 text-xs">{{ $g->libelleSonde() }}</td>
                        <td class="px-3 py-2 text-right {{ $g->residuEleve() ? 'font-bold text-red-700' : '' }}">{{ $g->residu_gastrique }} mL</td>
                        <td class="px-3 py-2">{{ $g->type_aliment }}</td>
                        <td class="px-3 py-2 text-right">{{ $g->quantite_aliment }} mL</td>
                        <td class="px-3 py-2 text-right text-amber-700">{{ $g->quantite_eliminee }} mL</td>
                        <td class="px-3 py-2 text-right font-semibold text-blue-800">{{ $g->quantiteRetenue() }} mL</td>
                        <td class="px-3 py-2 text-xs">{{ $g->libelleTolerance() }}</td>
                        <td class="px-3 py-2 text-gray-500 text-xs">{{ $g->auteur?->nom }}</td>
                    </tr>
                    @if($g->alerte())
                    <tr><td colspan="9" class="px-3 pb-2 text-xs text-red-700">⚠️ {{ $g->alerte() }}</td></tr>
                    @endif
                    @empty
                    <tr><td colspan="9" class="px-3 py-8 text-center text-gray-400">Aucun gavage enregistré</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ══════════════════ ÉVALUATION NEURO ══════════════════ --}}
    @if($onglet === 'neuro')
        @php $dernier = $neuros->first(); @endphp
        @if($dernier)
        <div class="grid md:grid-cols-4 gap-3 mb-5">
            <div class="rounded-xl p-4 text-center {{ $dernier->score <= 8 ? 'bg-red-50' : ($dernier->score <= 12 ? 'bg-amber-50' : 'bg-green-50') }}">
                <p class="text-3xl font-bold {{ $dernier->score <= 8 ? 'text-red-700' : ($dernier->score <= 12 ? 'text-amber-700' : 'text-green-700') }}">
                    {{ $dernier->score }}/15
                </p>
                <p class="text-xs text-gray-600 mt-1">{{ $dernier->libelleGravite() }}</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <p class="text-xs text-gray-500">Ouverture des yeux</p>
                <p class="font-semibold text-sm text-gray-800">{{ $dernier->libelleYeux() }} ({{ $dernier->ouverture_yeux }})</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <p class="text-xs text-gray-500">Réponse verbale</p>
                <p class="font-semibold text-sm text-gray-800">{{ $dernier->libelleVerbale() }} ({{ $dernier->reponse_verbale }})</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <p class="text-xs text-gray-500">Réponse motrice</p>
                <p class="font-semibold text-sm text-gray-800">{{ $dernier->libelleMotrice() }} ({{ $dernier->reponse_motrice }})</p>
            </div>
        </div>
        @if($dernier->alerte())
        <div class="bg-red-50 border border-red-300 rounded-lg px-4 py-3 mb-4 text-sm text-red-800">⚠️ {{ $dernier->alerte() }}</div>
        @endif
        @endif

        @unless($clos)
        <form method="POST" action="{{ route('infirmier.neuro', $visit) }}" class="grid md:grid-cols-3 gap-3 mb-6 bg-gray-50 rounded-lg p-4">
            @csrf
            <div class="md:col-span-3">
                <label for="n-date" class="block text-xs font-semibold text-gray-600 mb-1">Évalué le</label>
                <input id="n-date" type="datetime-local" name="evalue_a" required
                       value="{{ old('evalue_a', now()->format('Y-m-d\TH:i')) }}"
                       class="w-full md:w-64 border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="n-yeux" class="block text-xs font-semibold text-gray-600 mb-1">Ouverture des yeux (Y)</label>
                <select id="n-yeux" name="ouverture_yeux" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\EvaluationNeuro::OUVERTURE_YEUX as $pts => $l)
                    <option value="{{ $pts }}" @selected((int) old('ouverture_yeux', 4) === $pts)>{{ $pts }} — {{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="n-verb" class="block text-xs font-semibold text-gray-600 mb-1">Réponse verbale (V)</label>
                <select id="n-verb" name="reponse_verbale" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\EvaluationNeuro::REPONSE_VERBALE as $pts => $l)
                    <option value="{{ $pts }}" @selected((int) old('reponse_verbale', 5) === $pts)>{{ $pts }} — {{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="n-mot" class="block text-xs font-semibold text-gray-600 mb-1">Réponse motrice (M)</label>
                <select id="n-mot" name="reponse_motrice" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\EvaluationNeuro::REPONSE_MOTRICE as $pts => $l)
                    <option value="{{ $pts }}" @selected((int) old('reponse_motrice', 6) === $pts)>{{ $pts }} — {{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="n-pd" class="block text-xs font-semibold text-gray-600 mb-1">Pupille droite</label>
                <select id="n-pd" name="pupille_droite" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">—</option>
                    @foreach(\App\Models\EvaluationNeuro::PUPILLES as $c => $l)
                    <option value="{{ $c }}" @selected(old('pupille_droite') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="n-pg" class="block text-xs font-semibold text-gray-600 mb-1">Pupille gauche</label>
                <select id="n-pg" name="pupille_gauche" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">—</option>
                    @foreach(\App\Models\EvaluationNeuro::PUPILLES as $c => $l)
                    <option value="{{ $c }}" @selected(old('pupille_gauche') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="n-obs" class="block text-xs font-semibold text-gray-600 mb-1">Observation</label>
                <input id="n-obs" name="observation" maxlength="500" value="{{ old('observation') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-3">
                <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                    Enregistrer l'évaluation
                </button>
                <span class="ml-3 text-xs text-gray-500">Le score de Glasgow est calculé automatiquement (Y + V + M).</span>
            </div>
        </form>
        @endunless

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-center">Y</th>
                        <th class="px-3 py-2 text-center">V</th>
                        <th class="px-3 py-2 text-center">M</th>
                        <th class="px-3 py-2 text-center">Glasgow</th>
                        <th class="px-3 py-2 text-left">Pupilles (D / G)</th>
                        <th class="px-3 py-2 text-left">Par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($neuros as $n)
                    <tr class="{{ $n->score <= 8 ? 'bg-red-50' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $n->evalue_a->format('d/m H:i') }}</td>
                        <td class="px-3 py-2 text-center" title="{{ $n->libelleYeux() }}">{{ $n->ouverture_yeux }}</td>
                        <td class="px-3 py-2 text-center" title="{{ $n->libelleVerbale() }}">{{ $n->reponse_verbale }}</td>
                        <td class="px-3 py-2 text-center" title="{{ $n->libelleMotrice() }}">{{ $n->reponse_motrice }}</td>
                        <td class="px-3 py-2 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold {{ $n->score <= 8 ? 'bg-red-100 text-red-800' : ($n->score <= 12 ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800') }}">
                                {{ $n->score }}/15
                            </span>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600">
                            {{ \App\Models\EvaluationNeuro::PUPILLES[$n->pupille_droite] ?? '—' }}
                            /
                            {{ \App\Models\EvaluationNeuro::PUPILLES[$n->pupille_gauche] ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-gray-500 text-xs">{{ $n->auteur?->nom }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400">Aucune évaluation neurologique</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ══════════════════ TRANSFUSION ══════════════════ --}}
    @if($onglet === 'transfusion')
        @unless($clos)
        <form method="POST" action="{{ route('infirmier.transfusion', $visit) }}" class="grid md:grid-cols-4 gap-3 mb-6 bg-gray-50 rounded-lg p-4">
            @csrf
            <div>
                <label for="t-produit" class="block text-xs font-semibold text-gray-600 mb-1">Produit</label>
                <select id="t-produit" name="produit" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Transfusion::PRODUITS as $c => $l)
                    <option value="{{ $c }}" @selected(old('produit') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="t-gd" class="block text-xs font-semibold text-gray-600 mb-1">Groupe donneur</label>
                <select id="t-gd" name="groupe_donneur" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Transfusion::GROUPES as $g)
                    <option value="{{ $g }}" @selected(old('groupe_donneur') === $g)>{{ $g }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="t-gr" class="block text-xs font-semibold text-gray-600 mb-1">Groupe receveur</label>
                <select id="t-gr" name="groupe_receveur" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Transfusion::GROUPES as $g)
                    <option value="{{ $g }}" @selected(old('groupe_receveur') === $g)>{{ $g }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="t-poche" class="block text-xs font-semibold text-gray-600 mb-1">N° de poche</label>
                <input id="t-poche" name="numero_poche" required maxlength="50" value="{{ old('numero_poche') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="t-qte" class="block text-xs font-semibold text-gray-600 mb-1">Quantité (mL)</label>
                <input id="t-qte" type="number" min="10" max="1000" step="10" name="quantite" required
                       value="{{ old('quantite', 250) }}" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="t-jour" class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                <input id="t-jour" type="date" name="jour" required value="{{ old('jour', now()->toDateString()) }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="t-hd" class="block text-xs font-semibold text-gray-600 mb-1">Heure de début</label>
                <input id="t-hd" type="time" name="heure_debut" required value="{{ old('heure_debut', now()->format('H:i')) }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="t-hf" class="block text-xs font-semibold text-gray-600 mb-1">Heure de fin</label>
                <input id="t-hf" type="time" name="heure_fin" value="{{ old('heure_fin') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="t-inc" class="block text-xs font-semibold text-gray-600 mb-1">Incident</label>
                <select id="t-inc" name="incident" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Transfusion::INCIDENTS as $c => $l)
                    <option value="{{ $c }}" @selected(old('incident') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label for="t-obs" class="block text-xs font-semibold text-gray-600 mb-1">Observation</label>
                <input id="t-obs" name="observation" maxlength="500" value="{{ old('observation') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex items-end">
                <button class="w-full bg-red-700 hover:bg-red-800 text-white rounded-lg px-4 py-2 text-sm font-semibold">
                    Poser la poche
                </button>
            </div>
            <p class="md:col-span-4 text-xs text-gray-500">
                La compatibilité ABO / Rhésus est vérifiée avant enregistrement : une poche incompatible est refusée.
            </p>
        </form>
        @endunless

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Produit</th>
                        <th class="px-3 py-2 text-center">Donneur</th>
                        <th class="px-3 py-2 text-center">Receveur</th>
                        <th class="px-3 py-2 text-left">N° poche</th>
                        <th class="px-3 py-2 text-right">Qté</th>
                        <th class="px-3 py-2 text-left">Date</th>
                        <th class="px-3 py-2 text-center">Début → fin</th>
                        <th class="px-3 py-2 text-left">Incident</th>
                        <th class="px-3 py-2 text-left">Par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($transfusions as $tr)
                    <tr class="{{ $tr->avecIncident() ? 'bg-red-50' : '' }}">
                        <td class="px-3 py-2 text-xs">{{ $tr->libelleProduit() }}</td>
                        <td class="px-3 py-2 text-center font-semibold">{{ $tr->groupe_donneur }}</td>
                        <td class="px-3 py-2 text-center font-semibold">{{ $tr->groupe_receveur }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $tr->numero_poche }}</td>
                        <td class="px-3 py-2 text-right">{{ $tr->quantite }} mL</td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $tr->jour->format('d/m/Y') }}</td>
                        <td class="px-3 py-2 text-center text-xs whitespace-nowrap">
                            {{ \Illuminate\Support\Str::of($tr->heure_debut)->limit(5, '') }}
                            →
                            @if($tr->heure_fin)
                                {{ \Illuminate\Support\Str::of($tr->heure_fin)->limit(5, '') }}
                                <span class="text-gray-400">({{ $tr->dureeMinutes() }} min)</span>
                            @else
                                <span class="text-amber-700 font-semibold">en cours</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs {{ $tr->avecIncident() ? 'text-red-700 font-semibold' : 'text-gray-500' }}">
                            {{ $tr->libelleIncident() }}
                        </td>
                        <td class="px-3 py-2 text-gray-500 text-xs">{{ $tr->auteur?->nom }}</td>
                    </tr>
                    @if($tr->enCours() && ! $clos)
                    <tr class="bg-amber-50">
                        <td colspan="9" class="px-3 py-2">
                            <form method="POST" action="{{ route('infirmier.transfusion.terminer', $tr) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <div>
                                    <label for="fin-{{ $tr->id }}" class="block text-[11px] text-gray-600">Heure de fin</label>
                                    <input id="fin-{{ $tr->id }}" type="time" name="heure_fin" required value="{{ now()->format('H:i') }}"
                                           class="border border-gray-300 rounded px-2 py-1 text-sm">
                                </div>
                                <div>
                                    <label for="inc-{{ $tr->id }}" class="block text-[11px] text-gray-600">Incident constaté</label>
                                    <select id="inc-{{ $tr->id }}" name="incident" required class="border border-gray-300 rounded px-2 py-1 text-sm">
                                        @foreach(\App\Models\Transfusion::INCIDENTS as $c => $l)
                                        <option value="{{ $c }}" @selected($tr->incident === $c)>{{ $l }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex-1 min-w-40">
                                    <label for="tobs-{{ $tr->id }}" class="block text-[11px] text-gray-600">Observation</label>
                                    <input id="tobs-{{ $tr->id }}" name="observation" maxlength="500" value="{{ $tr->observation }}"
                                           class="w-full border border-gray-300 rounded px-2 py-1 text-sm">
                                </div>
                                <button class="bg-blue-700 hover:bg-blue-800 text-white rounded px-4 py-1.5 text-sm font-semibold">
                                    Terminer la poche
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endif
                    @empty
                    <tr><td colspan="9" class="px-3 py-8 text-center text-gray-400">Aucune transfusion enregistrée</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    </div>
</div>
@endsection
