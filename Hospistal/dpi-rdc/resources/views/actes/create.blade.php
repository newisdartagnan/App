@extends('layouts.app')
@section('title', 'Nouvel acte')
@section('content')
<div class="max-w-2xl mx-auto px-4 py-6">
    <h2 class="text-2xl font-bold mb-2">{{ $domaine === 'maternite' ? 'Acte maternité' : 'Acte chirurgical' }}</h2>
    <p class="text-sm text-gray-600 mb-6">Patient : <strong>{{ $visit->patient->nom_complet }}</strong></p>

    <form method="POST" action="{{ $domaine === 'maternite' ? route('maternite.store') : route('bloc.store') }}" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf
        <input type="hidden" name="visit_id" value="{{ $visit->id }}">
        <input type="hidden" name="domaine" value="{{ $domaine }}">

        <div>
            <label class="block text-sm font-medium mb-2">Type d'acte</label>
            @foreach($catalogue as $i => $item)
            <label class="flex items-center gap-2 text-sm mb-2">
                <input type="radio" name="libelle" value="{{ $item['libelle'] }}" data-prix="{{ $item['prix'] }}" @checked($i===0) required>
                {{ $item['libelle'] }} — {{ number_format($item['prix'], 0, ',', ' ') }} CDF
            </label>
            @endforeach
        </div>

        <input type="hidden" name="prix" id="prix" value="{{ $catalogue[0]['prix'] ?? 0 }}">

        <div>
            <label class="block text-sm text-gray-600 mb-1">Compte-rendu (optionnel)</label>
            <textarea name="compte_rendu" rows="3" class="w-full border rounded-lg px-3 py-2 text-sm"></textarea>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="facturer" value="1" checked class="rounded"> Émettre facture au guichet
        </label>

        <button type="submit" class="bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">Enregistrer</button>
    </form>
</div>
<script>
document.querySelectorAll('[name=libelle]').forEach(r => r.addEventListener('change', e => {
    document.getElementById('prix').value = e.target.dataset.prix;
}));
</script>
@endsection
