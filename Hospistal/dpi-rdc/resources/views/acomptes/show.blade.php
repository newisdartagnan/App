@extends('layouts.app')
@section('title', 'Acomptes — ' . $visit->patient->nom_complet)
@section('content')
@php $clos = ! $visit->peutRecevoirServices(); @endphp
<div class="max-w-5xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-4 flex-wrap">
        @if($visit->service)
        <a href="{{ route('services.dossier', [$visit->service, $visit]) }}" class="text-blue-700 hover:underline text-sm">← Dossier de séjour</a>
        @else
        <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline text-sm">← Parcours</a>
        @endif
        <h2 class="text-2xl font-bold text-gray-800">💰 Acomptes de soins</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
            {{ $visit->patient->nom_complet }} · {{ $visit->patient->dossier_number }}
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

@php $dev = app(\App\Services\DeviseService::class); @endphp
    <div class="grid grid-cols-3 gap-3 mb-3">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-blue-700">{{ number_format($totalVerse, 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Total avancé, en contre-valeur CDF</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-purple-700">{{ number_format($totalVerse - $disponible, 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Déjà imputé sur les factures</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold {{ $disponible > 0 ? 'text-green-700' : 'text-gray-400' }}">{{ number_format($disponible, 0, ',', ' ') }}</p>
            <p class="text-xs text-gray-500">Encore disponible (CDF)</p>
        </div>
    </div>

    {{-- Ce que le guichet détient réellement, devise par devise --}}
    @if(count($parDevise) > 0)
    <div class="bg-white rounded-xl shadow p-4 mb-5">
        <p class="text-sm font-semibold text-gray-700 mb-2">Détenu par devise</p>
        <div class="flex flex-wrap gap-3">
            @foreach($parDevise as $code => $montant)
            <span class="px-4 py-2 rounded-lg bg-gray-50 border border-gray-200 text-sm">
                <strong class="text-gray-800">{{ $dev->formater((float) $montant, $code) }}</strong>
                <span class="text-xs text-gray-500">{{ $dev->libelle($code) }}</span>
            </span>
            @endforeach
        </div>
    </div>
    @endif

    @unless($clos)
    <div class="bg-white rounded-xl shadow p-5 mb-5">
        <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Encaisser un acompte</h3>
        <form method="POST" action="{{ route('acomptes.store', $visit) }}" class="grid md:grid-cols-3 gap-3">
            @csrf
            <div>
                <label for="a-montant" class="block text-xs font-semibold text-gray-600 mb-1">Montant reçu</label>
                <input id="a-montant" name="montant" type="number" step="1" min="1" required
                       value="{{ old('montant') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="a-devise" class="block text-xs font-semibold text-gray-600 mb-1">Devise</label>
                <select id="a-devise" name="devise" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach($dev->referentiel() as $code => $definition)
                    <option value="{{ $code }}" @selected(old('devise', 'CDF') === $code)>
                        {{ $definition['libelle'] }} ({{ $code }}){{ $code === $dev->pivot() ? '' : ' — 1 '.$code.' = '.number_format($definition['taux_cdf'], 2, ',', ' ').' CDF' }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-type" class="block text-xs font-semibold text-gray-600 mb-1">Nature</label>
                <select id="a-type" name="type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Caution::TYPES as $c => $l)
                    <option value="{{ $c }}" @selected(old('type') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-mode" class="block text-xs font-semibold text-gray-600 mb-1">Mode de paiement</label>
                <select id="a-mode" name="mode_paiement" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Caution::MODES_PAIEMENT as $c => $l)
                    <option value="{{ $c }}" @selected(old('mode_paiement') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="a-ref" class="block text-xs font-semibold text-gray-600 mb-1">Référence</label>
                <input id="a-ref" name="reference" maxlength="200" value="{{ old('reference') }}"
                       placeholder="N° de transaction, chèque…"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="a-motif" class="block text-xs font-semibold text-gray-600 mb-1">Motif</label>
                <input id="a-motif" name="motif" maxlength="500" value="{{ old('motif') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-3">
                <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                    Encaisser l'acompte
                </button>
                <span class="ml-3 text-xs text-gray-500">
                    L'acompte s'impute automatiquement sur les factures ouvertes du séjour.
                </span>
            </div>
        </form>
    </div>
    @endunless

    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Acomptes du séjour</div>
        @forelse($acomptes as $acompte)
        <div class="px-4 py-3 border-b last:border-0">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div>
                    <span class="font-semibold text-gray-800">{{ $acompte->montantFormate() }}</span>
                    <span class="ml-2 px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-700">{{ $acompte->libelleType() }}</span>
                    <span class="ml-1 px-2 py-0.5 rounded-full text-xs {{ $acompte->resteDisponible() > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                        {{ $acompte->libelleStatut() }}
                    </span>
                </div>
                <span class="text-xs text-gray-500">
                    {{ \App\Models\Caution::MODES_PAIEMENT[$acompte->mode_paiement] ?? $acompte->mode_paiement }}
                    · {{ $acompte->created_at->format('d/m/Y H:i') }}
                    · {{ $acompte->caissier?->nom_complet }}
                </span>
            </div>
            @if($acompte->motif)
            <p class="text-xs text-gray-500 italic mt-1">{{ $acompte->motif }}</p>
            @endif
            <p class="text-xs text-gray-600 mt-1">
                Imputé : <strong>{{ $dev->formater((float) $acompte->montant_impute, $acompte->devise) }}</strong>
                · Remboursé : <strong>{{ $dev->formater((float) $acompte->montant_rembourse, $acompte->devise) }}</strong>
                · Disponible : <strong class="{{ $acompte->resteDisponible() > 0 ? 'text-green-700' : '' }}">{{ $acompte->resteFormate() }}</strong>
            </p>
            @if($acompte->imputations->isNotEmpty())
            <ul class="mt-2 ml-4 text-xs text-gray-500 list-disc">
                @foreach($acompte->imputations as $imputation)
                <li>
                    {{ $imputation->preleveFormate() }} prélevés
                    @if($imputation->devise !== $acompte->devise)
                    <span class="text-gray-400">→ {{ $imputation->montantFormate() }}</span>
                    @endif
                    sur
                    <a href="{{ route('caisse.show', $imputation->facture_id) }}" class="text-blue-700 hover:underline">
                        {{ $imputation->facture?->numero_facture }}
                    </a>
                    — {{ $imputation->created_at->format('d/m/Y H:i') }}
                </li>
                @endforeach
            </ul>
            @endif
        </div>
        @empty
        <p class="px-4 py-8 text-center text-gray-400 text-sm">Aucun acompte versé pour ce séjour.</p>
        @endforelse
    </div>

    @if($disponible > 0)
    <div class="bg-white rounded-xl shadow p-5">
        <h3 class="font-semibold text-gray-700 mb-2">Remboursement du reliquat</h3>
        <p class="text-sm text-gray-600 mb-3">
            @foreach($parDevise as $code => $montant){{ $loop->first ? '' : ' et ' }}{{ $dev->formater((float) $montant, $code) }}@endforeach
            restent disponibles. Le remboursement impute d'abord les factures encore ouvertes,
            puis rend le solde au patient <strong>dans la devise de chaque versement</strong>.
        </p>
        <form method="POST" action="{{ route('acomptes.rembourser', $visit) }}" class="flex flex-wrap gap-2 items-end">
            @csrf
            <div>
                <label for="r-ref" class="block text-xs font-semibold text-gray-600 mb-1">Référence du remboursement</label>
                <input id="r-ref" name="reference" maxlength="200"
                       class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <button class="bg-amber-600 hover:bg-amber-700 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                Rembourser le reliquat
            </button>
        </form>
    </div>
    @endif
</div>
@endsection
