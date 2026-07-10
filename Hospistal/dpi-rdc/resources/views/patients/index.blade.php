@extends('layouts.app')
@section('title', 'Patients')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Dossiers patients</h2>
        <a href="{{ route('patients.create') }}"
           class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            + Nouveau patient
        </a>
    </div>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" action="{{ route('patients.index') }}" class="bg-white rounded-xl shadow p-4 mb-4">
        <div class="flex gap-3">
            <label for="search" class="sr-only">Rechercher</label>
            <input id="search" name="search" type="search" value="{{ $search }}"
                placeholder="Nom, prénom, n° dossier..."
                class="flex-1 min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
            <label for="sexe" class="sr-only">Sexe</label>
            <select id="sexe" name="sexe" class="min-h-[44px] rounded-lg border border-gray-300 px-3">
                <option value="">Tous</option>
                <option value="M" @selected($sexe === 'M')>Masculin</option>
                <option value="F" @selected($sexe === 'F')>Féminin</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm font-medium">Filtrer</button>
        </div>
    </form>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">N° Dossier</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Nom complet</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Sexe</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Date naissance</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Prise en charge</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($patients as $patient)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $patient->dossier_number }}</td>
                    <td class="px-4 py-3 font-medium">{{ $patient->nom_complet }}</td>
                    <td class="px-4 py-3">{{ $patient->sexe }}</td>
                    <td class="px-4 py-3">{{ $patient->date_naissance?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded-full text-xs {{ $patient->type_prise_en_charge === 'indigent' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' }}">
                            {{ $patient->type_prise_en_charge }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-xs font-medium">Voir</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                        {{ $search ? 'Aucun patient trouvé pour « '.$search.' »' : 'Aucun patient enregistré' }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $patients->links() }}</div>
    </div>
</div>
@endsection
