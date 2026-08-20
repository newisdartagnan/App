@extends('layouts.app')
@section('title', $assurance->nom)
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route('assurances.index') }}" class="text-blue-700 hover:underline text-sm">← Conventions</a>
        <h2 class="text-2xl font-bold text-gray-800">🛡️ {{ $assurance->nom }}</h2>
        <span class="px-2 py-0.5 rounded-full text-xs {{ $assurance->est_actif ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">
            {{ $assurance->est_actif ? 'Active' : 'Suspendue' }}
        </span>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Code <span class="font-mono">{{ $assurance->code }}</span> ·
        {{ $assurance->modalites() }}
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

    {{-- Contrat --}}
    <div class="bg-white rounded-xl shadow p-5 mb-5">
        <h3 class="font-semibold text-gray-700 mb-1">Contrat et modalités de paiement</h3>
        <p class="text-xs text-gray-500 mb-4 pb-3 border-b">
            Le taux du contrat s'applique à toutes les prestations, moins le ticket
            modérateur laissé au patient. Prochaine échéance d'une facture émise
            aujourd'hui : <strong>{{ $assurance->echeancePour(now())->format('d/m/Y') }}</strong>.
        </p>
        @include('assurances.partials.formulaire', [
            'assurance' => $assurance,
            'action' => route('assurances.update', $assurance),
        ])
    </div>

    <div class="grid lg:grid-cols-2 gap-5">
        {{-- Règles de couverture --}}
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-1">Règles de couverture</h3>
            <p class="text-xs text-gray-500 mb-4 pb-3 border-b">
                Sans règle, une catégorie est couverte au taux du contrat
                ({{ $assurance->taux_couverture + 0 }} %). Une règle permet d'exclure
                une catégorie, ou de lui donner un taux négocié.
            </p>

            <form method="POST" action="{{ route('assurances.couvertures', $assurance) }}" class="grid sm:grid-cols-2 gap-3 mb-5">
                @csrf
                <div>
                    <label for="c-type" class="block text-xs font-semibold text-gray-600 mb-1">Catégorie d'actes</label>
                    <select id="c-type" name="type" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($categories as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('type') === $cle)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="c-couvert" class="block text-xs font-semibold text-gray-600 mb-1">Prise en charge</label>
                    <select id="c-couvert" name="couvert" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="1" @selected(old('couvert', '1') === '1')>Couvert</option>
                        <option value="0" @selected(old('couvert') === '0')>Exclu — à charge du patient</option>
                    </select>
                </div>
                <div>
                    <label for="c-taux" class="block text-xs font-semibold text-gray-600 mb-1">Taux négocié (%)</label>
                    <input id="c-taux" name="taux_specifique" type="number" step="0.01" min="0" max="100"
                           value="{{ old('taux_specifique') }}" placeholder="taux du contrat"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <p class="text-xs text-gray-500 mt-1">Laisser vide pour garder le taux du contrat.</p>
                </div>
                <div>
                    <label for="c-libelle" class="block text-xs font-semibold text-gray-600 mb-1">Précision</label>
                    <input id="c-libelle" name="reference_libelle" maxlength="255" value="{{ old('reference_libelle') }}"
                           placeholder="Avenant nº 3, actes lourds…"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Enregistrer la règle
                    </button>
                </div>
            </form>

            <div class="border-t pt-3 divide-y divide-gray-100">
                @forelse($assurance->couvertures as $couverture)
                <div class="py-2 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-gray-800">
                            {{ $categories[$couverture->type] ?? $couverture->type }}
                        </p>
                        <p class="text-xs {{ $couverture->couvert ? 'text-green-700' : 'text-red-700' }}">
                            @if($couverture->couvert)
                                Couvert à
                                {{ $couverture->taux_specifique !== null
                                    ? ($couverture->taux_specifique + 0).' % (taux négocié)'
                                    : ($assurance->taux_couverture + 0).' % (taux du contrat)' }}
                            @else
                                Exclu — entièrement à charge du patient
                            @endif
                        </p>
                        @if($couverture->reference_libelle)
                        <p class="text-xs text-gray-500 italic">{{ $couverture->reference_libelle }}</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('assurances.couvertures.destroy', $couverture) }}">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs text-red-700 hover:underline">Retirer</button>
                    </form>
                </div>
                @empty
                <p class="py-6 text-center text-gray-400 text-sm">
                    Aucune règle : tout est couvert à {{ $assurance->taux_couverture + 0 }} %.
                </p>
                @endforelse
            </div>
        </div>

        {{-- Forfaits réservés --}}
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-1">Forfaits réservés à cette convention</h3>
            <p class="text-xs text-gray-500 mb-4 pb-3 border-b">
                Un forfait fixe un prix tout compris. Il se crée depuis l'écran des
                forfaits, en le rattachant à cette société.
            </p>

            <div class="divide-y divide-gray-100">
                @forelse($forfaits as $forfait)
                <div class="py-3">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <span class="text-sm font-medium text-gray-800">{{ $forfait->libelle }}</span>
                        <span class="text-sm font-semibold text-blue-800">
                            {{ number_format((float) $forfait->montant, 2, ',', ' ') }} {{ $forfait->devise }}
                        </span>
                    </div>
                    <p class="text-xs text-gray-500">{{ $forfait->libellePortee() }}</p>
                    <p class="text-xs text-gray-600">{{ implode(' · ', $forfait->libellesCouverts()) }}</p>
                </div>
                @empty
                <p class="py-6 text-center text-gray-400 text-sm">Aucun forfait rattaché.</p>
                @endforelse
            </div>

            <a href="{{ route('forfaits.index') }}" class="inline-block mt-4 text-sm text-blue-700 hover:underline">
                📦 Gérer les forfaits →
            </a>

            @if($assurance->notes)
            <div class="mt-5 pt-4 border-t">
                <p class="text-xs font-semibold text-gray-600 mb-1">Notes du contrat</p>
                <p class="text-sm text-gray-700 whitespace-pre-line">{{ $assurance->notes }}</p>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
