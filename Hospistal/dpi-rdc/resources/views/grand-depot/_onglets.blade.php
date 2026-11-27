<div class="flex flex-wrap items-center justify-between gap-3 mb-4 dpi-sans-impression">
    <h2 class="text-2xl font-bold text-gray-800">🏬 Grand dépôt pharmaceutique</h2>
    <nav class="flex flex-wrap gap-2" aria-label="Grand dépôt">
        @foreach([
            ['grand-depot.tableau', 'Tableau de bord', 'grand-depot.tableau'],
            ['grand-depot.commandes', 'Commandes', 'grand-depot.commandes*'],
            ['grand-depot.receptions', 'Réceptions', 'grand-depot.receptions*'],
            ['grand-depot.inventaires', 'Inventaires', 'grand-depot.inventaires*'],
            ['grand-depot.destructions', 'Périmés & destructions', 'grand-depot.destructions*'],
            ['grand-depot.fournisseurs', 'Fournisseurs', 'grand-depot.fournisseurs*'],
            ['officines.depot', 'Réquisitions des officines', 'officines.depot'],
        ] as [$r, $lib, $motif])
        <a href="{{ route($r) }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm {{ request()->routeIs($motif) ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-blue-700 hover:bg-blue-50' }}">{{ $lib }}</a>
        @endforeach
    </nav>
</div>
@include('catalogue._erreurs')
