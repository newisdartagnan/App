{{-- Champs communs aux deux formulaires de programmation : patient, poste,
     type de séance, abord vasculaire. --}}
@php $prefixe = $prefixe ?? 's'; @endphp

<div class="sm:col-span-2">
    <label for="{{ $prefixe }}-patient" class="block text-xs font-semibold text-gray-600 mb-1">
        Patient <span class="text-red-500">*</span>
    </label>
    <input id="{{ $prefixe }}-patient" name="patient_id" required list="liste-patients-dialyse"
           value="{{ old('patient_id') }}" placeholder="Identifiant du patient"
           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm font-mono">
    <datalist id="liste-patients-dialyse">
        @foreach(\App\Models\Patient::orderByDesc('created_at')->limit(300)->get() as $p)
        <option value="{{ $p->id }}">{{ $p->dossier_number }} — {{ $p->nom_complet }}</option>
        @endforeach
    </datalist>
</div>

<div>
    <label for="{{ $prefixe }}-gen" class="block text-xs font-semibold text-gray-600 mb-1">
        Générateur <span class="text-red-500">*</span>
    </label>
    <select id="{{ $prefixe }}-gen" name="generateur_id" required
            class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
        <option value="">—</option>
        @foreach($generateurs as $generateur)
        <option value="{{ $generateur->id }}" @selected(old('generateur_id') === $generateur->id)>
            {{ $generateur->nom }}@if($generateur->reserve_hbs) (AgHBs)@endif
        </option>
        @endforeach
    </select>
</div>
<div>
    <label for="{{ $prefixe }}-type" class="block text-xs font-semibold text-gray-600 mb-1">
        Type <span class="text-red-500">*</span>
    </label>
    <select id="{{ $prefixe }}-type" name="type" required
            class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
        @foreach($types as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('type') === $cle)>{{ $libelle }}</option>
        @endforeach
    </select>
</div>
<div>
    <label for="{{ $prefixe }}-abord" class="block text-xs font-semibold text-gray-600 mb-1">Abord vasculaire</label>
    <select id="{{ $prefixe }}-abord" name="abord" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
        <option value="">—</option>
        @foreach($abords as $cle => $libelle)
        <option value="{{ $cle }}" @selected(old('abord') === $cle)>{{ $libelle }}</option>
        @endforeach
    </select>
</div>
<div>
    <label for="{{ $prefixe }}-sec" class="block text-xs font-semibold text-gray-600 mb-1">Poids sec (kg)</label>
    <input id="{{ $prefixe }}-sec" name="poids_sec_kg" type="number" step="0.1" min="10" max="250"
           value="{{ old('poids_sec_kg') }}"
           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
</div>
<div class="sm:col-span-2">
    <label for="{{ $prefixe }}-nephro" class="block text-xs font-semibold text-gray-600 mb-1">Néphrologue</label>
    <select id="{{ $prefixe }}-nephro" name="nephrologue_id" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
        <option value="">—</option>
        @foreach($nephrologues as $medecin)
        <option value="{{ $medecin->id }}" @selected(old('nephrologue_id') === $medecin->id)>
            {{ $medecin->nom }} {{ $medecin->prenom }}
        </option>
        @endforeach
    </select>
</div>
