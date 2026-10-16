@extends('layouts.app')
@section('title', 'Journal — '.$facture->numero_facture)
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <a href="{{ route('caisse.show', $facture) }}" class="text-blue-700 hover:underline text-sm">← Facture {{ $facture->numero_facture }}</a>
    <h2 class="text-2xl font-bold text-gray-800 mt-1">Journal de la facture {{ $facture->numero_facture }}</h2>
    <p class="text-sm text-gray-500 mb-5">
        Chaque écriture qui a touché cette facture, dans l'ordre : son émission, la part de l'assureur,
        les acomptes, les encaissements, les bons, les avoirs. Le journal ne se modifie pas.
    </p>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        @if($entrees->isEmpty())
        <p class="px-5 py-8 text-sm text-gray-400">
            Aucune écriture journalisée : la facture est antérieure à la mise en service du journal.
        </p>
        @else
        <ol class="divide-y divide-gray-100">
            @foreach($entrees as $e)
            <li class="px-5 py-3 text-sm">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p>
                        <span class="font-semibold text-gray-800">{{ $e['quoi'] }}</span>
                        <span class="text-gray-500">— {{ $e['geste'] }}</span>
                    </p>
                    <p class="text-xs text-gray-500 whitespace-nowrap">
                        {{ $e['log']->created_at?->format('d/m/Y à H:i:s') }}
                        · {{ $e['log']->user?->nom_complet ?? 'système' }}
                        @if($e['log']->ip_address) · {{ $e['log']->ip_address }} @endif
                    </p>
                </div>
                @if($e['details'] !== [])
                <p class="text-xs text-gray-600 mt-1">{{ implode(' · ', $e['details']) }}</p>
                @endif
            </li>
            @endforeach
        </ol>
        @endif
    </div>
</div>
@endsection
