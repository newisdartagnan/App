@extends('layouts.app')
@section('title', 'Apparence')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <a href="{{ route('dashboard') }}" class="text-blue-700 hover:underline text-sm">← Accueil</a>
        <h2 class="text-2xl font-bold text-gray-800">🎨 Apparence</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Le réglage vous suit de poste en poste : un poste passe de main en main
        toute la journée, votre choix ne doit pas rester derrière vous.
    </p>

    @include('partials._flash')

    <div class="grid gap-4 md:grid-cols-2">
        @foreach($themes as $cle => $theme)
        <form method="POST" action="{{ route('apparence.enregistrer') }}"
              class="bg-white rounded-xl shadow overflow-hidden border-2 {{ $actuel === $cle ? 'border-blue-600' : 'border-transparent' }}">
            @csrf
            <input type="hidden" name="theme" value="{{ $cle }}">

            {{-- L'aperçu montre la vraie palette : un nom de thème ne dit
                 rien tant qu'on n'a pas vu ce qu'il fait aux couleurs. --}}
            <div class="h-24 flex" aria-hidden="true">
                @foreach($theme['apercu'] as $couleur)
                <div class="flex-1" style="background: {{ $couleur }}"></div>
                @endforeach
            </div>

            <div class="p-5">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <h3 class="font-semibold text-gray-800">{{ $theme['nom'] }}</h3>
                    @if($actuel === $cle)
                    <span class="text-xs font-bold uppercase bg-blue-600 text-white rounded px-2 py-0.5">actuel</span>
                    @endif
                </div>
                <p class="text-sm text-gray-600 mb-4">{{ $theme['pourquoi'] }}</p>

                <button class="w-full min-h-[44px] rounded-lg font-semibold text-sm
                    {{ $actuel === $cle
                        ? 'bg-gray-100 text-gray-500 cursor-default'
                        : 'bg-blue-700 hover:bg-blue-800 text-white' }}"
                    @disabled($actuel === $cle)>
                    {{ $actuel === $cle ? 'Thème en cours' : 'Appliquer ce thème' }}
                </button>
            </div>
        </form>
        @endforeach
    </div>

    <div class="bg-white rounded-xl shadow px-5 py-4 mt-5 text-sm text-gray-600">
        <p class="font-semibold text-gray-800 mb-1">Ce que le thème ne change pas</p>
        <p>
            Les documents imprimés restent en noir sur blanc, quel que soit votre
            réglage : un bulletin de sortie sorti en blanc sur fond noir serait
            illisible, et coûterait une cartouche d'encre par patient.
        </p>
        <p class="mt-2">
            Les couleurs d'alerte non plus — le rouge d'un dépistage positif,
            l'ambre d'un stock annoncé il y a six heures — gardent leur force dans
            tous les thèmes. Un thème qui rend une alerte discrète serait dangereux.
        </p>
    </div>
</div>
@endsection
