@extends('layouts.app')
@section('title', 'Rapports')
@section('content')
<h2 class="text-2xl font-bold text-gray-800 mb-1">Rapports</h2>
<p class="text-sm text-gray-500 mb-4">Générés à partir des données du système, sur la période choisie (le mois en cours par défaut).</p>
<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
    @foreach($disponibles as $cle => [$libelle])
    <a href="{{ route('rapports.afficher', $cle) }}" class="bg-white border rounded-xl shadow-sm p-5 hover:bg-blue-50">
        <p class="font-semibold text-blue-800">{{ $libelle }}</p>
        <p class="text-xs text-gray-500 mt-1">Voir le rapport →</p>
    </a>
    @endforeach
    @if(Route::has('snis.index') && auth()->user()->hasAnyRole(['super_admin', 'directeur', 'infirmier_chef', 'agent_admin']))
    <a href="{{ route('snis.index') }}" class="bg-white border rounded-xl shadow-sm p-5 hover:bg-blue-50"><p class="font-semibold text-blue-800">Canevas SNIS</p><p class="text-xs text-gray-500 mt-1">Rapport mensuel à la zone de santé →</p></a>
    @endif
</div>
@endsection
