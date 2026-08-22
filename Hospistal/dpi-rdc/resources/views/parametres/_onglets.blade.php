<div class="flex flex-wrap gap-1 border-b border-gray-300 mb-5 text-sm">
    @foreach([
        'parametres.index' => '💱 Taux de change',
        'tarifs.index' => '🏷️ Tarifs et catalogues',
        'utilisateurs.index' => '👤 Comptes du personnel',
        'assurances.index' => '🤝 Conventions et assurances',
        'forfaits.index' => '📦 Forfaits',
    ] as $route => $libelle)
    <a href="{{ route($route) }}"
       class="px-4 py-2 rounded-t-lg border border-b-0 {{ request()->routeIs($route) ? 'bg-white font-semibold text-blue-800 border-gray-300' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100' }}">
        {{ $libelle }}
    </a>
    @endforeach
</div>
