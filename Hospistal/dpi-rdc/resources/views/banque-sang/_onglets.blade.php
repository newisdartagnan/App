<div class="flex flex-wrap gap-1 border-b border-gray-300 mb-5 text-sm">
    @foreach([
        'banque-sang.index' => '🧊 Stock et demandes',
        'banque-sang.donneurs' => '🤝 Fichier des donneurs',
        'banque-sang.registre' => '📖 Registre transfusionnel',
        'banque-sang.reseau' => '🌍 Réseau des banques',
    ] as $route => $libelle)
    <a href="{{ route($route) }}"
       class="px-4 py-2 rounded-t-lg border border-b-0 {{ request()->routeIs($route) ? 'bg-white font-semibold text-blue-800 border-gray-300' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100' }}">
        {{ $libelle }}
    </a>
    @endforeach
</div>
