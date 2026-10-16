@extends('layouts.app')
@section('title', 'Facture ' . $facture->numero)
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-4 flex-wrap">
        <a href="{{ route('conventions.index') }}" class="text-blue-700 hover:underline text-sm">← Conventions</a>
        <h2 class="text-2xl font-bold text-gray-800">{{ $facture->numero }}</h2>
        <span class="text-sm bg-gray-100 text-gray-600 px-3 py-1 rounded-full">{{ $facture->assurance->nom }}</span>
        <a href="{{ route('conventions.imprimer', $facture) }}" target="_blank"
           class="ml-auto bg-white border border-blue-300 text-blue-700 px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-50">🖨 Imprimer</a>
    </div>


    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
        <div><span class="text-gray-500 text-xs">Période</span><p class="font-semibold">{{ $facture->periode_debut->format('d/m/Y') }} → {{ $facture->periode_fin->format('d/m/Y') }}</p></div>
        <div><span class="text-gray-500 text-xs">Présentation</span><p class="font-semibold capitalize">{{ $facture->mode }}</p></div>
        <div><span class="text-gray-500 text-xs">Montant</span><p class="font-semibold">{{ number_format((float) $facture->montant_total, 2, ',', ' ') }} {{ $facture->devise }}</p>
            @if($facture->montantRejete() > 0)
            <p class="text-xs text-red-700">− {{ number_format($facture->montantRejete(), 2, ',', ' ') }} rejetés</p>
            @endif
            @php $deduits = $facture->montantAvoirs(); @endphp
            @if($deduits > 0)
            <p class="text-xs text-red-700">− {{ number_format($deduits, 2, ',', ' ') }} d'avoirs
                ({{ $facture->avoirs()->pluck('numero')->implode(', ') }})</p>
            @endif
        </div>
        <div><span class="text-gray-500 text-xs">Réglé</span><p class="font-semibold text-green-700">{{ number_format((float) $facture->montant_regle, 2, ',', ' ') }}</p></div>
        <div><span class="text-gray-500 text-xs">Reste dû</span><p class="font-semibold {{ $facture->resteDu() > 0 ? 'text-amber-700' : 'text-green-700' }}">{{ number_format($facture->resteDu(), 2, ',', ' ') }}</p></div>
        <div>
            <span class="text-gray-500 text-xs">Statut</span>
            <p><span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded
                {{ match($facture->statut) {
                    'reglee' => 'bg-green-100 text-green-800',
                    'partiellement_reglee' => 'bg-amber-100 text-amber-800',
                    'annulee' => 'bg-gray-100 text-gray-600',
                    default => 'bg-blue-100 text-blue-800',
                } }}">{{ str_replace('_', ' ', $facture->statut) }}</span></p>
        </div>
    </div>

    @if($facture->mode === 'individuelle')
    <p class="text-xs text-gray-500 mb-3">Présentation individuelle : un décompte par bénéficiaire.</p>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Détail par bénéficiaire</div>
        @php $parPatient = $facture->lignes->groupBy('patient_id'); @endphp
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Bénéficiaire</th>
                    <th class="px-4 py-2 text-left">Dossier</th>
                    <th class="px-4 py-2 text-center">Factures</th>
                    <th class="px-4 py-2 text-right">Part convention</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($parPatient as $lignes)
                @php $p = $lignes->first()->patient; @endphp
                <tr>
                    <td class="px-4 py-2 font-semibold">{{ $p->nom_complet }}</td>
                    <td class="px-4 py-2 text-xs text-gray-500">{{ $p->dossier_number }}</td>
                    <td class="px-4 py-2 text-center">{{ $lignes->count() }}</td>
                    <td class="px-4 py-2 text-right font-semibold">{{ number_format((float) $lignes->sum('part_assurance'), 0, ',', ' ') }} CDF</td>
                </tr>
                @if($facture->mode === 'individuelle')
                    @foreach($lignes as $l)
                    <tr class="bg-gray-50/60">
                        <td class="px-4 py-1.5 pl-8 text-xs text-gray-500" colspan="2">{{ $l->facture->numero_facture }} — {{ $l->facture->date_facture->format('d/m/Y') }}</td>
                        <td></td>
                        <td class="px-4 py-1.5 text-right text-xs">{{ number_format((float) $l->part_assurance, 0, ',', ' ') }}</td>
                    </tr>
                    @endforeach
                @endif
                @endforeach
            </tbody>
            <tfoot class="bg-gray-50 font-bold">
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right">Total</td>
                    <td class="px-4 py-2 text-right">{{ number_format((float) $facture->lignes->sum('part_assurance'), 0, ',', ' ') }} CDF</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Règlements</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @forelse($facture->reglements as $r)
                    <tr>
                        <td class="px-4 py-2 text-xs">{{ $r->date_reglement->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-xs">{{ $r->mode_paiement }}{{ $r->reference ? ' · ' . $r->reference : '' }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ number_format((float) $r->montant, 0, ',', ' ') }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400 text-sm">Aucun règlement</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(! $facture->estSoldee())
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Enregistrer un règlement</div>
            <form method="POST" action="{{ route('conventions.regler', $facture) }}" class="p-4 space-y-3">
                @csrf
                <div>
                    <label for="montant" class="block text-xs text-gray-500 mb-1">Montant ({{ $facture->devise }})</label>
                    <input id="montant" type="number" step="0.01" min="0.01" name="montant" required
                        value="{{ number_format($facture->resteDu(), 2, '.', '') }}"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="mode_paiement" class="block text-xs text-gray-500 mb-1">Mode</label>
                    <select id="mode_paiement" name="mode_paiement" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="virement">Virement</option>
                        <option value="cheque">Chèque</option>
                        <option value="especes">Espèces</option>
                        <option value="mobile_money">Mobile money</option>
                    </select>
                </div>
                <div>
                    <label for="reference" class="block text-xs text-gray-500 mb-1">Référence</label>
                    <input id="reference" name="reference" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-5 py-2 rounded-lg font-semibold w-full">
                    Enregistrer le règlement
                </button>
            </form>
        </div>
        @endif
    </div>

    @if($peutRecouvrer && $facture->statut !== 'annulee')
    @php
        $dev = $facture->devise;
        $montantDev = fn ($m) => number_format((float) $m, 2, ',', ' ').' '.$dev;
        $echeance = $facture->echeance();
        $retard = (int) $echeance->diffInDays(now()->startOfDay(), false);
    @endphp
    <h3 class="text-lg font-bold text-gray-800 mt-8 mb-3">Suivi du recouvrement</h3>

    {{-- Dépôt --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="text-sm">
                <p class="font-semibold text-gray-700">Dépôt chez l'assureur</p>
                @if($facture->date_depot)
                <p>Déposé le {{ $facture->date_depot->format('d/m/Y') }}{{ $facture->accuse_reception ? ' — accusé de réception '.$facture->accuse_reception : '' }}.</p>
                @else
                <p class="text-amber-800">Pas encore déposé : le délai court depuis l'émission, faute de mieux.</p>
                @endif
                <p class="{{ $retard > 0 && ! $facture->estSoldee() ? 'text-red-700 font-semibold' : 'text-gray-600' }}">
                    Échéance : {{ $echeance->format('d/m/Y') }}
                    @if(! $facture->estSoldee())
                    {{ $retard > 0 ? '— échu depuis '.$retard.' jour(s)' : '— dans '.abs($retard).' jour(s)' }}
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('recouvrement.depot', $facture) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label for="d-date" class="block text-xs text-gray-500 mb-1">Date du dépôt</label>
                    <input id="d-date" type="date" name="date_depot" required max="{{ now()->toDateString() }}"
                           value="{{ old('date_depot', $facture->date_depot?->toDateString() ?? now()->toDateString()) }}"
                           class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="d-ar" class="block text-xs text-gray-500 mb-1">Accusé de réception</label>
                    <input id="d-ar" name="accuse_reception" maxlength="100" value="{{ old('accuse_reception', $facture->accuse_reception) }}"
                           class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-40">
                </div>
                <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-4 py-2 rounded-lg font-semibold">Enregistrer</button>
            </form>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
        {{-- Rejets --}}
        <div id="rejets" class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">
                Rejets
                @if($facture->montantRejete() > 0)
                <span class="text-red-700 font-normal text-sm">— {{ $montantDev($facture->montantRejete()) }} déduits</span>
                @endif
            </div>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse($facture->rejets as $rejet)
                <li class="px-4 py-2">
                    <p><span class="font-medium">{{ $rejet->facture?->patient?->nom_complet }}</span>
                        — {{ $montantDev($rejet->montant) }}
                        <span class="ml-1 px-2 py-0.5 rounded-full text-xs {{ $rejet->estEnCours() ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700' }}">{{ $rejet->libelleStatut() }}</span></p>
                    <p class="text-xs text-gray-600">{{ $rejet->date_rejet->format('d/m/Y') }} · {{ $rejet->libelleMotif() }}{{ $rejet->motif ? ' — '.$rejet->motif : '' }}</p>
                    @if($rejet->suite_donnee)
                    <p class="text-xs text-gray-500 italic">Suite : {{ $rejet->suite_donnee }}</p>
                    @endif
                    @if($rejet->estEnCours())
                    <details class="mt-1">
                        <summary class="text-xs text-blue-700 cursor-pointer">Donner une suite</summary>
                        <form method="POST" action="{{ route('recouvrement.rejet.traiter', $rejet) }}" class="mt-2 space-y-2">
                            @csrf
                            <label for="s-{{ $rejet->id }}" class="sr-only">Suite</label>
                            <select id="s-{{ $rejet->id }}" name="statut" class="w-full border border-gray-300 rounded px-2 py-1 text-xs">
                                <option value="leve">Levé — l'assureur paiera (pièce redéposée, erreur corrigée)</option>
                                <option value="conteste">Contesté — courrier de contestation envoyé</option>
                                <option value="abandonne">Abandonné — perdu pour l'hôpital</option>
                            </select>
                            <label for="sd-{{ $rejet->id }}" class="sr-only">Ce qui a été fait</label>
                            <input id="sd-{{ $rejet->id }}" name="suite_donnee" required minlength="3" placeholder="Ce qui a été fait"
                                   class="w-full border border-gray-300 rounded px-2 py-1 text-xs">
                            <button class="text-xs bg-blue-700 text-white rounded px-3 py-1">Enregistrer</button>
                        </form>
                    </details>
                    @endif
                </li>
                @empty
                <li class="px-4 py-4 text-gray-400">Aucun rejet.</li>
                @endforelse
            </ul>
            <details class="border-t">
                <summary class="px-4 py-3 text-sm font-medium text-red-700 cursor-pointer">Enregistrer un rejet</summary>
                <form method="POST" action="{{ route('recouvrement.rejet', $facture) }}" class="px-4 pb-4 space-y-2">
                    @csrf
                    <div>
                        <label for="r-ligne" class="block text-xs text-gray-500 mb-1">Facture rejetée</label>
                        <select id="r-ligne" name="ligne_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            @foreach($facture->lignes as $l)
                            <option value="{{ $l->id }}">{{ $l->facture?->numero_facture }} — {{ $l->patient?->nom_complet }} ({{ number_format((float) $l->part_assurance, 0, ',', ' ') }} CDF)</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="r-motif" class="block text-xs text-gray-500 mb-1">Motif</label>
                        <select id="r-motif" name="motif_categorie" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            @foreach(\App\Models\RejetConvention::MOTIFS as $cle => $libelle)
                            <option value="{{ $cle }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="r-montant" class="block text-xs text-gray-500 mb-1">Montant ({{ $dev }})</label>
                            <input id="r-montant" type="number" step="0.01" min="0.01" name="montant" placeholder="toute la part"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label for="r-date" class="block text-xs text-gray-500 mb-1">Notifié le</label>
                            <input id="r-date" type="date" name="date_rejet" required value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}"
                                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label for="r-detail" class="block text-xs text-gray-500 mb-1">Détail</label>
                        <input id="r-detail" name="motif" maxlength="1000" placeholder="Ce que dit l'assureur"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <button class="bg-red-700 hover:bg-red-800 text-white text-sm px-4 py-2 rounded-lg font-semibold">Enregistrer le rejet</button>
                </form>
            </details>
        </div>

        {{-- Relances --}}
        <div id="relances" class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Relances</div>
            <ul class="divide-y divide-gray-100 text-sm">
                @forelse($facture->relances->sortByDesc('date_relance') as $relance)
                <li class="px-4 py-2">
                    <p><span class="font-medium">{{ $relance->date_relance->format('d/m/Y') }}</span> — {{ $relance->libelleMode() }}
                        <span class="text-xs text-gray-500">par {{ $relance->auteur?->nom_complet ?? '—' }}</span></p>
                    @if($relance->commentaire)<p class="text-xs text-gray-600">{{ $relance->commentaire }}</p>@endif
                    @if($relance->prochaine_action)<p class="text-xs text-blue-800">Prochaine action le {{ $relance->prochaine_action->format('d/m/Y') }}</p>@endif
                </li>
                @empty
                <li class="px-4 py-4 text-gray-400">Aucune relance.</li>
                @endforelse
            </ul>
            <form method="POST" action="{{ route('recouvrement.relance', $facture) }}" class="border-t px-4 py-3 space-y-2">
                @csrf
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="l-date" class="block text-xs text-gray-500 mb-1">Date</label>
                        <input id="l-date" type="date" name="date_relance" required value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}"
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="l-mode" class="block text-xs text-gray-500 mb-1">Moyen</label>
                        <select id="l-mode" name="mode" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            @foreach(\App\Models\RelanceConvention::MODES as $cle => $libelle)
                            <option value="{{ $cle }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label for="l-commentaire" class="block text-xs text-gray-500 mb-1">Ce qui a été dit ou promis</label>
                    <input id="l-commentaire" name="commentaire" maxlength="1000"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="l-prochaine" class="block text-xs text-gray-500 mb-1">Prochaine action le</label>
                        <input id="l-prochaine" type="date" name="prochaine_action"
                               class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-4 py-2 rounded-lg font-semibold">Enregistrer la relance</button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
@endsection
