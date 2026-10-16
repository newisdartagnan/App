@extends('layouts.app')
@section('title', 'Registre des avoirs')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-1">
        <h2 class="text-2xl font-bold text-gray-800">Registre des avoirs</h2>
        <form method="GET" action="{{ route('avoirs.index') }}" class="flex items-end gap-2">
            <div>
                <label for="mois" class="block text-xs text-gray-500 mb-1">Mois</label>
                <input id="mois" type="month" name="mois" value="{{ $mois->format('Y-m') }}"
                       class="min-h-[40px] rounded-lg border border-gray-300 px-3 py-1 text-sm">
            </div>
            <button class="min-h-[40px] px-4 py-1 rounded-lg bg-blue-700 hover:bg-blue-800 text-white text-sm font-medium">Afficher</button>
        </form>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Chaque facture corrigée ou remise en {{ $mois->translatedFormat('F Y') }}, par qui et pourquoi.
        Un même motif qui revient dit où le circuit de facturation se trompe.
    </p>

    @if($parMotif->isNotEmpty())
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 mb-5">
        @foreach($parMotif as $motif)
        <div class="bg-white rounded-xl shadow px-4 py-3">
            <p class="text-xs text-gray-500">{{ $motif['libelle'] }}</p>
            <p class="text-lg font-bold text-gray-800">
                {{ $motif['nombre'] }} avoir{{ $motif['nombre'] > 1 ? 's' : '' }}
                <span class="text-sm font-normal text-gray-500">— {{ app(\App\Services\DeviseService::class)->formater($motif['montant'], 'CDF') }}</span>
            </p>
        </div>
        @endforeach
    </div>
    @endif

    @if($remisesParAuteur->isNotEmpty())
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm text-amber-900">
        <p class="font-semibold mb-1">Remises accordées ce mois</p>
        <ul class="space-y-0.5">
            @foreach($remisesParAuteur as $r)
            <li>{{ $r['auteur'] }} — {{ $r['nombre'] }} remise{{ $r['nombre'] > 1 ? 's' : '' }},
                {{ app(\App\Services\DeviseService::class)->formater($r['montant'], 'CDF') }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        @if($avoirs->isEmpty())
        <p class="px-5 py-8 text-sm text-gray-400">Aucun avoir établi ce mois-ci.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Avoir</th>
                        <th class="px-4 py-2 text-left">Facture</th>
                        <th class="px-4 py-2 text-left">Patient</th>
                        <th class="px-4 py-2 text-left">Nature</th>
                        <th class="px-4 py-2 text-right">Montant</th>
                        <th class="px-4 py-2 text-left">Par</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($avoirs as $avoir)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">
                            <a href="{{ route('avoirs.show', $avoir) }}" class="text-blue-700 hover:underline font-medium">{{ $avoir->numero }}</a>
                            <p class="text-xs text-gray-500">{{ $avoir->date_avoir->format('d/m/Y H:i') }} · {{ $avoir->estRemise() ? $avoir->libelleCourt() : $avoir->libelleType() }}</p>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            <a href="{{ route('caisse.show', $avoir->facture_id) }}" class="text-blue-700 hover:underline">{{ $avoir->facture?->numero_facture }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $avoir->patient?->nom_complet }}</td>
                        <td class="px-4 py-2">
                            {{ $avoir->libelleMotif() }}
                            <p class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($avoir->motif, 90) }}</p>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap font-medium">{{ $avoir->formater((float) $avoir->montant_total) }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $avoir->auteur?->nom_complet }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
