{{-- Photo de profil ronde, ou les initiales. Paramètres : $u, $taille (px) --}}
@php $taille = $taille ?? 40; @endphp
@if($u->photoUrl())
<img src="{{ $u->photoUrl() }}" alt="" width="{{ $taille }}" height="{{ $taille }}" class="rounded-full object-cover shrink-0" style="width:{{ $taille }}px;height:{{ $taille }}px">
@else
<span class="rounded-full bg-blue-200 text-blue-900 font-bold inline-flex items-center justify-center shrink-0" style="width:{{ $taille }}px;height:{{ $taille }}px;font-size:{{ (int) ($taille / 2.6) }}px">{{ $u->initiales() }}</span>
@endif
