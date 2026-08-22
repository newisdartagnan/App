@extends('layouts.app')
@section('title', 'Sociétés conventionnées')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route('parametres.index') }}" class="text-blue-700 hover:underline text-sm">← Paramétrage</a>
        <h2 class="text-2xl font-bold text-gray-800">🛡️ Sociétés conventionnées</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Contrats, modalités de règlement et règles de couverture. Le taux du contrat
        s'applique par défaut ; une règle par catégorie permet d'exclure un acte ou de
        lui donner un taux négocié.
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

    <details class="bg-white rounded-xl shadow mb-5" {{ $errors->any() ? 'open' : '' }}>
        <summary class="px-5 py-3 font-semibold text-gray-700 cursor-pointer select-none">
            ➕ Nouvelle convention
        </summary>
        <div class="px-5 pb-5 border-t pt-4">
            @include('assurances.partials.formulaire', ['assurance' => null, 'action' => route('assurances.store')])
        </div>
    </details>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Société</th>
                        <th class="px-4 py-3 text-center">Taux</th>
                        <th class="px-4 py-3 text-center">Ticket modérateur</th>
                        <th class="px-4 py-3">Modalités de règlement</th>
                        <th class="px-4 py-3 text-center">Règles</th>
                        <th class="px-4 py-3 text-center">Affiliés</th>
                        <th class="px-4 py-3">État</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($assurances as $assurance)
                    <tr class="{{ $assurance->est_actif ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('assurances.show', $assurance) }}" class="font-medium text-blue-700 hover:underline">
                                {{ $assurance->nom }}
                            </a>
                            <p class="text-xs text-gray-400 font-mono">{{ $assurance->code }}</p>
                        </td>
                        <td class="px-4 py-3 text-center font-semibold">{{ $assurance->taux_couverture + 0 }} %</td>
                        <td class="px-4 py-3 text-center text-xs">
                            {{ $assurance->ticket_moderateur > 0 ? ($assurance->ticket_moderateur + 0).' %' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ $assurance->modalites() }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $assurance->couvertures_count > 0 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-500' }}">
                                {{ $assurance->couvertures_count ?: 'aucune' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-xs">{{ $assurance->patient_assurances_count }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $assurance->est_actif ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">
                                {{ $assurance->est_actif ? 'Active' : 'Suspendue' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('assurances.show', $assurance) }}" class="text-blue-700 hover:underline text-xs">Détail →</a>
                            <form method="POST" action="{{ route('assurances.basculer', $assurance) }}" class="inline ml-2">
                                @csrf
                                <button class="text-xs {{ $assurance->est_actif ? 'text-red-700' : 'text-green-700' }} hover:underline">
                                    {{ $assurance->est_actif ? 'Suspendre' : 'Réactiver' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">Aucune convention enregistrée</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
