@extends('layouts.app')
@section('title', 'Visites')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Visites & hospitalisation</h2>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif

    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <select name="type" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">Tous types</option>
            @foreach(['consultation_externe','urgence','hospitalisation','chirurgie','accouchement'] as $t)
            <option value="{{ $t }}" @selected(request('type')===$t)>{{ str_replace('_',' ',ucfirst($t)) }}</option>
            @endforeach
        </select>
        <select name="statut" class="border rounded-lg px-3 py-2 text-sm">
            <option value="en_cours" @selected(request('statut','en_cours')==='en_cours')>En cours</option>
            <option value="termine" @selected(request('statut')==='termine')>Terminées</option>
            <option value="" @selected(request('statut')==='')>Toutes</option>
        </select>
        <button type="submit" class="bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">Filtrer</button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="px-4 py-3">Patient</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Entrée</th>
                    <th class="px-4 py-3">Service / Lit</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($visites as $visit)
                <tr class="border-t hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium">{{ $visit->patient->nom_complet }}</td>
                    <td class="px-4 py-3">{{ str_replace('_',' ', $visit->type) }}</td>
                    <td class="px-4 py-3">{{ $visit->date_entree->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        @if($visit->service) {{ $visit->service->nom }} @endif
                        @if($visit->lit) — Lit {{ $visit->lit->numero }} @endif
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs {{ $visit->statut==='en_cours' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700' }}">
                            {{ ucfirst($visit->statut) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline">Parcours →</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucune visite</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $visites->links() }}</div>
</div>
@endsection
