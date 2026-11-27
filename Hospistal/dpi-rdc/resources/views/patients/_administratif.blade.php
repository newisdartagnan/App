{{--
    Partie administrative du dossier : identité (avec Modifier, Imprimer
    carte, Empreintes, Photo), relations familiales, méthodes de paiement,
    vaccinations.
--}}
@php
    $peutModifier = auth()->user()->can('patient.update') || auth()->user()->hasAnyRole(['super_admin', 'directeur']);
    $proches = \App\Http\Controllers\PatientIdentiteController::proches($patient);
    $methodesPatient = $patient->methodesPaiement()->with('methode')->get();
    $vaccins = $patient->vaccinations()->orderBy('date_vaccination')->get();
    $etats = ['celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'divorce' => 'Divorcé(e)', 'veuf' => 'Veuf (ve)', 'union_libre' => 'Union libre', 'inconnu' => 'Non précisé'];
@endphp

<section class="bg-white rounded-xl shadow mb-6 overflow-hidden" id="identite">
    <header class="flex items-center gap-3 px-5 py-3 bg-green-50 border-b">
        <span class="text-2xl" aria-hidden="true">🪪</span>
        <div><h3 class="font-semibold text-green-800">Identité</h3><p class="text-xs text-gray-600">Informations générales du patient</p></div>
    </header>
    <div class="p-5 flex flex-col md:flex-row gap-5">
        <div class="shrink-0">
            @if($patient->photoUrl())
                <img src="{{ $patient->photoUrl() }}" alt="Photo de {{ $patient->nom_complet }}" class="w-28 h-32 object-cover rounded-lg border">
            @else
                <div class="w-28 h-32 rounded-lg border bg-gray-50 flex items-center justify-center text-4xl text-gray-300" aria-hidden="true">👤</div>
            @endif
        </div>
        <table class="flex-1 text-sm border">
            <tbody class="divide-y">
                <tr><th class="text-left px-3 py-2 w-48 bg-gray-50">N° dossier</th><td class="px-3 py-2 font-mono">{{ $patient->dossier_number }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Nom complet</th><td class="px-3 py-2 font-semibold">{{ trim($patient->nom.' '.$patient->postnom.' '.$patient->prenom) }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Sexe</th><td class="px-3 py-2">{{ ['M' => 'Masculin', 'F' => 'Féminin'][$patient->sexe] ?? 'Inconnu' }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Date de naissance</th><td class="px-3 py-2">{{ $patient->date_naissance?->format('d/m/Y') ?? '—' }} <span class="text-gray-500">— {{ $patient->libelleAge() }}</span></td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Lieu de naissance</th><td class="px-3 py-2">{{ $patient->lieu_naissance ?? '—' }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Nationalité</th><td class="px-3 py-2">{{ $patient->nationalite ?? '—' }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Adresse</th><td class="px-3 py-2">{{ $patient->adresse ?? '—' }}{{ $patient->province ? ' — '.$patient->province : '' }}{{ $patient->territoire ? ', '.$patient->territoire : '' }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Téléphone</th><td class="px-3 py-2">{{ $patient->telephone ?? '—' }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">État civil</th><td class="px-3 py-2">{{ $etats[$patient->situation_matrimoniale] ?? $patient->situation_matrimoniale }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Profession</th><td class="px-3 py-2">{{ $patient->profession ?? '—' }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Prise en charge</th><td class="px-3 py-2">{{ $patient->libellePriseEnCharge() }}</td></tr>
                @php $caracteristiques = $patient->caracteristiquesSnis(); @endphp
                @if($caracteristiques)
                <tr><th class="text-left px-3 py-2 bg-gray-50">Caractéristiques</th><td class="px-3 py-2">{{ implode(' · ', $caracteristiques) }}</td></tr>
                @endif
                @if($patient->contact_urgence_nom)
                <tr><th class="text-left px-3 py-2 bg-gray-50">Personne à contacter</th><td class="px-3 py-2">{{ $patient->contact_urgence_nom }} ({{ $patient->contact_urgence_lien }}) — {{ $patient->contact_urgence_telephone }}</td></tr>
                @endif
                <tr><th class="text-left px-3 py-2 bg-gray-50">Autres détails</th><td class="px-3 py-2">{{ $patient->autres_details ?? '—' }}</td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Date d'enregistrement</th><td class="px-3 py-2">{{ $patient->created_at?->format('d/m/Y') }} <span class="text-gray-500">{{ $patient->created_at?->format('H:i') }}, {{ $patient->created_at?->diffForHumans() }}</span></td></tr>
                <tr><th class="text-left px-3 py-2 bg-gray-50">Enregistré par</th><td class="px-3 py-2">{{ $patient->enregistrePar?->nom_complet ?? '—' }}</td></tr>
            </tbody>
        </table>
        <div class="flex md:flex-col gap-2 flex-wrap shrink-0">
            @if($peutModifier)
            <a href="{{ route('patients.edit', $patient) }}" class="inline-flex items-center min-h-[44px] px-4 rounded-lg border text-blue-700 hover:bg-blue-50 text-sm font-semibold">✎ Modifier</a>
            @endif
            <a href="{{ route('patients.carte', $patient) }}" target="_blank" class="inline-flex items-center min-h-[44px] px-4 rounded-lg border text-blue-700 hover:bg-blue-50 text-sm font-semibold">🖨 Imprimer carte</a>
            @if($peutModifier)
            <a href="{{ route('patients.empreintes', $patient) }}" class="inline-flex items-center min-h-[44px] px-4 rounded-lg border text-blue-700 hover:bg-blue-50 text-sm font-semibold">☝ Empreintes <span class="ml-1 text-xs text-gray-500">({{ $patient->empreintes()->count() }})</span></a>
            <details class="relative">
                <summary class="inline-flex items-center min-h-[44px] px-4 rounded-lg border text-blue-700 hover:bg-blue-50 text-sm font-semibold cursor-pointer list-none">📷 Photo</summary>
                <form method="POST" action="{{ route('patients.photo.store', $patient) }}" enctype="multipart/form-data" class="mt-2 p-3 border rounded-lg bg-white space-y-2 w-64">
                    @csrf
                    <label for="photo-patient" class="block text-xs text-gray-600">Prendre une photo (tablette) ou choisir un fichier</label>
                    <input id="photo-patient" type="file" name="photo" accept="image/*" capture="user" required class="block w-full text-xs">
                    <button class="min-h-[36px] px-3 bg-blue-700 text-white rounded-lg text-xs font-semibold">Enregistrer</button>
                </form>
            </details>
            @endif
        </div>
    </div>
</section>

<section class="bg-white rounded-xl shadow mb-6 overflow-hidden" id="relations">
    <header class="px-5 py-3 bg-green-50 border-b"><h3 class="font-semibold text-green-800">👪 Relations familiales ({{ $proches->count() }})</h3></header>
    <div class="p-5 space-y-3">
        @if($proches->isNotEmpty())
        <table class="w-full text-sm border">
            <thead class="bg-gray-50"><tr><th class="text-left px-3 py-2">Patient</th><th class="text-left px-3 py-2">N° dossier</th><th class="text-left px-3 py-2">Parenté avec {{ $patient->prenom }}</th><th></th></tr></thead>
            <tbody class="divide-y">
            @foreach($proches as $p)
                <tr>
                    <td class="px-3 py-2"><a href="{{ route('patients.show', $p->proche) }}" class="text-blue-700 hover:underline font-medium">{{ $p->proche->nom_complet }}</a></td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $p->proche->dossier_number }}</td>
                    <td class="px-3 py-2">{{ \App\Models\RelationFamiliale::LIENS[$p->lien] ?? $p->lien }}{{ $p->relation->precision ? ' — '.$p->relation->precision : '' }}</td>
                    <td class="px-3 py-2 text-right">@if($peutModifier)<form method="POST" action="{{ route('patients.relations.destroy', $p->relation) }}">@csrf @method('DELETE')<button class="text-xs text-red-700 hover:underline">Retirer</button></form>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @else
        <p class="text-sm text-gray-500">Aucune relation familiale enregistrée.</p>
        @endif
        @if($peutModifier)
        <details>
            <summary class="text-sm text-blue-700 font-semibold cursor-pointer">⊕ Ajouter une relation familiale</summary>
            <form method="POST" action="{{ route('patients.relations.store', $patient) }}" class="mt-3 grid md:grid-cols-4 gap-3 items-end">
                @csrf
                <div class="md:col-span-2">
                    <label for="rel-recherche" class="block text-xs font-semibold mb-1">Patient enregistré (nom ou n° dossier)</label>
                    <input id="rel-recherche" type="search" autocomplete="off" placeholder="2 lettres minimum…" class="w-full min-h-[44px] border rounded-lg px-3"
                           data-url="{{ route('patients.recherche') }}">
                    <input type="hidden" name="proche_id" id="rel-proche">
                    <div id="rel-resultats" class="mt-1 space-y-1"></div>
                </div>
                <div><label for="rel-lien" class="block text-xs font-semibold mb-1">Parenté avec {{ $patient->prenom }}</label>
                    <select id="rel-lien" name="lien" class="w-full min-h-[44px] border rounded-lg px-3">@foreach(\App\Models\RelationFamiliale::LIENS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div><button class="min-h-[44px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold text-sm">Valider</button></div>
            </form>
            <script>
            (function () {
                var champ = document.getElementById('rel-recherche'), zone = document.getElementById('rel-resultats'), cache = document.getElementById('rel-proche'), t;
                if (!champ) return;
                champ.addEventListener('input', function () {
                    clearTimeout(t); cache.value = '';
                    var q = champ.value.trim(); if (q.length < 2) { zone.innerHTML = ''; return; }
                    t = setTimeout(function () {
                        fetch(champ.dataset.url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); }).then(function (d) {
                                zone.innerHTML = '';
                                (d.patients || []).filter(function (p) { return p.id !== @json($patient->id); }).forEach(function (p) {
                                    var b = document.createElement('button'); b.type = 'button';
                                    b.className = 'block w-full text-left px-3 py-2 border rounded hover:bg-blue-50 text-sm';
                                    b.textContent = p.nom_complet + ' — ' + (p.dossier || '');
                                    b.addEventListener('click', function () { cache.value = p.id; champ.value = p.nom_complet; zone.innerHTML = ''; });
                                    zone.appendChild(b);
                                });
                            });
                    }, 250);
                });
            })();
            </script>
        </details>
        @endif
    </div>
</section>

<section class="bg-white rounded-xl shadow mb-6 overflow-hidden" id="methodes-paiement">
    <header class="px-5 py-3 bg-green-50 border-b"><h3 class="font-semibold text-green-800">💳 Méthodes de paiement ({{ $methodesPatient->count() }})</h3></header>
    <div class="p-5 space-y-3">
        @if($methodesPatient->isNotEmpty())
        <table class="w-full text-sm border">
            <thead class="bg-gray-50"><tr><th class="text-left px-3 py-2">Méthode</th><th class="text-left px-3 py-2">Identifiant du patient</th><th class="text-left px-3 py-2">Début</th><th class="text-left px-3 py-2">Fin</th><th></th></tr></thead>
            <tbody class="divide-y">
            @foreach($methodesPatient as $lien)
                <tr class="{{ $lien->enVigueur() ? '' : 'opacity-50' }}">
                    <td class="px-3 py-2 font-medium">{{ $lien->methode?->libelle }} @unless($lien->enVigueur())<span class="text-xs text-amber-700">(hors période)</span>@endunless</td>
                    <td class="px-3 py-2">{{ $lien->identifiant ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $lien->date_debut?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $lien->date_fin?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">@if($peutModifier || auth()->user()->can('facture.create'))<form method="POST" action="{{ route('patients.methodes.destroy', $lien) }}">@csrf @method('DELETE')<button class="text-xs text-red-700 hover:underline">Retirer</button></form>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @else
        <p class="text-sm text-gray-500">Aucune méthode de paiement enregistrée — paiement direct par défaut.</p>
        @endif
        @if($peutModifier || auth()->user()->can('facture.create'))
        <details>
            <summary class="text-sm text-blue-700 font-semibold cursor-pointer">⊕ Ajouter méthode de paiement</summary>
            <form method="POST" action="{{ route('patients.methodes.store', $patient) }}" class="mt-3 grid md:grid-cols-3 gap-3 items-end">
                @csrf
                <div><label for="mp-exist" class="block text-xs font-semibold mb-1">Réutiliser une méthode existante</label>
                    <select id="mp-exist" name="methode_paiement_id" class="w-full min-h-[44px] border rounded-lg px-3"><option value="">— ou en créer une nouvelle —</option>
                        @foreach(\App\Models\MethodePaiement::where('est_actif', true)->orderBy('libelle')->get() as $m)<option value="{{ $m->id }}">{{ $m->libelle }}</option>@endforeach</select></div>
                <div class="md:col-span-2"><label for="mp-libelle" class="block text-xs font-semibold mb-1">Libellé de la méthode de paiement (nouvelle)</label>
                    <input id="mp-libelle" name="libelle" placeholder="Ex. : Abonné mutuelle X" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="mp-ident" class="block text-xs font-semibold mb-1">Identifiant du patient</label>
                    <input id="mp-ident" name="identifiant" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="mp-debut" class="block text-xs font-semibold mb-1">Date début</label>
                    <input id="mp-debut" type="date" name="date_debut" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="mp-fin" class="block text-xs font-semibold mb-1">Date fin</label>
                    <input id="mp-fin" type="date" name="date_fin" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><button class="min-h-[44px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold text-sm">Valider</button></div>
            </form>
        </details>
        @endif
    </div>
</section>

<section class="bg-white rounded-xl shadow mb-6 overflow-hidden" id="vaccinations">
    <header class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 bg-green-50 border-b">
        <h3 class="font-semibold text-green-800">💉 Vaccinations ({{ $vaccins->count() }})</h3>
        <a href="{{ route('patients.carte-vaccination', $patient) }}" target="_blank" class="text-sm text-blue-700 hover:underline font-semibold">🖨 Imprimer carte de vaccination</a>
    </header>
    <div class="p-5 space-y-3">
        @if($vaccins->isNotEmpty())
        <table class="w-full text-sm border">
            <thead class="bg-gray-50"><tr><th class="text-left px-3 py-2">Vaccin</th><th class="text-left px-3 py-2">Date d'administration</th><th class="text-left px-3 py-2">Lot</th></tr></thead>
            <tbody class="divide-y">
            @foreach($vaccins as $v)<tr><td class="px-3 py-2">{{ $v->libelleVaccin() }}</td><td class="px-3 py-2">{{ $v->date_vaccination?->format('d/m/Y') }}</td><td class="px-3 py-2">{{ $v->lot }}</td></tr>@endforeach
            </tbody>
        </table>
        @else
        <p class="text-sm text-gray-500">Aucune vaccination enregistrée.</p>
        @endif
        @can('soin.execute')
        <details>
            <summary class="text-sm text-blue-700 font-semibold cursor-pointer">⊕ Ajouter une vaccination</summary>
            <form method="POST" action="{{ route('prevention.vaccination.store', $patient) }}" class="mt-3 grid md:grid-cols-4 gap-3 items-end">
                @csrf
                <input type="hidden" name="retour" value="dossier">
                <input type="hidden" name="strategie" value="fixe">
                <div><label for="vac-nom" class="block text-xs font-semibold mb-1">Nom du vaccin</label>
                    <select id="vac-nom" name="antigene" class="w-full min-h-[44px] border rounded-lg px-3">
                        @foreach(\App\Models\Vaccination::ANTIGENES as $k => $a)<option value="{{ $k }}">{{ $a['libelle'] }}</option>@endforeach
                        <option value="autre">Autre…</option>
                    </select></div>
                <div><label for="vac-libre" class="block text-xs font-semibold mb-1">Si « Autre » : nom du vaccin</label>
                    <input id="vac-libre" name="antigene_libre" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="vac-date" class="block text-xs font-semibold mb-1">Date d'administration</label>
                    <input id="vac-date" type="date" name="date_vaccination" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><button class="min-h-[44px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold text-sm">Enregistrer</button></div>
            </form>
        </details>
        @endcan
    </div>
</section>
