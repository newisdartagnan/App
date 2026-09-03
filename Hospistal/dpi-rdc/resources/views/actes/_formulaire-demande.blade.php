@php
    // Le diagnostic de l'épisode, proposé en indication. Calculé ici parce
    // que ce formulaire est inclus depuis trois écrans différents.
    $diagnosticRepris = app(\App\Services\DiagnosticService::class)
        ->pourIndication($visit ?? null);
@endphp
{{--
    Demande d'acte clinique.

    Le prescripteur dit quel acte, pour quel patient, et pourquoi. La salle,
    l'heure et l'opérateur viendront du plateau technique : c'est lui qui
    connaît ses créneaux.

    Variables : $domaine, $catalogue, $sejoursOuverts, $visit (facultatif).
--}}
@php
    $visit = $visit ?? null;
    $routeStore = match ($domaine) {
        'maternite' => 'maternite.store',
        'examen_specialise' => 'examens-specialises.store',
        'dialyse' => 'dialyse.store',
        default => 'bloc.store',
    };
    $suffixe = $visit?->id ?? 'libre';
@endphp

<form method="POST" action="{{ route($routeStore) }}" class="grid md:grid-cols-4 gap-3">
    @csrf
    <input type="hidden" name="domaine" value="{{ $domaine }}">

    @if($visit)
    <input type="hidden" name="visit_id" value="{{ $visit->id }}">
    <div class="md:col-span-4 bg-gray-50 rounded-lg px-3 py-2 text-sm">
        <strong>{{ $visit->patient->nom_complet }}</strong>
        <span class="text-gray-500">
            — {{ $visit->patient->dossier_number }}
            @if($visit->service) · {{ $visit->service->nom }} @endif
            · séjour ouvert depuis le {{ $visit->date_entree->format('d/m/Y') }}
        </span>
    </div>
    @else
    <div class="md:col-span-2">
        <label for="d-visit-{{ $suffixe }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Patient <span class="text-red-500">*</span>
        </label>
        <select id="d-visit-{{ $suffixe }}" name="visit_id" required
                class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
            <option value="">— choisir un séjour ouvert —</option>
            @foreach($sejoursOuverts as $sejour)
            <option value="{{ $sejour->id }}" @selected(old('visit_id') === $sejour->id)>
                {{ $sejour->patient->nom_complet }} ({{ $sejour->patient->dossier_number }})
                — {{ $sejour->service?->nom ?? ucfirst(str_replace('_', ' ', $sejour->type)) }}
            </option>
            @endforeach
        </select>
        <p class="text-xs text-gray-500 mt-1">
            Seuls les séjours ouverts apparaissent : un acte se rattache à un passage.
        </p>
    </div>
    @endif

    <div class="{{ $visit ? 'md:col-span-2' : 'md:col-span-2' }}">
        <label for="d-acte-{{ $suffixe }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Acte à réaliser <span class="text-red-500">*</span>
        </label>
        <select id="d-acte-{{ $suffixe }}" name="libelle" required
                class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
            <option value="">— choisir —</option>
            @foreach($catalogue as $item)
            <option value="{{ $item['libelle'] }}" @selected(old('libelle') === $item['libelle'])>
                {{ $item['libelle'] }} — {{ number_format($item['prix'], 0, ',', ' ') }} CDF
            </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="d-prix-{{ $suffixe }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Prix (CDF) <span class="text-red-500">*</span>
        </label>
        <input id="d-prix-{{ $suffixe }}" name="prix" type="number" min="0" step="1000" required
               value="{{ old('prix', $catalogue[0]['prix'] ?? 0) }}"
               class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
        <p class="text-xs text-gray-500 mt-1">Reprenez le tarif de l'acte choisi.</p>
    </div>
    <div>
        <label for="d-duree-{{ $suffixe }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Durée prévue (min)
        </label>
        <input id="d-duree-{{ $suffixe }}" name="duree_minutes" type="number" min="5" max="1440" step="15"
               value="{{ old('duree_minutes', $catalogue[0]['duree'] ?? 60) }}"
               class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
        <p class="text-xs text-gray-500 mt-1">Elle réservera le créneau.</p>
    </div>

    <div class="md:col-span-2">
        <label for="d-diag-{{ $suffixe }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Diagnostic
        </label>
        <input id="d-diag-{{ $suffixe }}" name="diagnostic_preop" maxlength="1000"
               value="{{ old('diagnostic_preop') }}" placeholder="Hernie inguinale droite, bassin rétréci…"
               class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
    </div>
    <div class="md:col-span-2">
        <label for="d-ind-{{ $suffixe }}" class="block text-xs font-semibold text-gray-600 mb-1">
            Indication
        </label>
        <input id="d-ind-{{ $suffixe }}" name="indication" maxlength="1000"
               value="{{ old('indication', $diagnosticRepris ?? null) }}" placeholder="Motif de la demande"
               class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
        @if(($diagnosticRepris ?? null) && ! old('indication'))
        <p class="text-[11px] text-blue-700 mt-1">Repris du diagnostic de la consultation.</p>
        @endif
    </div>

    <div class="md:col-span-4 flex flex-wrap gap-5 items-center pt-1">
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="urgence" value="1" @checked(old('urgence')) class="rounded">
            Urgence
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="consentement" value="1" @checked(old('consentement')) class="rounded">
            Consentement signé
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="facturer" value="1" @checked(old('facturer')) class="rounded">
            Émettre la facture au guichet
        </label>
        <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
            Enregistrer la demande
        </button>
    </div>

    <p class="md:col-span-4 text-xs text-gray-500">
        La demande part au programme du plateau technique. Tant qu'elle n'est pas
        programmée — salle, créneau, opérateur — puis clôturée par un compte rendu,
        rien n'atteste que l'acte a été réalisé.
    </p>
</form>
