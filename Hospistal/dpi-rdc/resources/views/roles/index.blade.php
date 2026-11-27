@extends('layouts.app')
@section('title', 'Rôles des utilisateurs')
@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('utilisateurs.index') }}" class="text-blue-700 hover:underline text-sm">← Utilisateurs</a>
            <h2 class="text-2xl font-bold text-gray-800">Rôles des utilisateurs ({{ $roles->count() }})</h2>
            <p class="text-sm text-gray-500">Un rôle est une fonction du personnel. Il regroupe les permissions qui décident de ce que l'agent voit et gère.</p>
        </div>
        <a href="{{ route('roles.create') }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Nouveau</a>
    </div>

    <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr><th class="px-4 py-3">#</th><th class="px-4 py-3">Désignation</th><th class="px-4 py-3">Utilisateurs</th><th class="px-4 py-3">Permissions</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y">
            @foreach($roles as $i => $role)
                <tr>
                    <td class="px-4 py-3 text-gray-500">{{ $i + 1 }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('roles.edit', $role) }}" class="font-semibold text-blue-700 hover:underline">{{ \App\Http\Controllers\RoleController::libelle($role) }}</a>
                        @if($role->description)<p class="text-xs text-gray-500">{{ $role->description }}</p>@endif
                    </td>
                    <td class="px-4 py-3">{{ $role->users_count }}</td>
                    <td class="px-4 py-3">{{ $role->name === 'super_admin' ? 'Toutes' : $role->permissions_count }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('roles.edit', $role) }}" class="inline-flex items-center min-h-[36px] px-3 rounded-lg border text-blue-700 hover:bg-blue-50 text-xs font-semibold">✎ Modifier</a>
                        @if($role->name !== 'super_admin')
                        <form method="POST" action="{{ route('roles.destroy', $role) }}" class="inline" onsubmit="return confirm('Supprimer ce rôle ?');">
                            @csrf @method('DELETE')
                            <button class="inline-flex items-center min-h-[36px] px-3 rounded-lg border text-red-700 hover:bg-red-50 text-xs font-semibold"
                                    @disabled($role->users_count > 0) title="{{ $role->users_count > 0 ? 'Rôle attribué : suppression impossible' : 'Supprimer' }}">🗑</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
