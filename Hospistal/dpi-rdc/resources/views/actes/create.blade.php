@extends('layouts.app')
@php
    $titreActe = match($domaine) {
        'maternite' => 'Demande d\'acte de maternité',
        'examen_specialise' => 'Demande d\'examen spécialisé',
        'dialyse' => 'Demande de séance de dialyse',
        default => 'Demande d\'intervention chirurgicale',
    };
    $retour = match($domaine) {
        'maternite' => ['maternite.actes', 'Actes de maternité'],
        'examen_specialise' => ['examens-specialises.index', 'Examens spécialisés'],
        'dialyse' => ['dialyse.actes', 'Actes de dialyse'],
        default => ['bloc.index', 'Actes chirurgicaux'],
    };
@endphp
@section('title', $titreActe)
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route($retour[0]) }}" class="text-blue-700 hover:underline text-sm">← {{ $retour[1] }}</a>
        <h2 class="text-2xl font-bold text-gray-800">{{ $titreActe }}</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Vous dites quel acte et pour qui. Le plateau technique le programmera :
        salle, créneau, opérateur.
    </p>

    @include('partials._flash')

    <div class="bg-white rounded-xl shadow p-6">
        @include('actes._formulaire-demande')
    </div>
</div>
@endsection
