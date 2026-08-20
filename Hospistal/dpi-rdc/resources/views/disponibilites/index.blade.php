@extends('layouts.app')
@section('title', 'Disponibilité des médecins')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🗓️ Disponibilité des médecins</h2>
    <p class="text-sm text-gray-500 mb-5">
        Qui consulte, dans quelle spécialité, et à quelle heure. L'accueil s'y réfère avant
        d'envoyer un patient régler une consultation.
    </p>

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

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-5 flex flex-wrap gap-3 items-end">
        <div>
            <label for="d-jour" class="block text-xs font-semibold text-gray-600 mb-1">Jour</label>
            <input id="d-jour" type="date" name="jour" value="{{ $jour }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="d-heure" class="block text-xs font-semibold text-gray-600 mb-1">Heure</label>
            <input id="d-heure" type="time" name="heure" value="{{ $heure }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Afficher</button>
        <span class="ml-auto text-sm font-semibold text-blue-900">
            {{ \Carbon\Carbon::parse($jour)->locale('fr')->isoFormat('dddd D MMMM YYYY') }} à {{ $heure }}
        </span>
    </form>

    {{-- Couverture par spécialité --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Couverture par spécialité</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Spécialité</th>
                        <th class="px-4 py-3">Consultations concernées</th>
                        <th class="px-4 py-3">Médecins présents</th>
                        <th class="px-4 py-3 text-center">État</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($tableau as $ligne)
                    <tr class="{{ $ligne['disponibles']->isEmpty() ? 'bg-amber-50' : '' }}">
                        <td class="px-4 py-3 font-medium">{{ $ligne['specialite'] }}</td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ implode(', ', $ligne['types']) }}</td>
                        <td class="px-4 py-3 text-xs">
                            @forelse($ligne['disponibles'] as $medecin)
                            <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-800 mr-1 mb-1">
                                {{ $medecin->nom_complet }}
                            </span>
                            @empty
                            <span class="text-gray-500">
                                @if($ligne['tous']->isEmpty())
                                    Aucun médecin enregistré dans cette spécialité
                                @else
                                    Personne à cette heure ({{ $ligne['tous']->count() }} médecin(s) au total)
                                @endif
                            </span>
                            @endforelse
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $ligne['disponibles']->isNotEmpty() ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                {{ $ligne['disponibles']->isNotEmpty() ? 'Assurée' : 'Non assurée' }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-gray-400">Aucun type de consultation actif</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-5">
        {{-- Plages de présence --}}
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Ajouter une plage de présence</h3>
            <form method="POST" action="{{ route('disponibilites.store') }}" class="grid sm:grid-cols-2 gap-3 mb-5">
                @csrf
                <div class="sm:col-span-2">
                    <label for="p-medecin" class="block text-xs font-semibold text-gray-600 mb-1">Médecin</label>
                    <select id="p-medecin" name="user_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($medecins as $medecin)
                        <option value="{{ $medecin->id }}">
                            {{ $medecin->nom_complet }}{{ $medecin->specialite ? ' — '.$medecin->specialite : '' }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="p-jour" class="block text-xs font-semibold text-gray-600 mb-1">Jour</label>
                    <select id="p-jour" name="jour_semaine" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($jours as $num => $libelle)
                        <option value="{{ $num }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="p-lieu" class="block text-xs font-semibold text-gray-600 mb-1">Lieu</label>
                    <input id="p-lieu" name="lieu" maxlength="100" placeholder="Cabinet 2"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="p-debut" class="block text-xs font-semibold text-gray-600 mb-1">De</label>
                    <input id="p-debut" type="time" name="heure_debut" required value="08:00"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="p-fin" class="block text-xs font-semibold text-gray-600 mb-1">À</label>
                    <input id="p-fin" type="time" name="heure_fin" required value="14:00"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Ajouter la plage
                    </button>
                </div>
            </form>

            <h4 class="font-semibold text-gray-700 text-sm mb-2">Plages déclarées</h4>
            @forelse($medecins as $medecin)
            @if($medecin->disponibilites->isNotEmpty())
            <div class="mb-3">
                <p class="text-sm font-medium text-gray-800">
                    {{ $medecin->nom_complet }}
                    <span class="text-xs text-gray-500">{{ $medecin->specialite ?: 'Médecine générale' }}</span>
                </p>
                <ul class="ml-3 mt-1 space-y-1">
                    @foreach($medecin->disponibilites as $plage)
                    <li class="flex items-center gap-2 text-xs text-gray-600">
                        <span>{{ $plage->libelleJour() }} · {{ $plage->plage() }}@if($plage->lieu) · {{ $plage->lieu }}@endif</span>
                        <form method="POST" action="{{ route('disponibilites.destroy', $plage) }}">
                            @csrf @method('DELETE')
                            <button class="text-red-600 hover:underline">retirer</button>
                        </form>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
            @empty
            <p class="text-sm text-gray-400">Aucun médecin enregistré.</p>
            @endforelse
        </div>

        {{-- Absences --}}
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Déclarer une absence</h3>
            <form method="POST" action="{{ route('disponibilites.absence') }}" class="grid sm:grid-cols-2 gap-3 mb-5">
                @csrf
                <div class="sm:col-span-2">
                    <label for="ab-medecin" class="block text-xs font-semibold text-gray-600 mb-1">Médecin</label>
                    <select id="ab-medecin" name="user_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($medecins as $medecin)
                        <option value="{{ $medecin->id }}">{{ $medecin->nom_complet }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="ab-debut" class="block text-xs font-semibold text-gray-600 mb-1">Du</label>
                    <input id="ab-debut" type="date" name="debut" required value="{{ $jour }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="ab-fin" class="block text-xs font-semibold text-gray-600 mb-1">Au</label>
                    <input id="ab-fin" type="date" name="fin" required value="{{ $jour }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label for="ab-motif" class="block text-xs font-semibold text-gray-600 mb-1">Motif</label>
                    <input id="ab-motif" name="motif" maxlength="150" placeholder="Congé, mission, garde extérieure"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <button class="bg-amber-600 hover:bg-amber-700 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Déclarer l'absence
                    </button>
                </div>
            </form>

            <h4 class="font-semibold text-gray-700 text-sm mb-2">Absences en cours et à venir</h4>
            @php $absences = $medecins->flatMap->absences->filter(fn ($a) => $a->fin->gte(now()->startOfDay()))->sortBy('debut'); @endphp
            @forelse($absences as $absence)
            <div class="flex items-center justify-between text-xs text-gray-600 border-b last:border-0 py-2">
                <span>
                    <strong>{{ $absence->medecin?->nom_complet }}</strong>
                    — du {{ $absence->debut->format('d/m/Y') }} au {{ $absence->fin->format('d/m/Y') }}
                    @if($absence->motif) · {{ $absence->motif }} @endif
                </span>
                <form method="POST" action="{{ route('disponibilites.absence.destroy', $absence) }}">
                    @csrf @method('DELETE')
                    <button class="text-red-600 hover:underline">lever</button>
                </form>
            </div>
            @empty
            <p class="text-sm text-gray-400">Aucune absence déclarée.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
