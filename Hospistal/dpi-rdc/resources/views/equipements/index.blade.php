@extends('layouts.app')
@section('title', 'Équipements')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-6">Équipements & machines</h2>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>@endif
    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    <details class="bg-white rounded-xl shadow mb-6">
        <summary class="cursor-pointer select-none px-5 py-3 font-semibold text-blue-700 hover:bg-blue-50 rounded-xl">+ Ajouter un équipement</summary>
        <form method="POST" action="{{ route('equipements.store') }}" class="px-5 pb-5 pt-2 border-t grid grid-cols-2 md:grid-cols-4 gap-4 items-end">
            @csrf
            <div>
                <label for="eq-nom" class="block text-sm font-medium text-gray-700 mb-1">Nom <span class="text-red-500">*</span></label>
                <input id="eq-nom" name="nom" required class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
            </div>
            <div>
                <label for="eq-type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select id="eq-type" name="type" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
                    <option value="labo">Laboratoire</option>
                    <option value="imagerie">Imagerie</option>
                    <option value="autre">Autre</option>
                </select>
            </div>
            <div>
                <label for="eq-marque" class="block text-sm font-medium text-gray-700 mb-1">Marque</label>
                <input id="eq-marque" name="marque" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
            </div>
            <div>
                <label for="eq-modele" class="block text-sm font-medium text-gray-700 mb-1">Modèle</label>
                <input id="eq-modele" name="modele" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
            </div>
            <div>
                <label for="eq-serie" class="block text-sm font-medium text-gray-700 mb-1">N° série</label>
                <input id="eq-serie" name="numero_serie" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
            </div>
            <div>
                <label for="eq-loc" class="block text-sm font-medium text-gray-700 mb-1">Localisation</label>
                <input id="eq-loc" name="localisation" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
            </div>
            <div>
                <label for="eq-maint" class="block text-sm font-medium text-gray-700 mb-1">Prochaine maintenance</label>
                <input id="eq-maint" name="prochaine_maintenance" type="date" class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
            </div>
            <button type="submit" class="min-h-[44px] bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg">Enregistrer</button>
        </form>
    </details>

    @forelse($equipements as $type => $groupe)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b bg-gray-50 font-semibold text-sm text-gray-700 uppercase tracking-wide">
            {{ $type === 'labo' ? '🔬 Laboratoire' : ($type === 'imagerie' ? '📷 Imagerie' : 'Autres') }} ({{ $groupe->count() }})
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b"><tr>
                <th class="text-left px-4 py-2 font-medium text-gray-600">Équipement</th>
                <th class="text-left px-4 py-2 font-medium text-gray-600">Marque / modèle</th>
                <th class="text-left px-4 py-2 font-medium text-gray-600">N° série</th>
                <th class="text-left px-4 py-2 font-medium text-gray-600">Localisation</th>
                <th class="text-left px-4 py-2 font-medium text-gray-600">Statut</th>
                <th class="text-left px-4 py-2 font-medium text-gray-600">Maintenance</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($groupe as $eq)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium">{{ $eq->nom }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $eq->marque }} {{ $eq->modele }}</td>
                    <td class="px-4 py-3 text-gray-500 font-mono text-xs">{{ $eq->numero_serie ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $eq->localisation ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs {{ str_contains($eq->statut, 'operation') ? 'bg-green-100 text-green-700' : ($eq->statut === 'maintenance' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700') }}">
                            {{ $eq->statut }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600 text-xs">
                        @if($eq->date_derniere_maintenance) Dernière : {{ $eq->date_derniere_maintenance->format('d/m/Y') }}<br>@endif
                        @if($eq->prochaine_maintenance)
                        <span class="{{ $eq->prochaine_maintenance->isPast() ? 'text-red-600 font-semibold' : '' }}">Prochaine : {{ $eq->prochaine_maintenance->format('d/m/Y') }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @empty
    <p class="text-gray-400 text-center py-8">Aucun équipement — utilisez « php artisan dpi:import-csk » pour reprendre les machines de l'ancien système.</p>
    @endforelse
</div>
@endsection
