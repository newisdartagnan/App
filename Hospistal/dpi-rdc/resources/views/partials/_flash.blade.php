{{--
    Messages de retour, communs à tous les écrans.

    Le bloc est inclus par la mise en page pour que toute erreur de validation
    soit vue, y compris sur les écrans qui ne l'affichaient pas : un formulaire
    refusé sans message donne l'impression que le bouton ne marche pas. La
    garde @once évite le doublon là où la vue l'inclut déjà elle-même.
--}}
@once
@foreach(['success','error','info'] as $t)
    @if(session($t))
    <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : ($t==='error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-blue-50 border-blue-200 text-blue-800') }}">{{ session($t) }}</div>
    @endif
@endforeach

@if ($errors->any())
<div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
    @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
</div>
@endif
@endonce
