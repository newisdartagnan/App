<nav class="flex flex-wrap gap-2 mb-4" aria-label="Tarification">
    <a href="{{ route('tarification.categories') }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm {{ request()->routeIs('tarification.categories*') ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-blue-700 hover:bg-blue-50' }}">Catégories de convention</a>
    <a href="{{ route('tarification.actes') }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm {{ request()->routeIs('tarification.actes*') ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-blue-700 hover:bg-blue-50' }}">Catalogue des actes</a>
    @if(Route::has('tarifs.index'))<a href="{{ route('tarifs.index') }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm bg-white text-blue-700 hover:bg-blue-50">Tarifs publics</a>@endif
    @if(Route::has('assurances.index'))<a href="{{ route('assurances.index') }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm bg-white text-blue-700 hover:bg-blue-50">Conventions & assurances</a>@endif
</nav>
