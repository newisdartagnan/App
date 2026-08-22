@extends('layouts.app')
@section('title', 'Forfaits')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">📦 Règles de forfait</h2>
    <p class="text-sm text-gray-500 mb-5">
        Un forfait <strong>global</strong> couvre tout le séjour d'un montant unique. Un forfait
        <strong>partiel</strong> ne couvre que les catégories cochées, le reste étant facturé à
        l'acte. Un forfait réservé à une société ne s'applique qu'à ses affiliés ; sa convention
        prend ensuite en charge ce qui reste dû.
    </p>

    @include('parametres._onglets')

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    <div class="bg-white rounded-xl shadow p-5 mb-5">
        <h3 class="font-semibold text-gray-700 mb-4 pb-2 border-b">Nouveau forfait</h3>
        <form method="POST" action="{{ route('forfaits.store') }}" class="grid md:grid-cols-3 gap-3">
            @csrf
            <div>
                <label for="f-code" class="block text-xs font-semibold text-gray-600 mb-1">Code</label>
                <input id="f-code" name="code" required maxlength="30" value="{{ old('code') }}"
                       placeholder="FORF-ACC" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label for="f-libelle" class="block text-xs font-semibold text-gray-600 mb-1">Libellé</label>
                <input id="f-libelle" name="libelle" required maxlength="150" value="{{ old('libelle') }}"
                       placeholder="Forfait accouchement simple" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="f-portee" class="block text-xs font-semibold text-gray-600 mb-1">Portée</label>
                <select id="f-portee" name="portee" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\Forfait::PORTEES as $c => $l)
                    <option value="{{ $c }}" @selected(old('portee') === $c)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-montant" class="block text-xs font-semibold text-gray-600 mb-1">Montant</label>
                <input id="f-montant" name="montant" type="number" step="1" min="0" required value="{{ old('montant') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="f-devise" class="block text-xs font-semibold text-gray-600 mb-1">Devise</label>
                <select id="f-devise" name="devise" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="CDF">Francs congolais (CDF)</option>
                    <option value="USD">Dollars (USD)</option>
                </select>
            </div>
            <div>
                <label for="f-jours" class="block text-xs font-semibold text-gray-600 mb-1">Journées incluses</label>
                <input id="f-jours" name="jours_inclus" type="number" min="1" max="365" value="{{ old('jours_inclus') }}"
                       placeholder="illimité" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label for="f-assurance" class="block text-xs font-semibold text-gray-600 mb-1">Réservé à une société</label>
                <select id="f-assurance" name="assurance_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">Ouvert à tous les patients</option>
                    @foreach($assurances as $assurance)
                    <option value="{{ $assurance->id }}" @selected(old('assurance_id') === $assurance->id)>{{ $assurance->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-3">
                <p class="text-xs font-semibold text-gray-600 mb-2">Catégories couvertes (forfait partiel)</p>
                <div class="grid sm:grid-cols-3 gap-1">
                    @foreach(\App\Models\Forfait::CATEGORIES as $cle => $libelle)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="categories_couvertes[]" value="{{ $cle }}"
                               @checked(in_array($cle, old('categories_couvertes', []), true))>
                        {{ $libelle }}
                    </label>
                    @endforeach
                </div>
            </div>
            <div class="md:col-span-3">
                <label for="f-desc" class="block text-xs font-semibold text-gray-600 mb-1">Description</label>
                <input id="f-desc" name="description" maxlength="1000" value="{{ old('description') }}"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-3">
                <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                    Enregistrer le forfait
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Forfaits de l'établissement</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Libellé</th>
                        <th class="px-4 py-3">Portée</th>
                        <th class="px-4 py-3 text-right">Montant</th>
                        <th class="px-4 py-3 text-center">Journées</th>
                        <th class="px-4 py-3">Couvre</th>
                        <th class="px-4 py-3">Société</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($forfaits as $forfait)
                    <tr class="{{ $forfait->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3 font-mono text-xs">{{ $forfait->code }}</td>
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ $forfait->libelle }}</span>
                            @if($forfait->description)
                            <p class="text-xs text-gray-500">{{ $forfait->description }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $forfait->estGlobal() ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }}">
                                {{ $forfait->estGlobal() ? 'Global' : 'Partiel' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold">{{ number_format((float) $forfait->montant, 0, ',', ' ') }} {{ $forfait->devise }}</td>
                        <td class="px-4 py-3 text-center text-xs">{{ $forfait->jours_inclus ? $forfait->jours_inclus.' j' : 'illimité' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ implode(', ', $forfait->libellesCouverts()) }}</td>
                        <td class="px-4 py-3 text-xs">{{ $forfait->assurance?->nom ?? 'Tous' }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ route('forfaits.basculer', $forfait) }}">
                                @csrf
                                <button class="text-xs {{ $forfait->is_active ? 'text-red-700' : 'text-green-700' }} hover:underline">
                                    {{ $forfait->is_active ? 'Désactiver' : 'Réactiver' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Aucun forfait défini</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
