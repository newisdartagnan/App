@extends('layouts.app')
@section('title', 'Admissions & lits')
@section('content')
@php
    $tauxGlobal = $totalLits > 0 ? round($totalOccupes * 100 / $totalLits) : 0;
    $libellesType = [
        'hospitalisation' => 'Hospitalisation',
        'chirurgie' => 'Chirurgie',
        'accouchement' => 'Accouchement',
    ];
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">🛏️ Admissions &amp; lits</h2>
            <p class="text-sm text-gray-500">Séjours hospitaliers et occupation des lits. Les consultations externes ont leur propre file.</p>
        </div>
        <a href="{{ route('services.index') }}" class="bg-blue-700 hover:bg-blue-800 text-white text-sm font-semibold px-4 py-2 rounded-lg">
            Services d'hospitalisation →
        </a>
    </div>

    @foreach(['success','error','info'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : ($t==='error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-blue-50 border-blue-200 text-blue-800') }}">{{ session($t) }}</div>
        @endif
    @endforeach

    {{-- Occupation des lits, service par service --}}
    <div class="bg-white rounded-xl shadow p-4 mb-5">
        <div class="flex items-baseline justify-between mb-3 flex-wrap gap-2">
            <h3 class="font-semibold text-gray-700">Occupation des lits</h3>
            <span class="text-sm">
                <span class="font-bold text-2xl {{ $tauxGlobal >= 90 ? 'text-red-700' : ($tauxGlobal >= 70 ? 'text-amber-700' : 'text-green-700') }}">{{ $tauxGlobal }} %</span>
                <span class="text-gray-500">— {{ $totalOccupes }} lits occupés sur {{ $totalLits }}</span>
            </span>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($services as $service)
            @php
                $taux = $service->lits_total > 0 ? round($service->lits_occupes * 100 / $service->lits_total) : 0;
                $libres = $service->lits_total - $service->lits_occupes;
            @endphp
            <a href="{{ route('visites.index', ['service_id' => $service->id]) }}"
               class="block rounded-lg border p-3 hover:border-blue-400 hover:bg-blue-50 {{ request('service_id') === $service->id ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }}">
                <p class="text-sm font-semibold text-gray-800">{{ $service->nom }}</p>
                <p class="text-xs text-gray-500 mb-2">{{ $libres }} libre(s) sur {{ $service->lits_total }}</p>
                <div class="h-2 rounded-full bg-gray-200 overflow-hidden">
                    <div class="h-full {{ $taux >= 90 ? 'bg-red-500' : ($taux >= 70 ? 'bg-amber-500' : 'bg-green-500') }}"
                         style="width: {{ $taux }}%"></div>
                </div>
            </a>
            @endforeach
        </div>
        @if($services->isEmpty())
        <p class="text-sm text-gray-400 text-center py-4">Aucun service d'hospitalisation actif.</p>
        @endif
    </div>

    {{-- Filtres --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-4 items-end bg-white rounded-xl shadow p-4">
        <div>
            <label for="f-type" class="block text-xs font-semibold text-gray-600 mb-1">Type de séjour</label>
            <select id="f-type" name="type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Tous les séjours</option>
                @foreach($typesSejour as $t)
                <option value="{{ $t }}" @selected(request('type') === $t)>{{ $libellesType[$t] ?? ucfirst($t) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-service" class="block text-xs font-semibold text-gray-600 mb-1">Service</label>
            <select id="f-service" name="service_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Tous les services</option>
                @foreach($services as $s)
                <option value="{{ $s->id }}" @selected(request('service_id') === $s->id)>{{ $s->nom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-statut" class="block text-xs font-semibold text-gray-600 mb-1">Statut</label>
            <select id="f-statut" name="statut" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="en_cours" @selected(request('statut','en_cours')==='en_cours')>En cours</option>
                <option value="termine" @selected(request('statut')==='termine')>Sortis</option>
                <option value="tous" @selected(request('statut')==='tous')>Tous</option>
            </select>
        </div>
        <button type="submit" class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Filtrer</button>
    </form>

    {{-- Séjours --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Patient</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Entrée</th>
                        <th class="px-4 py-3 text-center">Durée</th>
                        <th class="px-4 py-3">Service / Lit</th>
                        <th class="px-4 py-3">Prise en charge</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($visites as $visit)
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ $visit->patient->nom_complet }}</span>
                            <p class="text-xs text-gray-400">{{ $visit->patient->dossier_number }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $libellesType[$visit->type] ?? str_replace('_', ' ', $visit->type) }}</td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap">{{ $visit->date_entree->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-center font-semibold text-blue-800">{{ $visit->joursHospitalisation() }} j</td>
                        <td class="px-4 py-3 text-xs">
                            {{ $visit->service?->nom ?? '—' }}
                            @if($visit->lit)<span class="text-gray-400">· Lit {{ $visit->lit->numero }}</span>@endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if($visit->forfait)
                            <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 font-semibold">
                                Forfait {{ $visit->forfait->libelle }}
                            </span>
                            @elseif($visit->patient->type_prise_en_charge === 'assurance')
                            <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                                {{ $visit->patient->assurance_nom ?: 'Assurance' }}
                            </span>
                            @else
                            <span class="text-gray-500">{{ \App\Models\Facture::PRISES_EN_CHARGE[$visit->patient->type_prise_en_charge] ?? 'Privé' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs {{ $visit->statut==='en_cours' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' }}">
                                {{ $visit->statut === 'en_cours' ? 'En cours' : 'Sorti' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if($visit->service)
                            <a href="{{ route('services.dossier', [$visit->service, $visit]) }}" class="text-blue-700 hover:underline text-xs">Dossier</a>
                            @endif
                            <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline text-xs ml-2">Parcours →</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Aucun séjour pour ce filtre</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $visites->links() }}</div>
</div>
@endsection
