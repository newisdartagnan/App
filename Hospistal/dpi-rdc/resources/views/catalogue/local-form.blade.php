@extends('layouts.app')
@section('title', $local->exists ? 'Modifier '.$local->nom : 'Nouveau local')
@section('content')
@include('catalogue._onglets')
<h2 class="text-2xl font-bold text-gray-800 mb-4">{{ $local->exists ? 'Modifier le local « '.$local->nom.' »' : 'Nouveau local de service' }}</h2>
@include('catalogue._erreurs')
<form method="POST" action="{{ $local->exists ? route('catalogue.locaux.update', $local) : route('catalogue.locaux.store') }}" class="bg-white rounded-xl shadow p-4 grid md:grid-cols-2 gap-3 max-w-3xl">
    @csrf
    <div><label for="lo-srv" class="block text-sm font-semibold mb-1">Service <span class="text-red-500">*</span></label>
        <select id="lo-srv" name="service_id" required class="w-full min-h-[44px] border rounded-lg px-3">
            <option value="">— Choisir —</option>
            @foreach($services as $s)<option value="{{ $s->id }}" @selected(old('service_id', $local->service_id) === $s->id)>{{ $s->nom }}</option>@endforeach
        </select></div>
    <div><label for="lo-nom" class="block text-sm font-semibold mb-1">Nom du local <span class="text-red-500">*</span></label>
        <input id="lo-nom" name="nom" required value="{{ old('nom', $local->nom) }}" placeholder="Ex. : Salle A, Chambre 3" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="lo-type" class="block text-sm font-semibold mb-1">Type</label>
        <select id="lo-type" name="type" class="w-full min-h-[44px] border rounded-lg px-3">
            @foreach(\App\Models\Local::TYPES as $k => $v)<option value="{{ $k }}" @selected(old('type', $local->type) === $k)>{{ $v }}</option>@endforeach
        </select></div>
    <div><label for="lo-etage" class="block text-sm font-semibold mb-1">Étage / bâtiment</label>
        <input id="lo-etage" name="etage" value="{{ old('etage', $local->etage) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $local->is_active ?? true))> Actif</label>
    <div class="md:col-span-2"><button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer</button></div>
</form>
@endsection
