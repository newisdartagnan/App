@extends('layouts.app')
@section('title', 'Facturation conventions')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-800">🏢 Facturation société / convention</h2>
        <span class="flex gap-3">
            <a href="{{ route('conventions.dettes') }}" class="text-sm text-blue-700 hover:underline">Dettes à recouvrer →</a>
            <a href="{{ route('caisse.billetage') }}" class="text-sm text-blue-700 hover:underline">Billetage →</a>
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

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-48">
            <label for="assurance_id" class="block text-xs text-gray-500 mb-1">Société / convention</label>
            <select id="assurance_id" name="assurance_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">— Choisir —</option>
                @foreach($assurances as $a)
                <option value="{{ $a->id }}" @selected($assurance?->id === $a->id)>{{ $a->nom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="debut" class="block text-xs text-gray-500 mb-1">Du</label>
            <input id="debut" type="date" name="debut" value="{{ $debut }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="fin" class="block text-xs text-gray-500 mb-1">Au</label>
            <input id="fin" type="date" name="fin" value="{{ $fin }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="px-4 py-2 bg-blue-700 text-white rounded-lg text-sm font-semibold">Afficher</button>
    </form>

    @if($assurance)
    @php $totalAPart = $aRefacturer->sum(fn ($f) => (float) $f->lignesTiersPayant->where('assurance_id', $assurance->id)->sum('part_assurance')); @endphp

    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b bg-amber-50 flex justify-between items-center flex-wrap gap-2">
            <span class="font-semibold text-amber-900">
                À refacturer à {{ $assurance->nom }} — {{ $aRefacturer->count() }} facture(s)
            </span>
            <span class="font-bold text-amber-900">{{ number_format($totalAPart, 0, ',', ' ') }} CDF</span>
        </div>

        @if($aRefacturer->isEmpty())
        <p class="px-4 py-10 text-center text-sm text-gray-400">
            Aucune facture patient en attente de refacturation sur cette période.
        </p>
        @else
        <div class="overflow-x-auto max-h-96">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="px-4 py-2 text-left">Facture</th>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Bénéficiaire</th>
                        <th class="px-4 py-2 text-right">Total facture</th>
                        <th class="px-4 py-2 text-right">Part convention</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($aRefacturer as $f)
                    @php $part = (float) $f->lignesTiersPayant->where('assurance_id', $assurance->id)->sum('part_assurance'); @endphp
                    <tr>
                        <td class="px-4 py-2 font-mono text-xs">{{ $f->numero_facture }}</td>
                        <td class="px-4 py-2 text-xs">{{ $f->date_facture->format('d/m/Y') }}</td>
                        <td class="px-4 py-2">{{ $f->patient->nom_complet }}</td>
                        <td class="px-4 py-2 text-right text-gray-500">{{ number_format((float) $f->total_ttc, 0, ',', ' ') }}</td>
                        <td class="px-4 py-2 text-right font-semibold">{{ number_format($part, 0, ',', ' ') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('conventions.emettre') }}" class="px-4 py-4 border-t bg-gray-50 flex flex-wrap gap-3 items-end">
            @csrf
            <input type="hidden" name="assurance_id" value="{{ $assurance->id }}">
            <input type="hidden" name="debut" value="{{ $debut }}">
            <input type="hidden" name="fin" value="{{ $fin }}">
            <div>
                <label for="mode" class="block text-xs text-gray-500 mb-1">Présentation</label>
                <select id="mode" name="mode" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="collective">Collective (un document)</option>
                    <option value="individuelle">Individuelle (par bénéficiaire)</option>
                </select>
            </div>
            <div>
                <label for="devise" class="block text-xs text-gray-500 mb-1">Devise</label>
                <select id="devise" name="devise" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\FactureConvention::DEVISES as $code => $libelle)
                    <option value="{{ $code }}">{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="taux_change" class="block text-xs text-gray-500 mb-1">Taux (1 devise = ? CDF)</label>
                <input id="taux_change" type="number" step="0.0001" min="0.0001" name="taux_change" value="1"
                    class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-5 py-2 rounded-lg font-semibold">
                Émettre la facture de convention
            </button>
        </form>
        @endif
    </div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Factures de convention émises</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Numéro</th>
                    <th class="px-4 py-2 text-left">Convention</th>
                    <th class="px-4 py-2 text-left">Période</th>
                    <th class="px-4 py-2 text-center">Bénéficiaires</th>
                    <th class="px-4 py-2 text-right">Montant</th>
                    <th class="px-4 py-2 text-right">Reste dû</th>
                    <th class="px-4 py-2 text-left">Statut</th>
                    <th class="px-4 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($facturesConvention as $f)
                <tr>
                    <td class="px-4 py-2 font-mono text-xs">{{ $f->numero }}</td>
                    <td class="px-4 py-2">{{ $f->assurance->nom }}</td>
                    <td class="px-4 py-2 text-xs">{{ $f->periode_debut->format('d/m/Y') }} → {{ $f->periode_fin->format('d/m/Y') }}</td>
                    <td class="px-4 py-2 text-center">{{ $f->lignes->count() }}</td>
                    <td class="px-4 py-2 text-right font-semibold">{{ number_format((float) $f->montant_total, 0, ',', ' ') }} {{ $f->devise }}</td>
                    <td class="px-4 py-2 text-right {{ $f->resteDu() > 0 ? 'text-amber-700 font-semibold' : 'text-green-700' }}">
                        {{ number_format($f->resteDu(), 0, ',', ' ') }}
                    </td>
                    <td class="px-4 py-2">
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded
                            {{ match($f->statut) {
                                'reglee' => 'bg-green-100 text-green-800',
                                'partiellement_reglee' => 'bg-amber-100 text-amber-800',
                                'annulee' => 'bg-gray-100 text-gray-600',
                                default => 'bg-blue-100 text-blue-800',
                            } }}">{{ str_replace('_', ' ', $f->statut) }}</span>
                    </td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('conventions.show', $f) }}" class="text-blue-700 text-xs hover:underline">Détail →</a></td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Aucune facture de convention</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
