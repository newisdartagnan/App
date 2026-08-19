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

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
        <div><span class="text-gray-500 text-xs">Période</span><p class="font-semibold">{{ $facture->periode_debut->format('d/m/Y') }} → {{ $facture->periode_fin->format('d/m/Y') }}</p></div>
        <div><span class="text-gray-500 text-xs">Présentation</span><p class="font-semibold capitalize">{{ $facture->mode }}</p></div>
        <div><span class="text-gray-500 text-xs">Montant</span><p class="font-semibold">{{ number_format((float) $facture->montant_total, 2, ',', ' ') }} {{ $facture->devise }}</p></div>
        <div><span class="text-gray-500 text-xs">Réglé</span><p class="font-semibold text-green-700">{{ number_format((float) $facture->montant_regle, 2, ',', ' ') }}</p></div>
        <div><span class="text-gray-500 text-xs">Reste dû</span><p class="font-semibold {{ $facture->resteDu() > 0 ? 'text-amber-700' : 'text-green-700' }}">{{ number_format($facture->resteDu(), 2, ',', ' ') }}</p></div>
    </div>

    @if($facture->mode === 'individuelle')
    <p class="text-xs text-gray-500 mb-3">Présentation individuelle : un décompte par bénéficiaire.</p>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Détail par bénéficiaire</div>
        @php $parPatient = $facture->lignes->groupBy('patient_id'); @endphp
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
</div>
@endsection
