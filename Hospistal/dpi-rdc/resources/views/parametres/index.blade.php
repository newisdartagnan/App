@extends('layouts.app')
@section('title', 'Paramétrage')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">⚙️ Paramétrage de l'établissement</h2>
    <p class="text-sm text-gray-500 mb-5">
        Taux de change appliqués au guichet. Une révision ne touche jamais les opérations
        déjà enregistrées : chaque acompte, encaissement et facture conserve le taux qui
        lui a été appliqué.
    </p>

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

    {{-- Taux en vigueur --}}
    <div class="grid sm:grid-cols-3 gap-3 mb-5">
        @foreach($devises as $code => $definition)
        <div class="bg-white rounded-xl shadow p-4 text-center {{ $code === $pivot ? 'border-2 border-blue-300' : '' }}">
            <p class="text-3xl font-bold text-blue-800">
                {{ $code === $pivot ? '1' : number_format((float) $definition['taux_cdf'], 2, ',', ' ') }}
            </p>
            <p class="text-xs text-gray-500 mt-1">
                {{ $code === $pivot ? 'Monnaie de compte' : '1 '.$code.' en francs congolais' }}
            </p>
            <p class="text-sm font-semibold text-gray-700 mt-2">{{ $definition['libelle'] }}</p>
        </div>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-5">
        {{-- Révision --}}
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="font-semibold text-gray-700 mb-1">Réviser un taux</h3>
            <p class="text-xs text-gray-500 mb-4 pb-3 border-b">
                À saisir dès qu'un taux change sur le marché. Les nouvelles opérations
                s'appliqueront au taux révisé ; les anciennes gardent le leur.
            </p>

            <form method="POST" action="{{ route('parametres.taux') }}" class="grid sm:grid-cols-2 gap-3">
                @csrf
                <div>
                    <label for="p-devise" class="block text-xs font-semibold text-gray-600 mb-1">Devise</label>
                    <select id="p-devise" name="devise" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($devises as $code => $definition)
                        @continue($code === $pivot)
                        <option value="{{ $code }}" @selected(old('devise') === $code)>
                            {{ $definition['libelle'] }} ({{ $code }})
                        </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="p-taux" class="block text-xs font-semibold text-gray-600 mb-1">
                        Nouveau taux, en francs congolais
                    </label>
                    <input id="p-taux" name="taux_cdf" type="number" step="0.0001" min="0.0001" required
                           value="{{ old('taux_cdf') }}" placeholder="2500"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label for="p-motif" class="block text-xs font-semibold text-gray-600 mb-1">Motif de la révision</label>
                    <input id="p-motif" name="motif" maxlength="500" value="{{ old('motif') }}"
                           placeholder="Hausse du dollar au marché parallèle, note de la direction…"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="sm:col-span-2">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Appliquer le nouveau taux
                    </button>
                </div>
            </form>
        </div>

        {{-- Historique --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Historique des révisions</div>
            <div class="max-h-96 overflow-y-auto divide-y divide-gray-100">
                @forelse($historique as $revision)
                <div class="px-4 py-3">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <span class="text-sm">
                            <strong class="text-gray-800">1 {{ $revision->devise }}</strong>
                            = {{ number_format((float) $revision->taux_cdf, 2, ',', ' ') }} CDF
                            @if($revision->taux_precedent)
                            <span class="text-xs text-gray-400">
                                (avant : {{ number_format((float) $revision->taux_precedent, 2, ',', ' ') }})
                            </span>
                            @endif
                        </span>
                        @php $variation = $revision->variation(); @endphp
                        @if($variation !== null)
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                            {{ $revision->sens() === 'hausse' ? 'bg-red-100 text-red-800' : ($revision->sens() === 'baisse' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600') }}">
                            {{ $variation > 0 ? '▲ +' : ($variation < 0 ? '▼ ' : '') }}{{ number_format($variation, 2, ',', ' ') }} %
                        </span>
                        @else
                        <span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800">Taux initial</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        {{ $revision->applique_a->format('d/m/Y à H:i') }}
                        · {{ $revision->auteur?->nom_complet ?? 'Système' }}
                    </p>
                    @if($revision->motif)
                    <p class="text-xs text-gray-600 italic mt-0.5">{{ $revision->motif }}</p>
                    @endif
                </div>
                @empty
                <p class="px-4 py-8 text-center text-gray-400 text-sm">Aucune révision enregistrée.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap gap-3">
        <a href="{{ route('utilisateurs.index') }}" class="bg-white border border-gray-300 hover:border-blue-400 rounded-lg px-4 py-3 text-sm">
            👥 <strong>Comptes du personnel</strong>
            <span class="block text-xs text-gray-500">Créer des utilisateurs, attribuer les profils</span>
        </a>
        <a href="{{ route('assurances.index') }}" class="bg-white border border-gray-300 hover:border-blue-400 rounded-lg px-4 py-3 text-sm">
            🛡️ <strong>Sociétés conventionnées</strong>
            <span class="block text-xs text-gray-500">Contrats, modalités de règlement, règles de couverture</span>
        </a>
        <a href="{{ route('forfaits.index') }}" class="bg-white border border-gray-300 hover:border-blue-400 rounded-lg px-4 py-3 text-sm">
            📦 <strong>Forfaits</strong>
            <span class="block text-xs text-gray-500">Prix tout compris, global ou partiel</span>
        </a>
    </div>
</div>
@endsection
