@extends('layouts.app')
@section('title', 'Maternité — grossesses suivies')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">👶 Maternité</h2>
    <p class="text-sm text-gray-500 mb-5">
        Une fiche par grossesse, ouverte à la première consultation prénatale et
        close à l'accouchement. Les termes les plus avancés sont en tête.
    </p>

    @include('maternite._onglets')
    @include('partials._flash')

    {{-- Ouverture d'une fiche --}}
    <details class="bg-white rounded-xl shadow mb-5" {{ $errors->any() ? 'open' : '' }}>
        <summary class="px-5 py-3 font-semibold text-gray-700 cursor-pointer select-none">
            ➕ Ouvrir une fiche obstétricale
        </summary>
        <div class="px-5 pb-5 border-t pt-4">
            <form method="POST" action="{{ route('maternite.grossesses.store') }}" class="grid md:grid-cols-4 gap-3">
                @csrf
                <div class="md:col-span-2">
                    <label for="g-patient" class="block text-xs font-semibold text-gray-600 mb-1">
                        Patiente <span class="text-red-500">*</span>
                    </label>
                    <input id="g-patient" name="patient_id" required value="{{ old('patient_id') }}"
                           placeholder="Identifiant de la patiente"
                           list="liste-patientes"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono">
                    <datalist id="liste-patientes">
                        @foreach(\App\Models\Patient::where('sexe', 'F')->orderByDesc('created_at')->limit(200)->get() as $p)
                        <option value="{{ $p->id }}">{{ $p->dossier_number }} — {{ $p->nom_complet }}</option>
                        @endforeach
                    </datalist>
                    <p class="text-xs text-gray-500 mt-1">
                        Choisissez dans la liste : le dossier et le nom s'affichent à la saisie.
                    </p>
                </div>
                <div>
                    <label for="g-ddr" class="block text-xs font-semibold text-gray-600 mb-1">
                        Dernières règles
                    </label>
                    <input id="g-ddr" name="date_dernieres_regles" type="date" value="{{ old('date_dernieres_regles') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <p class="text-xs text-gray-500 mt-1">Le terme et la date prévue s'en déduisent.</p>
                </div>
                <div>
                    <label for="g-dpa" class="block text-xs font-semibold text-gray-600 mb-1">
                        Terme prévu <span class="text-gray-400 font-normal">(si échographie)</span>
                    </label>
                    <input id="g-dpa" name="date_prevue_accouchement" type="date" value="{{ old('date_prevue_accouchement') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>

                <div>
                    <label for="g-gestite" class="block text-xs font-semibold text-gray-600 mb-1">
                        Gestité <span class="text-red-500">*</span>
                    </label>
                    <input id="g-gestite" name="gestite" type="number" min="1" max="20" required
                           value="{{ old('gestite', 1) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="g-parite" class="block text-xs font-semibold text-gray-600 mb-1">
                        Parité <span class="text-red-500">*</span>
                    </label>
                    <input id="g-parite" name="parite" type="number" min="0" max="20" required
                           value="{{ old('parite', 0) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="g-avort" class="block text-xs font-semibold text-gray-600 mb-1">Avortements</label>
                    <input id="g-avort" name="avortements" type="number" min="0" max="20"
                           value="{{ old('avortements', 0) }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="g-groupe" class="block text-xs font-semibold text-gray-600 mb-1">Groupe sanguin</label>
                    <select id="g-groupe" name="groupe_sanguin" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">—</option>
                        @foreach(\App\Models\PocheSang::GROUPES as $groupe)
                        <option value="{{ $groupe }}" @selected(old('groupe_sanguin') === $groupe)>{{ $groupe }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label for="g-atcd" class="block text-xs font-semibold text-gray-600 mb-1">
                        Antécédents obstétricaux
                    </label>
                    <input id="g-atcd" name="antecedents" maxlength="2000" value="{{ old('antecedents') }}"
                           placeholder="Césarienne en 2023, éclampsie, mort-né…"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="g-risque" class="block text-xs font-semibold text-gray-600 mb-1">
                        Motif de grossesse à risque
                    </label>
                    <input id="g-risque" name="motif_risque" maxlength="500" value="{{ old('motif_risque') }}"
                           placeholder="Utérus cicatriciel, HTA, diabète, primipare âgée…"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>

                <div class="md:col-span-4 flex flex-wrap gap-4 items-center">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="grossesse_a_risque" value="1" @checked(old('grossesse_a_risque')) class="rounded">
                        Grossesse à risque
                    </label>
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Ouvrir la fiche
                    </button>
                </div>
            </form>
        </div>
    </details>

    {{-- Filtres --}}
    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-statut" class="block text-xs font-semibold text-gray-600 mb-1">État</label>
            <select id="f-statut" name="statut" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                @foreach(['en_cours' => 'Grossesses en cours', 'accouchee' => 'Accouchées', 'toutes' => 'Toutes'] as $cle => $libelle)
                <option value="{{ $cle }}" @selected($statut === $cle)>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-recherche" class="block text-xs font-semibold text-gray-600 mb-1">Nom ou dossier</label>
            <input id="f-recherche" name="recherche" value="{{ $recherche }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Filtrer</button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Patiente</th>
                        <th class="px-4 py-3">Formule</th>
                        <th class="px-4 py-3 text-center">Terme</th>
                        <th class="px-4 py-3">Date prévue</th>
                        <th class="px-4 py-3 text-center">CPN</th>
                        <th class="px-4 py-3">État</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($grossesses as $grossesse)
                    @php
                        $terme = $grossesse->termeSemaines();
                        $aTerme = $terme !== null && $terme >= 37;
                        $depasse = $terme !== null && $terme > 41;
                    @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('maternite.show', $grossesse) }}" class="font-medium text-blue-700 hover:underline">
                                {{ $grossesse->patient->nom_complet }}
                            </a>
                            <p class="text-xs text-gray-400">{{ $grossesse->patient->dossier_number }}</p>
                            @if($grossesse->grossesse_a_risque)
                            <span class="text-xs bg-amber-100 text-amber-900 px-1.5 py-0.5 rounded">
                                à risque{{ $grossesse->motif_risque ? ' — '.$grossesse->motif_risque : '' }}
                            </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $grossesse->formuleObstetricale() }}</td>
                        <td class="px-4 py-3 text-center">
                            @if($terme !== null)
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                                {{ $depasse ? 'bg-red-100 text-red-800' : ($aTerme ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800') }}">
                                {{ $terme }} SA
                            </span>
                            @else
                            <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ $grossesse->date_prevue_accouchement?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-center text-xs">{{ $grossesse->consultations->count() }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $grossesse->estEnCours() ? 'bg-blue-100 text-blue-800' : 'bg-gray-200 text-gray-600' }}">
                                {{ $grossesse->libelleStatut() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('maternite.show', $grossesse) }}" class="text-blue-700 hover:underline text-xs">Fiche →</a>
                            <a href="{{ route('maternite.fiche', $grossesse) }}" target="_blank"
                               class="text-gray-600 hover:underline text-xs ml-2">🖨️</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">Aucune grossesse pour ce filtre</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $grossesses->links() }}</div>
</div>
@endsection
