@extends('layouts.app')
@section('title', $service->exists ? 'Modifier '.$service->nom : 'Nouveau service')
@section('content')
@include('catalogue._onglets')
<h2 class="text-2xl font-bold text-gray-800 mb-4">{{ $service->exists ? 'Modifier le service « '.$service->nom.' »' : 'Nouveau service' }}</h2>
@include('catalogue._erreurs')
<form method="POST" action="{{ $service->exists ? route('catalogue.services.update', $service) : route('catalogue.services.store') }}" class="bg-white rounded-xl shadow p-4 grid md:grid-cols-2 gap-3 max-w-3xl">
    @csrf
    <div><label for="s-nom" class="block text-sm font-semibold mb-1">Nom <span class="text-red-500">*</span></label>
        <input id="s-nom" name="nom" required value="{{ old('nom', $service->nom) }}" placeholder="Ex. : Pédiatrie, Médecine interne" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="s-code" class="block text-sm font-semibold mb-1">Code <span class="text-xs font-normal text-gray-500">(généré si vide)</span></label>
        <input id="s-code" name="code" value="{{ old('code', $service->code) }}" class="w-full min-h-[44px] border rounded-lg px-3 font-mono"></div>
    <div><label for="s-type" class="block text-sm font-semibold mb-1">Type</label>
        <select id="s-type" name="type" class="w-full min-h-[44px] border rounded-lg px-3">
            @foreach(['urgence' => 'Urgences', 'medecine' => 'Médecine', 'chirurgie' => 'Chirurgie', 'maternite' => 'Maternité', 'pediatrie' => 'Pédiatrie', 'labo' => 'Laboratoire', 'pharmacie' => 'Pharmacie', 'autre' => 'Autre'] as $k => $v)
            <option value="{{ $k }}" @selected(old('type', $service->type) === $k)>{{ $v }}</option>@endforeach
        </select></div>
    <div><label for="s-cap" class="block text-sm font-semibold mb-1">Capacité en lits</label>
        <input id="s-cap" name="capacite_lits" type="number" min="0" value="{{ old('capacite_lits', $service->capacite_lits) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $service->is_active ?? true))> Actif</label>
    <div class="md:col-span-2"><button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer</button></div>
</form>
@endsection
