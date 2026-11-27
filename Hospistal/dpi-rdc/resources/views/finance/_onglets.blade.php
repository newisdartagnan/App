<div class="flex flex-wrap items-center justify-between gap-3 mb-4 dpi-sans-impression">
    <h2 class="text-2xl font-bold text-gray-800">💰 Gestion financière</h2>
    <nav class="flex flex-wrap gap-2" aria-label="Finances">
        @foreach([
            ['finance.tableau', 'Tableau de bord', 'finance.tableau'],
            ['finance.depenses', 'Dépenses', 'finance.depenses*'],
            ['finance.comptes', 'Trésorerie', 'finance.comptes*'],
            ['finance.budget', 'Budget', 'finance.budget*'],
            ['finance.resultat', 'Résultat & clôture', 'finance.resultat*'],
        ] as [$r, $lib, $motif])
        <a href="{{ route($r) }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm {{ request()->routeIs($motif) ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-blue-700 hover:bg-blue-50' }}">{{ $lib }}</a>
        @endforeach
        @if(Route::has('rapports.afficher'))<a href="{{ route('rapports.afficher', 'caisse') }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm bg-white text-blue-700 hover:bg-blue-50">Rapport de caisse</a>@endif
    </nav>
</div>
@include('catalogue._erreurs')
@php $fc = fn ($n) => number_format((float) $n, 0, ',', ' ').' FC'; @endphp
