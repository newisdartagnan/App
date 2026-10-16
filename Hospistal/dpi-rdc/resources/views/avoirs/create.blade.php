@extends('layouts.app')
@section('title', 'Avoir — '.$facture->numero_facture)
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center gap-3 mb-1">
        <a href="{{ route('caisse.show', $facture) }}" class="text-blue-700 hover:underline text-sm">← Facture {{ $facture->numero_facture }}</a>
    </div>
    <h2 class="text-2xl font-bold text-gray-800 mb-1">Établir un avoir</h2>
    <p class="text-sm text-gray-500 mb-5">
        Une facture émise ne se modifie plus : on lui oppose un avoir, numéroté et motivé.
        Il reprend toute la facture, ou seulement les lignes à corriger.
    </p>


    <div class="bg-white rounded-xl shadow p-5 mb-4 text-sm">
        <div class="flex flex-wrap justify-between gap-3">
            <div>
                <p class="font-semibold text-gray-800">{{ $facture->patient->nom_complet }}
                    <span class="text-gray-400 font-normal">— {{ $facture->patient->dossier_number }}</span></p>
                <p class="text-gray-500">Facture {{ $facture->numero_facture }} du {{ $facture->date_facture->format('d/m/Y') }}
                    · {{ $facture->libellePriseEnCharge() }}</p>
            </div>
            <div class="text-right">
                <p class="text-gray-500">Encore réclamé</p>
                <p class="text-lg font-bold text-gray-800">{{ $facture->formater($facture->totalNet()) }}</p>
                <p class="text-xs text-gray-500">dont patient {{ $facture->formater($facture->partPatientNette()) }}
                    · assureur {{ $facture->formater($facture->partAssuranceNette()) }}</p>
            </div>
        </div>
    </div>

    {{-- Ce que l'avoir va déclencher : mieux vaut le lire avant de signer. --}}
    @if($retenu > 0)
    <div class="bg-purple-50 border border-purple-200 rounded-xl px-4 py-3 mb-3 text-sm text-purple-900">
        💰 Le patient a déjà versé {{ $facture->formater($retenu) }} sur cette facture. Si l'avoir ramène
        sa part sous ce montant, la différence devient un <strong>crédit à son nom</strong> : imputable sur
        sa prochaine facture, ou rendu en espèces par la caisse.
    </div>
    @endif
    @if($bordereau)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-3 text-sm text-amber-900">
        📄 Cette facture figure déjà sur le bordereau <strong>{{ $bordereau->numero }}</strong> envoyé à
        l'assureur. La part assureur reprise par l'avoir sera déduite de ce qu'il doit — pensez à le lui signaler.
    </div>
    @endif
    @if($bonsUtilises->isNotEmpty())
    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-3 text-sm text-red-800">
        ⚠️ Le bon {{ $bonsUtilises->pluck('numero')->implode(', ') }} a déjà été servi : les produits ou
        l'examen ont été délivrés. Une annulation sans refacturation revient à les offrir.
    </div>
    @endif

    <form method="POST" action="{{ route('avoirs.store', $facture) }}" class="space-y-4">
        @csrf

        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-3">Portée</h3>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="flex gap-3 items-start border rounded-lg p-3 cursor-pointer has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50">
                    <input type="radio" name="portee" value="totale" class="mt-1" @checked(old('portee', 'totale') === 'totale')>
                    <span>
                        <span class="font-medium text-gray-800">Annuler toute la facture</span>
                        <span class="block text-xs text-gray-500">Erreur de prise en charge, doublon, mauvais patient…</span>
                    </span>
                </label>
                <label class="flex gap-3 items-start border rounded-lg p-3 cursor-pointer has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50">
                    <input type="radio" name="portee" value="partielle" class="mt-1" @checked(old('portee') === 'partielle')>
                    <span>
                        <span class="font-medium text-gray-800">Reprendre certaines lignes</span>
                        <span class="block text-xs text-gray-500">Examen non réalisé, quantité erronée… Indiquez le montant repris par ligne ci-dessous.</span>
                    </span>
                </label>
            </div>

            <label class="flex gap-3 items-start mt-4 text-sm">
                <input type="checkbox" name="liberer" value="1" class="mt-1" @checked(old('liberer', true))>
                <span>
                    <span class="font-medium text-gray-800">Libérer les prestations pour les refacturer</span>
                    <span class="block text-xs text-gray-500">
                        En cas d'annulation totale seulement. L'examen, l'acte, les journées ou l'ordonnance
                        redeviennent facturables : une facture juste les reprendra. Décochez si la prestation
                        n'était pas due ou est offerte.
                    </span>
                </span>
            </label>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b font-semibold text-gray-700">
                Lignes de la facture
                <span class="text-gray-400 font-normal text-sm">— montant repris, pour un avoir partiel</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-4 py-2 text-left">Désignation</th>
                            <th class="px-4 py-2 text-right">Facturé</th>
                            <th class="px-4 py-2 text-right">Encore repris possible</th>
                            <th class="px-4 py-2 text-right w-40">Montant repris</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($lignes as $l)
                        <tr>
                            <td class="px-4 py-2">
                                <label for="m-{{ $l['ligne']->id }}" class="font-medium">{{ $l['ligne']->libelle }}</label>
                                @if($l['reste_assurance'] > 0)
                                <p class="text-xs text-gray-500">dont assureur {{ $facture->formater($l['reste_assurance']) }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">{{ $facture->formater($l['total']) }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap {{ $l['reste'] > 0 ? '' : 'text-gray-400' }}">
                                {{ $facture->formater($l['reste']) }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if($l['reste'] > 0)
                                <input id="m-{{ $l['ligne']->id }}" type="number" step="0.01" min="0" max="{{ $l['reste'] }}"
                                       name="montants[{{ $l['ligne']->id }}]" value="{{ old('montants.'.$l['ligne']->id) }}"
                                       class="w-full min-h-[40px] rounded-lg border border-gray-300 px-3 py-1 text-right">
                                @else
                                <span class="text-xs text-gray-400">déjà repris</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-5 space-y-4">
            <div>
                <label for="motif_categorie" class="block text-sm font-medium text-gray-700 mb-1">Nature de la correction <span class="text-red-500">*</span></label>
                <select id="motif_categorie" name="motif_categorie" required
                        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
                    <option value="">— Choisir —</option>
                    @foreach(\App\Models\Avoir::MOTIFS as $code => $libelle)
                    <option value="{{ $code }}" @selected(old('motif_categorie') === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="motif" class="block text-sm font-medium text-gray-700 mb-1">Motif détaillé <span class="text-red-500">*</span></label>
                <textarea id="motif" name="motif" rows="3" required minlength="5" maxlength="1000"
                          placeholder="Ex. : patient couvert par la SNEL depuis le 1er octobre, facturé en privé par erreur."
                          class="w-full rounded-lg border border-gray-300 px-3 py-2">{{ old('motif') }}</textarea>
                <p class="text-xs text-gray-500 mt-1">Il figurera sur l'avoir et dans le journal de la facture, avec votre nom.</p>
            </div>
        </div>

        <div class="flex flex-wrap justify-end gap-3">
            <a href="{{ route('caisse.show', $facture) }}" class="min-h-[44px] px-5 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Renoncer</a>
            <button type="submit" class="min-h-[44px] px-6 py-2 bg-red-700 hover:bg-red-800 text-white font-semibold rounded-lg">
                Établir l'avoir
            </button>
        </div>
    </form>
</div>
@endsection
