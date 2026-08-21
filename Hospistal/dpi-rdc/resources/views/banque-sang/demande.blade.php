@extends('layouts.app')
@section('title', 'Demande de sang '.$demande->numero)
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route('banque-sang.index') }}" class="text-blue-700 hover:underline text-sm">← Banque de sang</a>
        <h2 class="text-2xl font-bold text-gray-800">🩸 {{ $demande->numero }}</h2>
        @if($demande->urgence)
        <span class="px-2 py-0.5 rounded-full text-xs bg-red-600 text-white font-semibold">URGENCE VITALE</span>
        @endif
        <span class="px-2 py-0.5 rounded-full text-xs {{ $demande->estOuverte() ? 'bg-blue-100 text-blue-800' : 'bg-gray-200 text-gray-600' }}">
            {{ $demande->libelleStatut() }}
        </span>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        {{ $demande->patient->nom_complet }} · {{ $demande->patient->dossier_number }} ·
        {{ $demande->patient->libellePriseEnCharge() }}
        @if($demande->demandeur) · demandé par {{ $demande->demandeur->nom_complet }} @endif
    </p>

    @include('partials._flash')

    {{-- Ce que la demande réclame --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-red-700">{{ $demande->groupeReceveur() ?: '?' }}</p>
            <p class="text-xs text-gray-500 mt-1">Groupe du receveur</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $demande->pochesRestantes() }}</p>
            <p class="text-xs text-gray-500 mt-1">Poche(s) restant à servir</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-lg font-bold text-gray-800">
                {{ \App\Models\PocheSang::PRODUITS[$demande->type_produit] ?? $demande->type_produit }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Produit demandé</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold {{ $demande->hemoglobine !== null && $demande->hemoglobine < 7 ? 'text-red-700' : 'text-gray-800' }}">
                {{ $demande->hemoglobine !== null ? ($demande->hemoglobine + 0) : '—' }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Hémoglobine (g/dL)</p>
        </div>
    </div>

    @if($demande->indication)
    <div class="bg-white rounded-xl shadow px-5 py-3 mb-5 text-sm">
        <span class="text-xs text-gray-500">Indication :</span> {{ $demande->indication }}
    </div>
    @endif

    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-sm text-blue-900">
        Un receveur <strong>{{ $demande->groupeReceveur() ?: 'de groupe inconnu' }}</strong> accepte
        <strong>{{ implode(', ', $groupesAcceptes) }}</strong>.
        @unless($demande->groupeReceveur())
        Faites déterminer son groupe : sans lui, seul du O négatif peut être proposé.
        @endunless
    </div>

    {{-- Poches compatibles --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Poches compatibles en stock
            <span class="text-gray-400 font-normal text-sm">— {{ $pochesCompatibles->count() }} disponible(s)</span>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse($pochesCompatibles as $poche)
            <div class="px-5 py-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium">
                        <span class="font-mono">{{ $poche->numero }}</span>
                        — <span class="font-bold">{{ $poche->groupe_sanguin }}</span>
                        · {{ $poche->libelleProduit() }} · {{ $poche->volume_ml }} ml
                    </p>
                    <p class="text-xs text-gray-500">
                        Périme le {{ $poche->date_peremption->format('d/m/Y') }}
                        ({{ $poche->joursAvantPeremption() }} jours)
                        @if($poche->donneur) · donneur {{ $poche->donneur->nomComplet() }} @endif
                        @if($poche->emplacement) · {{ $poche->emplacement }} @endif
                    </p>
                </div>

                @if($demande->estOuverte())
                <details>
                    <summary class="cursor-pointer text-sm font-medium text-green-700 select-none">Délivrer</summary>
                    <form method="POST" action="{{ route('banque-sang.delivrer', $demande) }}"
                          class="mt-2 flex flex-wrap gap-2 items-end">
                        @csrf
                        <input type="hidden" name="poche_id" value="{{ $poche->id }}">
                        <div>
                            <label for="hb-{{ $poche->id }}" class="block text-xs font-semibold text-gray-600 mb-1">
                                Hb avant (g/dL)
                            </label>
                            <input id="hb-{{ $poche->id }}" name="hemoglobine_avant" type="number" step="0.1" min="1" max="25"
                                   value="{{ $demande->hemoglobine }}"
                                   class="border border-gray-300 rounded px-2 py-1 text-sm w-24">
                        </div>
                        <div>
                            <label for="h-{{ $poche->id }}" class="block text-xs font-semibold text-gray-600 mb-1">Heure de pose</label>
                            <input id="h-{{ $poche->id }}" name="heure_debut" type="time" value="{{ now()->format('H:i') }}"
                                   class="border border-gray-300 rounded px-2 py-1 text-sm">
                        </div>
                        <label class="flex items-center gap-2 text-xs text-gray-800 pb-1 max-w-xs">
                            <input type="checkbox" name="controle_ultime" value="1" required class="rounded">
                            <span>Contrôle ultime au lit du malade effectué — obligatoire</span>
                        </label>
                        <button class="bg-green-700 hover:bg-green-800 text-white rounded-lg px-4 py-2 text-sm font-semibold">
                            ✓ Délivrer cette poche
                        </button>
                    </form>
                </details>
                @endif
            </div>
            @empty
            <p class="px-5 py-8 text-center text-gray-500 text-sm">
                Aucune poche compatible en stock. Appelez un donneur — la liste est ci-dessous.
            </p>
            @endforelse
        </div>
    </div>

    {{-- Poches déjà délivrées --}}
    @if($demande->transfusions->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Poches délivrées</div>
        <div class="divide-y divide-gray-100">
            @foreach($demande->transfusions as $transfusion)
            <div class="px-5 py-3 text-sm">
                <span class="font-mono">{{ $transfusion->numero_poche }}</span>
                — {{ $transfusion->groupe_donneur }} → {{ $transfusion->groupe_receveur }}
                · {{ $transfusion->jour?->format('d/m/Y') }} à {{ $transfusion->heure_debut }}
                @if($transfusion->controle_ultime)
                <span class="text-xs text-green-700">✓ contrôle ultime</span>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Donneurs à appeler --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Donneurs à appeler
            <span class="text-gray-400 font-normal text-sm">— {{ $donneursAAppeler->count() }} joignable(s)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Donneur</th>
                        <th class="px-4 py-3">Groupe</th>
                        <th class="px-4 py-3">Téléphone</th>
                        <th class="px-4 py-3">Dernier don</th>
                        <th class="px-4 py-3">Type</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($donneursAAppeler as $donneur)
                    <tr>
                        <td class="px-4 py-3 font-medium">{{ $donneur->nomComplet() }}</td>
                        <td class="px-4 py-3 font-bold">{{ $donneur->groupe_sanguin }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $donneur->telephone ?: '—' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $donneur->dernier_don?->format('d/m/Y') ?? 'jamais donné' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $donneur->libelleType() }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">
                        Aucun donneur compatible joignable dans le fichier.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($demande->estOuverte())
    <details class="bg-white rounded-xl shadow mt-5">
        <summary class="px-5 py-3 cursor-pointer text-sm font-medium text-red-700 select-none">
            Refuser cette demande
        </summary>
        <form method="POST" action="{{ route('banque-sang.refuser', $demande) }}" class="px-5 pb-5 pt-2 flex flex-wrap gap-3 items-end">
            @csrf
            <div class="flex-1 min-w-64">
                <label for="motif" class="block text-xs font-semibold text-gray-600 mb-1">
                    Motif du refus <span class="text-red-500">*</span>
                </label>
                <input id="motif" name="motif_refus" required maxlength="500"
                       placeholder="Stock épuisé pour ce groupe, indication à revoir…"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <button class="bg-red-700 hover:bg-red-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                Refuser
            </button>
        </form>
    </details>
    @endif
</div>
@endsection
