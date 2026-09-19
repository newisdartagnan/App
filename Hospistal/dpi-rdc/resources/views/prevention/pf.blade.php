@extends('layouts.app')
@section('title', 'Planification familiale')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-1">
        <h2 class="text-2xl font-bold text-gray-800">💠 Planification familiale</h2>
        <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 text-xs font-semibold">
            Canevas {{ $systeme['sigle'] }}
        </span>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Les actes enregistrés depuis le 1er {{ $depuis->translatedFormat('F Y') }}.
        Le canevas compte des actes et non des femmes : la cliente qui revient
        chercher sa plaquette compte chaque fois, en renouvellement.
    </p>

    @if(! $produite)
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 mb-5 text-sm text-amber-900">
        Le canevas {{ $systeme['sigle'] }} ne remonte pas la planification familiale.
        Les actes saisis ici restent enregistrés, mais ils n'apparaîtront pas
        dans le rapport mensuel.
    </div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Actes du mois
            <span class="text-gray-400 font-normal text-sm">— {{ $actes->count() }} enregistré(s)</span>
        </div>

        @if($actes->isEmpty())
        <p class="px-5 py-8 text-sm text-gray-400">
            Aucun acte enregistré ce mois-ci. Les actes se saisissent depuis
            l'écran de prévention du patient, accessible de sa fiche.
        </p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Cliente</th>
                        <th class="px-4 py-2 text-left">Méthode</th>
                        <th class="px-4 py-2 text-left">Acceptation</th>
                        <th class="px-4 py-2 text-left">Canal</th>
                        <th class="px-4 py-2 text-left">Post-partum</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($actes as $acte)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $acte->date_acte->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 font-medium">{{ $acte->patient?->nom_complet }}</td>
                        <td class="px-4 py-2">
                            @if($acte->type_acte === 'conseil_post_partum')
                            <span class="text-gray-600 italic">Conseil en PF du post-partum</span>
                            @else
                            {{ $acte->libelleMethode() }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-600">
                            {{ \App\Models\ActePlanificationFamiliale::ACCEPTATIONS[$acte->type_acceptation] ?? '—' }}
                        </td>
                        <td class="px-4 py-2 text-gray-600">{{ strtoupper($acte->canal) }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $acte->post_partum ? 'Oui' : '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if($acte->patient)
                            <a href="{{ route('prevention.patient', $acte->patient) }}"
                               class="text-blue-700 hover:underline whitespace-nowrap">Dossier →</a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
