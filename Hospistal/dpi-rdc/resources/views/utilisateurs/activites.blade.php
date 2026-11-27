@extends('layouts.app')
@section('title', 'Activités')
@section('content')
<div class="space-y-4">
    <div>
        @if($utilisateur && $utilisateur->id === auth()->id())
        <a href="{{ route('compte.index') }}" class="text-blue-700 hover:underline text-sm">← Mon compte</a>
        @else
        <a href="{{ route('utilisateurs.index') }}" class="text-blue-700 hover:underline text-sm">← Utilisateurs</a>
        @endif
        <h2 class="text-2xl font-bold text-gray-800">Activités {{ $utilisateur ? 'de '.$utilisateur->nom_complet : 'des utilisateurs' }}</h2>
        <p class="text-sm text-gray-500">Chaque action enregistrée dans le système, avec son auteur, son heure et le poste d'où elle vient.</p>
    </div>

    <form method="GET" class="bg-white rounded-xl shadow p-4 flex flex-wrap gap-3 items-end">
        <div><label for="a-du" class="block text-xs font-medium text-gray-600 mb-1">Du</label>
            <input id="a-du" type="date" name="du" value="{{ $du }}" class="min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="a-au" class="block text-xs font-medium text-gray-600 mb-1">Au</label>
            <input id="a-au" type="date" name="au" value="{{ $au }}" class="min-h-[44px] border rounded-lg px-3"></div>
        <button class="min-h-[44px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">Filtrer</button>
    </form>

    <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr>
                <th class="px-4 py-3">Date</th>@unless($utilisateur)<th class="px-4 py-3">Utilisateur</th>@endunless
                <th class="px-4 py-3">Activité</th><th class="px-4 py-3">Poste (IP)</th>
            </tr></thead>
            <tbody class="divide-y">
            @forelse($journal as $log)
                <tr>
                    <td class="px-4 py-2 whitespace-nowrap">{{ $log->created_at?->format('d/m/Y H:i:s') }} <span class="text-xs text-gray-400">{{ $log->created_at?->diffForHumans() }}</span></td>
                    @unless($utilisateur)<td class="px-4 py-2">{{ $log->user?->nom_complet ?? 'Système' }}</td>@endunless
                    <td class="px-4 py-2">{{ $activites->libelle($log) }}</td>
                    <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Aucune activité sur cette période.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $journal->links() }}</div>
    </div>
</div>
@endsection
