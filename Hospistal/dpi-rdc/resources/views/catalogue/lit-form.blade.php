@extends('layouts.app')
@section('title', $lit->exists ? 'Modifier le lit '.$lit->numero : 'Nouveau lit')
@section('content')
@include('catalogue._onglets')
<h2 class="text-2xl font-bold text-gray-800 mb-4">{{ $lit->exists ? 'Modifier le lit '.$lit->numero : 'Nouveau lit' }}</h2>
@include('catalogue._erreurs')
<form method="POST" action="{{ $lit->exists ? route('catalogue.lits.update', $lit) : route('catalogue.lits.store') }}" class="bg-white rounded-xl shadow p-4 grid md:grid-cols-2 gap-3 max-w-3xl">
    @csrf
    <div><label for="li-service" class="block text-sm font-semibold mb-1">Service <span class="text-red-500">*</span></label>
        <select id="li-service" name="service_id" required class="w-full min-h-[44px] border rounded-lg px-3">
            <option value="">— Choisir —</option>
            @foreach($services as $s)<option value="{{ $s->id }}" @selected(old('service_id', $lit->service_id) === $s->id)>{{ $s->nom }}</option>@endforeach
        </select></div>
    <div><label for="li-local" class="block text-sm font-semibold mb-1">Local</label>
        <select id="li-local" name="local_id" class="w-full min-h-[44px] border rounded-lg px-3">
            <option value="">— Aucun —</option>
            @foreach($locaux as $lo)<option value="{{ $lo->id }}" @selected(old('local_id', $lit->local_id) === $lo->id)>{{ $lo->service?->nom }} — {{ $lo->nom }}</option>@endforeach
        </select></div>
    <div><label for="li-num" class="block text-sm font-semibold mb-1">Numéro du lit <span class="text-red-500">*</span></label>
        <input id="li-num" name="numero" required maxlength="20" value="{{ old('numero', $lit->numero) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="li-statut" class="block text-sm font-semibold mb-1">Statut</label>
        <select id="li-statut" name="statut" class="w-full min-h-[44px] border rounded-lg px-3" @disabled($lit->statut === 'occupe')>
            @foreach(['libre' => 'Libre', 'maintenance' => 'Maintenance', 'reserve' => 'Réservé'] as $k => $v)<option value="{{ $k }}" @selected(old('statut', $lit->statut) === $k)>{{ $v }}</option>@endforeach
        </select>
        @if($lit->statut === 'occupe')<p class="text-xs text-amber-700 mt-1">Lit occupé : il se libère à la sortie du patient.</p>@endif</div>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $lit->is_active ?? true))> Actif</label>
    <div class="md:col-span-2"><button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer</button></div>
</form>
@endsection
