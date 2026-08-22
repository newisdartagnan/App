@extends('layouts.app')
@section('title', 'Banque de sang — donneurs')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🩸 Fichier des donneurs</h2>
    <p class="text-sm text-gray-500 mb-5">
        Le vrai stock de l'hôpital. Le réfrigérateur se vide en une nuit ; ce
        fichier, lui, permet d'appeler quelqu'un à trois heures du matin.
    </p>

    @include('banque-sang._onglets')
    @include('partials._flash')

    {{-- Recherche par receveur : la question qu'on se pose en urgence --}}
    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-receveur" class="block text-xs font-semibold text-gray-600 mb-1">
                Compatibles avec un receveur de groupe
            </label>
            <select id="f-receveur" name="pour_receveur" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">—</option>
                @foreach($groupes as $groupe)
                <option value="{{ $groupe }}" @selected($pourReceveur === $groupe)>{{ $groupe }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-groupe" class="block text-xs font-semibold text-gray-600 mb-1">Groupe du donneur</label>
            <select id="f-groupe" name="groupe" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Tous</option>
                @foreach($groupes as $g)
                <option value="{{ $g }}" @selected($groupe === $g)>{{ $g }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-recherche" class="block text-xs font-semibold text-gray-600 mb-1">Nom, code ou téléphone</label>
            <input id="f-recherche" name="recherche" value="{{ $recherche }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Rechercher</button>
    </form>

    @if($pourReceveur)
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-4 text-sm text-blue-900">
        Un receveur <strong>{{ $pourReceveur }}</strong> accepte du sang des groupes
        <strong>{{ implode(', ', \App\Models\PocheSang::groupesCompatiblesPour($pourReceveur, 'concentre_globulaire')) }}</strong>
        en globules rouges.
    </div>
    @endif

    <details class="bg-white rounded-xl shadow mb-5" {{ $errors->any() ? 'open' : '' }}>
        <summary class="px-5 py-3 font-semibold text-gray-700 cursor-pointer select-none">
            ➕ Inscrire un donneur
        </summary>
        <div class="px-5 pb-5 border-t pt-4">
            <form method="POST" action="{{ route('banque-sang.donneurs.store') }}" class="grid md:grid-cols-4 gap-3">
                @csrf
                <div>
                    <label for="n-nom" class="block text-xs font-semibold text-gray-600 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input id="n-nom" name="nom" required maxlength="100" value="{{ old('nom') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="n-postnom" class="block text-xs font-semibold text-gray-600 mb-1">Postnom</label>
                    <input id="n-postnom" name="postnom" maxlength="100" value="{{ old('postnom') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="n-prenom" class="block text-xs font-semibold text-gray-600 mb-1">Prénom</label>
                    <input id="n-prenom" name="prenom" maxlength="100" value="{{ old('prenom') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="n-groupe" class="block text-xs font-semibold text-gray-600 mb-1">
                        Groupe <span class="text-red-500">*</span>
                    </label>
                    <select id="n-groupe" name="groupe_sanguin" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        <option value="">—</option>
                        @foreach($groupes as $g)
                        <option value="{{ $g }}" @selected(old('groupe_sanguin') === $g)>{{ $g }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="n-sexe" class="block text-xs font-semibold text-gray-600 mb-1">Sexe</label>
                    <select id="n-sexe" name="sexe" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        <option value="">—</option>
                        <option value="M" @selected(old('sexe') === 'M')>Masculin</option>
                        <option value="F" @selected(old('sexe') === 'F')>Féminin</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Fixe le délai entre deux dons.</p>
                </div>
                <div>
                    <label for="n-naissance" class="block text-xs font-semibold text-gray-600 mb-1">Date de naissance</label>
                    <input id="n-naissance" name="date_naissance" type="date" value="{{ old('date_naissance') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="n-tel" class="block text-xs font-semibold text-gray-600 mb-1">Téléphone</label>
                    <input id="n-tel" name="telephone" maxlength="50" value="{{ old('telephone') }}"
                           placeholder="+243…"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="n-type" class="block text-xs font-semibold text-gray-600 mb-1">
                        Type <span class="text-red-500">*</span>
                    </label>
                    <select id="n-type" name="type_donneur" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        @foreach($types as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('type_donneur') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label for="n-adresse" class="block text-xs font-semibold text-gray-600 mb-1">Adresse</label>
                    <input id="n-adresse" name="adresse" maxlength="255" value="{{ old('adresse') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="n-notes" class="block text-xs font-semibold text-gray-600 mb-1">Notes</label>
                    <input id="n-notes" name="notes" maxlength="1000" value="{{ old('notes') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>

                <div class="md:col-span-4">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Inscrire au fichier
                    </button>
                </div>
            </form>
        </div>
    </details>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Donneur</th>
                        <th class="px-4 py-3">Groupe</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Téléphone</th>
                        <th class="px-4 py-3 text-center">Dons</th>
                        <th class="px-4 py-3">Dernier don</th>
                        <th class="px-4 py-3">Disponibilité</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($donneurs as $donneur)
                    @php $dispo = $donneur->peutDonnerMaintenant(); @endphp
                    <tr class="{{ $donneur->est_eligible ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $donneur->nomComplet() }}</p>
                            <p class="text-xs text-gray-400 font-mono">{{ $donneur->code }}</p>
                        </td>
                        <td class="px-4 py-3 font-bold">{{ $donneur->groupe_sanguin }}</td>
                        <td class="px-4 py-3 text-xs">{{ $donneur->libelleType() }}</td>
                        <td class="px-4 py-3 text-xs">{{ $donneur->telephone ?: '—' }}</td>
                        <td class="px-4 py-3 text-center">{{ $donneur->nombre_dons }}</td>
                        <td class="px-4 py-3 text-xs">{{ $donneur->dernier_don?->format('d/m/Y') ?? 'jamais' }}</td>
                        <td class="px-4 py-3 text-xs">
                            @if($dispo)
                            <span class="text-green-700 font-semibold">✓ Peut donner</span>
                            @else
                            <span class="text-gray-500">{{ $donneur->motifIndisponibilite() }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            {{-- Écarter n'est pas toujours une sérologie : poids, grossesse,
                                 traitement, refus. Cela doit se poser et se lever à la main. --}}
                            @if($donneur->est_eligible)
                            <details class="mb-1">
                                <summary class="cursor-pointer text-xs text-red-700 select-none">Écarter du fichier</summary>
                                <form method="POST" action="{{ route('banque-sang.eligibilite', $donneur) }}" class="mt-2 flex flex-col gap-1">
                                    @csrf
                                    <input type="hidden" name="eligible" value="0">
                                    <label for="mx-{{ $donneur->id }}" class="sr-only">Motif de l'exclusion</label>
                                    <input id="mx-{{ $donneur->id }}" name="motif_exclusion" required maxlength="255"
                                           placeholder="Poids insuffisant, grossesse, traitement…"
                                           class="border border-gray-300 rounded px-2 py-1 text-xs">
                                    <button class="bg-red-700 hover:bg-red-800 text-white rounded px-2 py-1 text-xs font-semibold">
                                        Écarter
                                    </button>
                                </form>
                            </details>
                            @else
                            <form method="POST" action="{{ route('banque-sang.eligibilite', $donneur) }}" class="mb-1">
                                @csrf
                                <input type="hidden" name="eligible" value="1">
                                <button class="text-xs text-green-700 hover:underline">Réintégrer au fichier</button>
                            </form>
                            @endif

                            @if($dispo)
                            <details>
                                <summary class="cursor-pointer text-xs text-blue-700 select-none">Enregistrer un don</summary>
                                <form method="POST" action="{{ route('banque-sang.don', $donneur) }}" class="mt-2 flex flex-col gap-1">
                                    @csrf
                                    <label for="p-produit-{{ $donneur->id }}" class="sr-only">Produit</label>
                                    <select id="p-produit-{{ $donneur->id }}" name="type_produit" required
                                            class="border border-gray-300 rounded px-2 py-1 text-xs">
                                        @foreach($produits as $cle => $libelle)
                                        <option value="{{ $cle }}">{{ $libelle }}</option>
                                        @endforeach
                                    </select>
                                    <label for="p-vol-{{ $donneur->id }}" class="sr-only">Volume</label>
                                    <input id="p-vol-{{ $donneur->id }}" name="volume_ml" type="number" min="50" max="1000"
                                           value="450" class="border border-gray-300 rounded px-2 py-1 text-xs">
                                    <label for="p-emp-{{ $donneur->id }}" class="sr-only">Emplacement</label>
                                    <input id="p-emp-{{ $donneur->id }}" name="emplacement" maxlength="100" placeholder="Réfrigérateur A"
                                           class="border border-gray-300 rounded px-2 py-1 text-xs">
                                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded px-2 py-1 text-xs font-semibold">
                                        Prélever
                                    </button>
                                </form>
                            </details>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">
                        Aucun donneur pour ce filtre.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
