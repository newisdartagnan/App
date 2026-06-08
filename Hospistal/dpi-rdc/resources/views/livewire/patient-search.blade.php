<div>
    <label for="patient-search" class="block text-sm font-medium text-gray-700 mb-1">Rechercher un patient</label>
    <input
        id="patient-search"
        type="search"
        wire:model.live.debounce.300ms="query"
        placeholder="Nom, prénom, n° dossier, téléphone..."
        class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 text-base focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
        autocomplete="off"
    >

    @if(strlen($query) >= 2)
        <div class="mt-4 space-y-2">
            @forelse($patients as $patient)
                <div
                   class="block p-4 min-h-[44px] rounded-lg border border-gray-200 hover:border-blue-400 hover:bg-blue-50 transition">
                    <div class="font-semibold">{{ $patient->nom }} {{ $patient->prenom }}</div>
                    <div class="text-sm text-gray-600">
                        {{ $patient->dossier_number }}
                        @if($patient->date_naissance) — {{ $patient->date_naissance->format('d/m/Y') }} @endif
                    </div>
                </div>
            @empty
                <p class="text-gray-500 text-sm py-4">Aucun patient trouvé.</p>
            @endforelse

            {{ $patients->links() }}
        </div>
    @endif
</div>
