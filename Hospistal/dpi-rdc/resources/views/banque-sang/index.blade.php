@extends('layouts.app')
@section('title', 'Banque de sang')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🩸 Banque de sang</h2>
    <p class="text-sm text-gray-500 mb-5">
        Ce qu'il y a au réfrigérateur ce soir, et qui peut donner demain. Une
        poche ne sort de quarantaine qu'avec un dépistage complet et négatif.
    </p>

    @include('banque-sang._onglets')
    @include('partials._flash')

    {{-- Stock par groupe : la lecture de première seconde --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Poches disponibles par groupe
            <span class="text-gray-400 font-normal text-sm">
                — {{ $stock['delivrables'] }} délivrable(s) sur {{ $stock['total'] }}
            </span>
        </div>
        <div class="grid grid-cols-4 md:grid-cols-8 gap-px bg-gray-100">
            @foreach($stock['par_groupe'] as $groupe => $nombre)
            @php $donneurs = $stock['donneurs_joignables'][$groupe] ?? 0; @endphp
            <a href="{{ route('banque-sang.index', ['groupe' => $groupe]) }}"
               class="bg-white p-4 text-center hover:bg-blue-50 {{ $groupeRecherche === $groupe ? 'ring-2 ring-inset ring-blue-400' : '' }}">
                <p class="text-xs font-mono text-gray-500">{{ $groupe }}</p>
                <p class="text-2xl font-bold {{ $nombre === 0 ? 'text-red-600' : 'text-gray-800' }}">{{ $nombre }}</p>
                <p class="text-[10px] text-gray-400">{{ $donneurs }} donneur(s)</p>
            </a>
            @endforeach
        </div>
        @if($stock['quarantaine'] > 0 || $stock['perime_bientot'] > 0)
        <div class="px-5 py-3 border-t text-xs text-gray-600 flex flex-wrap gap-4">
            @if($stock['quarantaine'] > 0)
            <span>⏳ {{ $stock['quarantaine'] }} poche(s) en quarantaine, à dépister</span>
            @endif
            @if($stock['perime_bientot'] > 0)
            <span class="text-amber-800">⚠️ {{ $stock['perime_bientot'] }} poche(s) périment dans moins de 8 jours</span>
            @endif
        </div>
        @endif
    </div>

    {{-- Demandes en attente --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Demandes des services
            <span class="text-gray-400 font-normal text-sm">— {{ $demandesOuvertes->count() }} ouverte(s)</span>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($demandesOuvertes as $demande)
            <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-3 {{ $demande->urgence ? 'bg-red-50/50' : '' }}">
                <div>
                    <p class="text-sm font-medium">
                        {{ $demande->patient->nom_complet }}
                        @if($demande->urgence)
                        <span class="ml-1 text-xs bg-red-600 text-white px-2 py-0.5 rounded-full">URGENT</span>
                        @endif
                    </p>
                    <p class="text-xs text-gray-500">
                        {{ $demande->numero }} ·
                        {{ $demande->groupeReceveur() ?: 'groupe inconnu' }} ·
                        {{ $demande->nombre_poches }} poche(s) demandée(s),
                        {{ $demande->pochesRestantes() }} restante(s)
                        @if($demande->indication) · {{ $demande->indication }} @endif
                    </p>
                </div>
                <a href="{{ route('banque-sang.demande', $demande) }}"
                   class="text-sm text-blue-700 hover:underline">Servir →</a>
            </div>
            @empty
            <p class="px-5 py-8 text-center text-gray-400 text-sm">Aucune demande en attente.</p>
            @endforelse
        </div>

        <details class="border-t">
            <summary class="px-5 py-3 cursor-pointer text-sm font-medium text-blue-700 select-none">
                ➕ Nouvelle demande de sang
            </summary>
            <form method="POST" action="{{ route('banque-sang.demander') }}" class="grid md:grid-cols-4 gap-3 px-5 pb-5 pt-2">
                @csrf
                <div class="md:col-span-2">
                    <label for="d-patient" class="block text-xs font-semibold text-gray-600 mb-1">
                        Patient <span class="text-red-500">*</span>
                    </label>
                    <input id="d-patient" name="patient_id" required list="liste-receveurs"
                           value="{{ old('patient_id') }}" placeholder="Identifiant du patient"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm font-mono">
                    <datalist id="liste-receveurs">
                        @foreach(\App\Models\Patient::orderByDesc('created_at')->limit(300)->get() as $p)
                        <option value="{{ $p->id }}">{{ $p->dossier_number }} — {{ $p->nom_complet }}{{ $p->groupe_sanguin ? ' ('.$p->groupe_sanguin.')' : '' }}</option>
                        @endforeach
                    </datalist>
                </div>
                <div>
                    <label for="d-groupe" class="block text-xs font-semibold text-gray-600 mb-1">
                        Groupe du receveur
                    </label>
                    <select id="d-groupe" name="groupe_demande" class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        <option value="">celui du dossier</option>
                        @foreach($groupes as $groupe)
                        <option value="{{ $groupe }}" @selected(old('groupe_demande') === $groupe)>{{ $groupe }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="d-produit" class="block text-xs font-semibold text-gray-600 mb-1">
                        Produit <span class="text-red-500">*</span>
                    </label>
                    <select id="d-produit" name="type_produit" required class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        @foreach($produits as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('type_produit') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="d-nb" class="block text-xs font-semibold text-gray-600 mb-1">
                        Nombre de poches <span class="text-red-500">*</span>
                    </label>
                    <input id="d-nb" name="nombre_poches" type="number" min="1" max="20" required
                           value="{{ old('nombre_poches', 1) }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div>
                    <label for="d-hb" class="block text-xs font-semibold text-gray-600 mb-1">Hémoglobine (g/dL)</label>
                    <input id="d-hb" name="hemoglobine" type="number" step="0.1" min="1" max="25"
                           value="{{ old('hemoglobine') }}"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="d-ind" class="block text-xs font-semibold text-gray-600 mb-1">Indication</label>
                    <input id="d-ind" name="indication" maxlength="1000" value="{{ old('indication') }}"
                           placeholder="Anémie sévère du post-partum, hémorragie digestive…"
                           class="w-full border border-gray-300 rounded-lg px-2 py-2 text-sm">
                </div>

                <div class="md:col-span-4 flex flex-wrap gap-4 items-center">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="urgence" value="1" @checked(old('urgence')) class="rounded">
                        Urgence vitale
                    </label>
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Ouvrir la demande
                    </button>
                </div>
            </form>
        </details>
    </div>

    {{-- Stock détaillé --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700 flex flex-wrap items-center justify-between gap-2">
            <span>
                Poches en stock
                @if($groupeRecherche)
                <span class="text-sm font-normal text-gray-500">— groupe {{ $groupeRecherche }}</span>
                @endif
            </span>
            @if($groupeRecherche)
            <a href="{{ route('banque-sang.index') }}" class="text-xs text-blue-700 hover:underline">Voir tous les groupes</a>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Poche</th>
                        <th class="px-4 py-3">Groupe</th>
                        <th class="px-4 py-3">Produit</th>
                        <th class="px-4 py-3">Donneur</th>
                        <th class="px-4 py-3">Péremption</th>
                        <th class="px-4 py-3">État</th>
                        <th class="px-4 py-3">Dépistage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($poches as $poche)
                    @php $jours = $poche->joursAvantPeremption(); @endphp
                    <tr class="{{ $jours <= 7 ? 'bg-amber-50/50' : '' }}">
                        <td class="px-4 py-3 font-mono text-xs">{{ $poche->numero }}</td>
                        <td class="px-4 py-3 font-bold">{{ $poche->groupe_sanguin }}</td>
                        <td class="px-4 py-3 text-xs">{{ $poche->libelleProduit() }} · {{ $poche->volume_ml }} ml</td>
                        <td class="px-4 py-3 text-xs">{{ $poche->donneur?->nomComplet() ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap">
                            {{ $poche->date_peremption->format('d/m/Y') }}
                            <span class="{{ $jours <= 7 ? 'text-amber-800 font-semibold' : 'text-gray-400' }}">
                                ({{ $jours }} j)
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs
                                {{ $poche->estDelivrable() ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900' }}">
                                {{ $poche->libelleStatut() }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if($poche->depistageComplet())
                            <span class="text-xs text-green-700">✓ négatif le {{ $poche->date_depistage?->format('d/m/Y') }}</span>
                            @else
                            <details>
                                <summary class="cursor-pointer text-xs text-blue-700 select-none">Saisir le dépistage</summary>
                                <form method="POST" action="{{ route('banque-sang.depister', $poche) }}" class="mt-2">
                                    @csrf
                                    <p class="text-xs text-gray-500 mb-1">Cochez uniquement les marqueurs <strong>positifs</strong>.</p>
                                    @foreach([
                                        'depistage_vih' => 'VIH',
                                        'depistage_hepatite_b' => 'Hépatite B',
                                        'depistage_hepatite_c' => 'Hépatite C',
                                        'depistage_syphilis' => 'Syphilis',
                                        'depistage_paludisme' => 'Paludisme',
                                    ] as $champ => $libelle)
                                    <label class="flex items-center gap-2 text-xs text-gray-700">
                                        <input type="checkbox" name="{{ $champ }}" value="1" class="rounded">
                                        {{ $libelle }} positif
                                    </label>
                                    @endforeach
                                    <button class="mt-2 bg-blue-700 hover:bg-blue-800 text-white rounded px-3 py-1 text-xs font-semibold">
                                        Valider le dépistage
                                    </button>
                                </form>
                            </details>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">
                        Aucune poche en stock{{ $groupeRecherche ? ' pour ce groupe' : '' }}.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
