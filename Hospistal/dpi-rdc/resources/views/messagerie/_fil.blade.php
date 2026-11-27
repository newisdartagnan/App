@forelse($messages as $m)
    @php $deMoi = $m->expediteur_id === $moi->id; @endphp
    <div class="flex {{ $deMoi ? 'justify-end' : 'justify-start' }}">
        <div class="max-w-[75%] rounded-2xl px-4 py-2 text-sm {{ $deMoi ? 'bg-blue-700 text-white rounded-br-sm' : 'bg-gray-100 text-gray-900 rounded-bl-sm' }}">
            @if($m->supprime)
                <em class="opacity-70">Message supprimé</em>
            @else
                <p class="whitespace-pre-wrap break-words">{{ $m->contenu }}</p>
            @endif
            <div class="flex items-center justify-end gap-2 mt-1 text-[11px] {{ $deMoi ? 'text-blue-100' : 'text-gray-500' }}">
                <span>{{ $m->created_at->format('d/m H:i') }}</span>
                @if($deMoi && $m->lu_at && ! $m->supprime)<span title="Lu {{ $m->lu_at->format('d/m H:i') }}">✓✓</span>@endif
                @unless($m->supprime)
                <details class="relative">
                    <summary class="cursor-pointer list-none px-1" aria-label="Plus d'options">⋯</summary>
                    <form method="POST" action="{{ route('messagerie.supprimer', $m) }}" class="js-supprimer absolute right-0 z-10 mt-1 bg-white border rounded shadow">
                        @csrf @method('DELETE')
                        <button class="px-3 py-1 text-xs text-red-700 whitespace-nowrap hover:bg-red-50">Supprimer</button>
                    </form>
                </details>
                @endunless
            </div>
        </div>
    </div>
@empty
    <p class="text-center text-sm text-gray-500 py-6">Aucun message. Écrivez le premier.</p>
@endforelse
