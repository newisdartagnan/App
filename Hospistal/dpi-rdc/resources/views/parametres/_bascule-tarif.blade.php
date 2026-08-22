{{--
    Retirer du catalogue, ou l'y remettre.

    Jamais de suppression : les factures déjà émises nomment le produit, et
    une ligne d'historique qui pointe dans le vide est pire qu'un produit
    inactif.
--}}
<form method="POST" action="{{ route('tarifs.basculer', [$famille, $element->id]) }}" class="inline">
    @csrf
    @if($actif)
    <button class="text-xs text-red-700 hover:underline" title="Ne plus proposer à la prescription">
        Retirer
    </button>
    @else
    <span class="text-xs text-gray-400 mr-2">Retiré</span>
    <button class="text-xs text-green-700 hover:underline">Remettre</button>
    @endif
</form>
