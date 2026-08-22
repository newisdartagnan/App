@extends('layouts.app')
@section('title', 'Réseau des banques de sang')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🌍 Réseau des banques de sang</h2>
    <p class="text-sm text-gray-500 mb-5">
        Ce que les autres établissements ont en rayon, en ce moment. Chercher
        du sang à trois heures du matin ne devrait pas consister à appeler les
        hôpitaux un par un.
    </p>

    @include('banque-sang._onglets')
    @include('partials._flash')

    {{-- Ce qu'on cherche --}}
    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-groupe" class="block text-xs font-semibold text-gray-600 mb-1">
                Groupe du receveur
            </label>
            <select id="f-groupe" name="groupe" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">— tout le stock —</option>
                @foreach($groupes as $g)
                <option value="{{ $g }}" @selected($groupe === $g)>{{ $g }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-produit" class="block text-xs font-semibold text-gray-600 mb-1">Produit</label>
            <select id="f-produit" name="produit" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                @foreach($produits as $cle => $libelle)
                <option value="{{ $cle }}" @selected($produit === $cle)>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Chercher</button>

        @if($groupe)
        <p class="text-xs text-blue-900 bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 ml-auto">
            Un receveur <strong>{{ $groupe }}</strong> accepte <strong>{{ implode(', ', $groupesAcceptes) }}</strong>.
        </p>
        @endif
    </form>

    {{-- Ce que la maison annonce, ou n'annonce pas --}}
    <div class="bg-white rounded-xl shadow px-5 py-4 mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm">
            <p class="font-semibold text-gray-800">
                {{ $nousPartageons ? 'Votre stock est visible du réseau' : 'Votre stock est retiré du réseau' }}
            </p>
            <p class="text-xs text-gray-500 mt-0.5">
                {{ $nousPartageons
                    ? 'Les autres établissements voient combien de poches vous avez par groupe — jamais l\'identité de vos donneurs ni celle de vos patients.'
                    : 'Vous continuez de voir le stock des autres, mais ils ne voient plus le vôtre.' }}
            </p>
        </div>
        @if($peutRegler)
        <form method="POST" action="{{ route('banque-sang.partage') }}">
            @csrf
            <input type="hidden" name="partage" value="{{ $nousPartageons ? 0 : 1 }}">
            <button class="{{ $nousPartageons ? 'bg-gray-700 hover:bg-gray-800' : 'bg-green-700 hover:bg-green-800' }} text-white rounded-lg px-4 py-2 text-sm font-semibold">
                {{ $nousPartageons ? 'Se retirer du réseau' : 'Rejoindre le réseau' }}
            </button>
        </form>
        @else
        <p class="text-xs text-gray-400">Le partage se règle depuis la direction.</p>
        @endif
    </div>

    {{--
        L'échange avec les hôpitaux distants.

        Il se fait tout seul au quart d'heure ; le bouton est là pour
        l'urgence qui n'attend pas le passage suivant.
    --}}
    <div class="bg-white rounded-xl shadow px-5 py-4 mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm">
            @if($reseauConfigure)
            <p class="font-semibold text-gray-800">Échange avec les hôpitaux distants</p>
            <p class="text-xs text-gray-500 mt-0.5">
                Les stocks s'échangent automatiquement toutes les quinze minutes.
                Chaque ligne ci-dessous porte son heure : un stock annoncé il y a
                six heures n'est pas une promesse.
            </p>
            @else
            <p class="font-semibold text-amber-800">Réseau distant non configuré</p>
            <p class="text-xs text-gray-500 mt-0.5">
                Seuls les établissements de cette base apparaissent. Pour joindre
                d'autres hôpitaux, renseignez <code class="font-mono">CENTRAL_API_URL</code>
                et le jeton de l'établissement.
            </p>
            @endif
        </div>
        @if($reseauConfigure)
        <form method="POST" action="{{ route('banque-sang.reseau.rafraichir') }}">
            @csrf
            <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-4 py-2 text-sm font-semibold min-h-[44px]"
                    title="Publier notre stock et rapporter celui des autres, maintenant">
                🔄 Rafraîchir maintenant
            </button>
        </form>
        @endif
    </div>

    {{-- Les maisons du réseau --}}
    <div class="space-y-4">
        @forelse($maisons as $maison)
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-5 py-3 border-b flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="font-semibold text-gray-800">
                        {{ $maison['nom'] }}
                        @if($maison['distant'] ?? false)
                        {{-- Ce qui vient d'un autre serveur est une annonce,
                             pas une lecture : on le dit. --}}
                        <span class="ml-1 px-2 py-0.5 rounded-full text-xs font-semibold align-middle
                            {{ $maison['frais'] ? 'bg-blue-100 text-blue-900' : 'bg-amber-100 text-amber-900' }}"
                            title="Stock annoncé par cet hôpital, pas lu en direct">
                            annoncé {{ $maison['age'] }}
                        </span>
                        @endif
                    </p>
                    <p class="text-xs text-gray-500">
                        {{ $maison['ville'] ?: 'Ville non précisée' }}
                        @if($maison['telephone']) · <span class="font-mono">{{ $maison['telephone'] }}</span> @endif
                    </p>
                    @if(($maison['distant'] ?? false) && ! $maison['frais'])
                    <p class="text-xs text-amber-800 mt-1">
                        Annonce ancienne — appelez avant d'envoyer une ambulance.
                    </p>
                    @endif
                </div>
                <div class="text-right">
                    <p class="text-xl font-bold {{ $maison['compatibles'] > 0 ? 'text-green-700' : 'text-gray-400' }}">
                        {{ $maison['compatibles'] }}
                    </p>
                    <p class="text-xs text-gray-500">
                        {{ $groupe ? 'poche(s) compatible(s)' : 'poche(s) délivrable(s)' }}
                        @if($groupe && $maison['total'] !== $maison['compatibles'])
                            · {{ $maison['total'] }} au total
                        @endif
                    </p>
                </div>
            </div>

            <div class="px-5 py-4">
                @if($maison['par_groupe']->isNotEmpty())
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach($maison['par_groupe'] as $g => $nombre)
                    <span class="px-3 py-1 rounded-full text-xs font-semibold
                        {{ in_array($g, $groupesAcceptes, true) ? 'bg-green-100 text-green-900' : 'bg-gray-100 text-gray-600' }}">
                        {{ $g }} · {{ $nombre }}
                    </span>
                    @endforeach
                </div>
                @else
                <p class="text-sm text-gray-400 mb-3">Aucune poche délivrable dans cette maison.</p>
                @endif

                <p class="text-xs text-gray-500">
                    Fichier des donneurs : {{ $maison['donneurs'] }} joignable(s)
                    @if($groupe) · {{ $maison['donneurs_compatibles'] }} compatible(s) {{ $groupe }} @endif
                </p>

                @if(($maison['distant'] ?? false) && $groupe && $maison['donneurs_compatibles'] > 0)
                {{-- Le fichier des donneurs d'un autre hôpital ne sort pas de
                     chez lui : on appelle la maison, elle appelle les siens. --}}
                <p class="text-xs text-gray-500 mt-1">
                    Appelez cet hôpital @if($maison['telephone'])au <span class="font-mono">{{ $maison['telephone'] }}</span>@endif :
                    il fera venir ses donneurs. Leurs coordonnées ne quittent pas leur registre.
                </p>
                @endif

                @if($maison['a_appeler']->isNotEmpty())
                <div class="mt-3 border-t pt-3">
                    <p class="text-xs font-semibold text-gray-600 mb-2">
                        Donneurs à faire appeler par cette maison — les plus reposés d'abord
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($maison['a_appeler'] as $donneur)
                        <span class="text-xs bg-gray-50 border border-gray-200 rounded-lg px-3 py-1">
                            <strong>{{ $donneur->groupe_sanguin }}</strong> · {{ $donneur->nomComplet() }}
                            @if($donneur->telephone) · <span class="font-mono">{{ $donneur->telephone }}</span> @endif
                        </span>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
        @empty
        <div class="bg-white rounded-xl shadow px-5 py-12 text-center">
            <p class="text-gray-500">Aucun autre établissement ne partage son stock pour l'instant.</p>
            <p class="text-xs text-gray-400 mt-2">
                Le réseau se remplit à mesure que l'application est installée ailleurs :
                chaque banque qui s'y joint devient visible ici, et voit la vôtre.
            </p>
        </div>
        @endforelse
    </div>
</div>
@endsection
