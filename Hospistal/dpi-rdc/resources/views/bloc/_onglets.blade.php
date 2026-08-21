{{-- Navigation interne du bloc opératoire, calquée sur le circuit réel :
     on demande, on planifie, on opère, on inscrit au registre. --}}
<div class="flex flex-wrap gap-1 border-b border-gray-300 mb-5 text-sm">
    @foreach([
        'bloc.programme' => '📋 Programme préopératoire',
        'bloc.horaire' => '📅 Horaire du bloc',
        'bloc.interventions' => '🔪 Interventions à clôturer',
        'bloc.registre' => '📖 Registre',
    ] as $route => $libelle)
    <a href="{{ route($route) }}"
       class="px-4 py-2 rounded-t-lg border border-b-0 {{ request()->routeIs($route) ? 'bg-white font-semibold text-blue-800 border-gray-300' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100' }}">
        {{ $libelle }}
    </a>
    @endforeach
</div>
