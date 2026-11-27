{{-- Navigation du paramétrage de l'établissement --}}
<nav class="flex flex-wrap gap-2 mb-4" aria-label="Catalogue de l'établissement">
    @foreach([
        ['catalogue.examens.index', '🔬 Examens de laboratoire'],
        ['catalogue.imagerie.index', '🩻 Techniques d\'imagerie'],
        ['catalogue.services.index', '🏥 Services'],
        ['catalogue.locaux.index', '🚪 Locaux'],
        ['catalogue.lits.index', '🛏️ Lits'],
        ['ambulances.index', '🚑 Ambulances'],
        ['methodes-paiement.index', '💳 Méthodes de paiement'],
    ] as [$r, $lib])
        @if(Route::has($r))
        <a href="{{ route($r) }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm {{ request()->routeIs(Str::beforeLast($r, '.').'.*') ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-blue-700 hover:bg-blue-50' }}">{{ $lib }}</a>
        @endif
    @endforeach
</nav>
