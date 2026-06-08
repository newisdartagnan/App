<div class="text-right text-xs">
    @if($isStale)
        <span class="inline-block px-2 py-1 bg-amber-600 rounded text-white">Hors sync</span>
    @else
        <span class="inline-block px-2 py-1 bg-green-600 rounded">Synchronisé</span>
    @endif
    @if($pending > 0)
        <span class="ml-1 text-blue-200">{{ $pending }} en attente</span>
    @endif
    @if($conflicts > 0)
        <span class="ml-1 text-red-300">{{ $conflicts }} conflit(s)</span>
    @endif
</div>
