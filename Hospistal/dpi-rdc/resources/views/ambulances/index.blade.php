@extends('layouts.app')
@section('title', 'Ambulances')
@section('content')
@include('catalogue._onglets')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-2xl font-bold text-gray-800">🚑 Ambulances</h2>
        @if(Route::has('rapports.afficher'))<a href="{{ route('rapports.afficher', 'ambulances') }}" class="min-h-[40px] inline-flex items-center px-4 rounded-lg border bg-white text-blue-700 text-sm font-semibold">📊 Rapport</a>@endif
    </div>
    @include('catalogue._erreurs')

    <div class="grid md:grid-cols-3 gap-4 items-start">
        <section class="bg-white border rounded-xl shadow-sm p-4 md:col-span-2">
            <h3 class="font-semibold text-green-800 mb-3">Nouvelle sortie</h3>
            @if($ambulances->where('is_active', true)->isEmpty())
                <p class="text-sm text-amber-700">Ajoutez d'abord une ambulance (à droite).</p>
            @else
            <form method="POST" action="{{ route('ambulances.sorties.store') }}" class="grid sm:grid-cols-3 gap-3">
                @csrf
                <div><label for="am-amb" class="block text-sm font-medium mb-1">Ambulance</label>
                    <select id="am-amb" name="ambulance_id" class="w-full min-h-[44px] border rounded-lg px-3">@foreach($ambulances->where('is_active', true) as $a)<option value="{{ $a->id }}">{{ $a->libelle }} {{ $a->immatriculation }}</option>@endforeach</select></div>
                <div class="sm:col-span-2"><label for="am-motif" class="block text-sm font-medium mb-1">Motif <span class="text-red-500">*</span></label>
                    <input id="am-motif" name="motif" required value="{{ old('motif') }}" placeholder="Évacuation, transfert, recherche de sang…" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="am-dest" class="block text-sm font-medium mb-1">Destination</label>
                    <input id="am-dest" name="destination" value="{{ old('destination') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="am-ch" class="block text-sm font-medium mb-1">Chauffeur</label>
                    <input id="am-ch" name="chauffeur" value="{{ old('chauffeur') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="am-km" class="block text-sm font-medium mb-1">Compteur au départ (km)</label>
                    <input id="am-km" name="km_depart" type="number" step="0.1" min="0" value="{{ old('km_depart') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="am-dep" class="block text-sm font-medium mb-1">Départ <span class="text-red-500">*</span></label>
                    <input id="am-dep" name="depart_at" type="datetime-local" required value="{{ old('depart_at', now()->format('Y-m-d\TH:i')) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="am-arr" class="block text-sm font-medium mb-1">Arrivée (si déjà rentrée)</label>
                    <input id="am-arr" name="arrivee_at" type="datetime-local" value="{{ old('arrivee_at') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div><label for="am-kma" class="block text-sm font-medium mb-1">Compteur au retour (km)</label>
                    <input id="am-kma" name="km_arrivee" type="number" step="0.1" min="0" value="{{ old('km_arrivee') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                <div class="sm:col-span-3"><button class="min-h-[44px] px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">Enregistrer la sortie</button></div>
            </form>
            @endif
        </section>

        <section class="bg-white border rounded-xl shadow-sm p-4">
            <h3 class="font-semibold text-green-800 mb-3">Parc ({{ $ambulances->count() }})</h3>
            <ul class="divide-y text-sm mb-3">
                @foreach($ambulances as $a)
                <li class="py-2 flex items-center justify-between {{ $a->is_active ? '' : 'opacity-50' }}">
                    <span>{{ $a->libelle }} <span class="text-xs text-gray-500 font-mono">{{ $a->immatriculation }}</span></span>
                    <form method="POST" action="{{ route('ambulances.basculer', $a) }}">@csrf<button class="text-xs {{ $a->is_active ? 'text-red-700' : 'text-green-700' }} hover:underline">{{ $a->is_active ? 'Retirer' : 'Remettre' }}</button></form>
                </li>
                @endforeach
            </ul>
            <form method="POST" action="{{ route('ambulances.store') }}" class="space-y-2">
                @csrf
                <label for="am-new" class="sr-only">Nom de l'ambulance</label>
                <input id="am-new" name="libelle" required placeholder="Nom (ex. : Ambulance 1)" class="w-full min-h-[40px] border rounded-lg px-3 text-sm">
                <label for="am-imm" class="sr-only">Immatriculation</label>
                <input id="am-imm" name="immatriculation" placeholder="Immatriculation" class="w-full min-h-[40px] border rounded-lg px-3 text-sm">
                <button class="min-h-[40px] px-4 rounded-lg border text-sm font-semibold text-blue-700 hover:bg-blue-50">⊕ Ajouter une ambulance</button>
            </form>
        </section>
    </div>

    @if($enCours->isNotEmpty())
    <section class="bg-amber-50 border border-amber-200 rounded-xl p-4">
        <h3 class="font-semibold text-amber-900 mb-2">En mission ({{ $enCours->count() }})</h3>
        @foreach($enCours as $s)
        <form method="POST" action="{{ route('ambulances.sorties.retour', $s) }}" class="flex flex-wrap items-end gap-2 py-2 border-t border-amber-200 text-sm">
            @csrf
            <span class="flex-1 min-w-[12rem]"><strong>{{ $s->ambulance?->libelle }}</strong> — {{ $s->motif }} — partie {{ $s->depart_at->format('d/m H:i') }}</span>
            <label class="sr-only" for="r-arr-{{ $s->id }}">Arrivée</label>
            <input id="r-arr-{{ $s->id }}" name="arrivee_at" type="datetime-local" required value="{{ now()->format('Y-m-d\TH:i') }}" class="min-h-[40px] border rounded-lg px-2">
            <label class="sr-only" for="r-km-{{ $s->id }}">Compteur au retour</label>
            <input id="r-km-{{ $s->id }}" name="km_arrivee" type="number" step="0.1" placeholder="km retour" class="w-28 min-h-[40px] border rounded-lg px-2">
            <button class="min-h-[40px] px-3 bg-green-700 text-white rounded-lg font-semibold">Retour</button>
        </form>
        @endforeach
    </section>
    @endif

    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div><label for="am-du" class="block text-xs text-gray-600 mb-1">Du</label><input id="am-du" type="date" name="du" value="{{ $du }}" class="min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="am-au" class="block text-xs text-gray-600 mb-1">Au</label><input id="am-au" type="date" name="au" value="{{ $au }}" class="min-h-[44px] border rounded-lg px-3"></div>
        <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Filtrer</button>
    </form>
    <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Ambulance</th><th class="px-4 py-3">Motif / destination</th><th class="px-4 py-3">Départ</th><th class="px-4 py-3">Arrivée</th><th class="px-4 py-3">Durée</th><th class="px-4 py-3">Distance</th></tr></thead>
            <tbody class="divide-y">
            @forelse($sorties as $s)
                <tr>
                    <td class="px-4 py-2">{{ $s->depart_at->format('d/m/Y') }}</td>
                    <td class="px-4 py-2">{{ $s->ambulance?->libelle }}</td>
                    <td class="px-4 py-2">{{ $s->motif }}<div class="text-xs text-gray-500">{{ $s->destination }}</div></td>
                    <td class="px-4 py-2">{{ $s->depart_at->format('H:i') }}</td>
                    <td class="px-4 py-2">{{ $s->arrivee_at?->format('H:i') ?? 'en mission' }}</td>
                    <td class="px-4 py-2">{{ $s->dureeMinutes() !== null ? intdiv($s->dureeMinutes(), 60).' h '.str_pad($s->dureeMinutes() % 60, 2, '0', STR_PAD_LEFT) : '—' }}</td>
                    <td class="px-4 py-2">{{ $s->distanceKm() !== null ? number_format($s->distanceKm(), 1, ',', ' ').' km' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Aucune sortie sur la période.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $sorties->links() }}</div>
    </div>
</div>
@endsection
