@extends('layouts.app')
@section('title', 'Recherche')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-4">
        <a href="{{ route('dashboard') }}" class="text-blue-700 hover:underline text-sm">← Accueil</a>
        <h2 class="text-2xl font-bold text-gray-800">🔎 Recherche</h2>
    </div>

    <form method="GET" action="{{ route('recherche') }}" class="bg-white rounded-xl shadow p-4 mb-5 flex flex-wrap gap-3">
        <label for="q-page" class="sr-only">Chercher</label>
        <input id="q-page" name="q" value="{{ $terme }}" autofocus
               placeholder="Nom, n° de dossier, facture, bon d'examen, téléphone…"
               class="flex-1 min-w-64 min-h-[44px] rounded-lg border border-gray-300 px-4 py-2">
        <button class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-6 rounded-lg min-h-[44px]">
            Chercher
        </button>
    </form>

    @if($tropCourt)
    <div class="bg-white rounded-xl shadow px-5 py-10 text-center text-gray-500">
        Deux lettres au moins : en deçà, tout ressemble à tout.
    </div>
    @elseif($terme === '')
    <div class="bg-white rounded-xl shadow px-5 py-10 text-center text-gray-500">
        <p>Cherchez un patient par son nom, son numéro de dossier ou son téléphone.</p>
        <p class="text-sm mt-2">
            Un numéro de facture ou de bon d'examen mène directement à sa fiche —
            utile quand on a le papier en main sans savoir à qui il est.
        </p>
    </div>
    @elseif($total === 0)
    <div class="bg-white rounded-xl shadow px-5 py-10 text-center text-gray-500">
        Rien ne correspond à « <strong>{{ $terme }}</strong> ».
        <p class="text-sm mt-2">
            Essayez un nom seul, ou le numéro de dossier complet.
        </p>
    </div>
    @else

    <p class="text-sm text-gray-500 mb-4">
        {{ $total }} résultat(s) pour « <strong>{{ $terme }}</strong> ».
    </p>

    @foreach($familles as $famille)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            {{ $famille['icone'] }} {{ $famille['titre'] }}
            <span class="text-gray-400 font-normal text-sm">— {{ $famille['resultats']->count() }}</span>
        </div>
        <div class="divide-y divide-gray-100">
            @foreach($famille['resultats'] as $resultat)
            <a href="{{ $resultat['url'] }}" class="block px-5 py-3 hover:bg-blue-50">
                <p class="font-medium text-gray-800">{{ $resultat['titre'] }}</p>
                <p class="text-xs text-gray-500">{{ $resultat['detail'] }}</p>
            </a>
            @endforeach
        </div>
    </div>
    @endforeach
    @endif
</div>
@endsection
