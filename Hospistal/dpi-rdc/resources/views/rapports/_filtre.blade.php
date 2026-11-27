<div class="flex flex-wrap items-center justify-between gap-3 mb-4 dpi-sans-impression">
    <nav class="flex flex-wrap gap-2" aria-label="Rapports">
        @foreach($disponibles as $cle => [$libelle])
        <a href="{{ route('rapports.afficher', ['rapport' => $cle, 'du' => $du, 'au' => $au]) }}"
           class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm {{ $rapport === $cle ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-blue-700 hover:bg-blue-50' }}">{{ $libelle }}</a>
        @endforeach
        @if(Route::has('snis.index') && auth()->user()->hasAnyRole(['super_admin', 'directeur', 'infirmier_chef', 'agent_admin']))
        <a href="{{ route('snis.index') }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border bg-white text-blue-700 hover:bg-blue-50 text-sm">Canevas SNIS</a>
        @endif
    </nav>
</div>
<form method="GET" class="flex flex-wrap items-end gap-2 mb-4 dpi-sans-impression">
    <div><label for="r-du" class="block text-xs text-gray-600 mb-1">Du</label><input id="r-du" type="date" name="du" value="{{ $du }}" class="min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="r-au" class="block text-xs text-gray-600 mb-1">Au</label><input id="r-au" type="date" name="au" value="{{ $au }}" class="min-h-[44px] border rounded-lg px-3"></div>
    @if($services->isNotEmpty())
    <div><label for="r-srv" class="block text-xs text-gray-600 mb-1">Service</label>
        <select id="r-srv" name="service" class="min-h-[44px] border rounded-lg px-3"><option value="">Tous</option>@foreach($services as $s)<option value="{{ $s->id }}" @selected($serviceId === $s->id)>{{ $s->nom }}</option>@endforeach</select></div>
    @endif
    <button class="min-h-[44px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⏷ Filtrer</button>
    <button type="button" onclick="window.print()" class="min-h-[44px] px-4 rounded-lg border bg-white">🖨 Imprimer</button>
    <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}" class="min-h-[44px] inline-flex items-center px-4 rounded-lg border bg-white">⬇ Exporter (CSV)</a>
</form>
