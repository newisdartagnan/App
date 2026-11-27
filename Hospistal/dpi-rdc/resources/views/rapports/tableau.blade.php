@extends('layouts.app')
@section('title', $donnees['titre'])
@section('content')
@include('rapports._filtre')
<div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
    <h2 class="text-2xl font-bold text-blue-900">{{ $donnees['titre'] }}</h2>
    <p class="text-sm text-gray-600">du {{ \Illuminate\Support\Carbon::parse($du)->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($au)->format('d/m/Y') }}</p>
</div>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-700"><tr>
            @foreach($donnees['colonnes'] as $col)<th class="px-3 py-2 whitespace-nowrap">{{ $col }}</th>@endforeach
            @if($rapport === 'caisse')<th class="px-3 py-2 dpi-sans-impression"></th>@endif
        </tr></thead>
        <tbody class="divide-y">
        @forelse($donnees['lignes'] as $i => $ligne)
            <tr class="hover:bg-gray-50">
                @foreach(array_values((array) $ligne) as $cellule)<td class="px-3 py-2">{{ $cellule }}</td>@endforeach
                @if($rapport === 'caisse')
                <td class="px-3 py-2 text-right dpi-sans-impression"><a href="{{ route('rapports.caisse.jour', $donnees['jours'][$i]) }}" class="inline-flex items-center min-h-[36px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-semibold">Détails →</a></td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ count($donnees['colonnes']) + 1 }}" class="px-3 py-8 text-center text-gray-400">Aucune donnée sur cette période.</td></tr>
        @endforelse
        </tbody>
        @if(! empty($donnees['totaux']) && count($donnees['lignes']) > 0)
        <tfoot class="bg-gray-50 font-bold"><tr>@foreach($donnees['totaux'] as $t)<td class="px-3 py-2">{{ $t }}</td>@endforeach @if($rapport === 'caisse')<td class="dpi-sans-impression"></td>@endif</tr></tfoot>
        @endif
    </table>
</div>
@endsection
