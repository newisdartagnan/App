@extends('layouts.app')
@section('title', 'Modifier '.$patient->nom_complet)
@section('content')
<div class="max-w-4xl mx-auto space-y-4">
    <div>
        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-sm">← Dossier de {{ $patient->nom_complet }}</a>
        <h2 class="text-2xl font-bold text-gray-800">Modifier l'identité</h2>
        <p class="text-sm text-gray-500">Les champs suivis d'un astérisque <span class="text-red-500">*</span> sont obligatoires.</p>
    </div>
    @include('catalogue._erreurs')
    <form method="POST" action="{{ route('patients.update', $patient) }}" class="bg-white rounded-xl shadow p-5 grid md:grid-cols-3 gap-3">
        @csrf @method('PUT')
        @php $champ = fn ($n, $d = null) => old($n, $patient->{$n} ?? $d); @endphp
        <div><label for="p-nom" class="block text-sm font-semibold mb-1">Nom <span class="text-red-500">*</span></label>
            <input id="p-nom" name="nom" required value="{{ $champ('nom') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-postnom" class="block text-sm font-semibold mb-1">Postnom</label>
            <input id="p-postnom" name="postnom" value="{{ $champ('postnom') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-prenom" class="block text-sm font-semibold mb-1">Prénom <span class="text-red-500">*</span></label>
            <input id="p-prenom" name="prenom" required value="{{ $champ('prenom') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-ddn" class="block text-sm font-semibold mb-1">Date de naissance</label>
            <input id="p-ddn" name="date_naissance" type="date" value="{{ old('date_naissance', $patient->date_naissance?->format('Y-m-d')) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-sexe" class="block text-sm font-semibold mb-1">Sexe <span class="text-red-500">*</span></label>
            <select id="p-sexe" name="sexe" class="w-full min-h-[44px] border rounded-lg px-3">
                @foreach(['M' => 'Homme', 'F' => 'Femme', 'Inconnu' => 'Inconnu'] as $k => $v)<option value="{{ $k }}" @selected($champ('sexe') === $k)>{{ $v }}</option>@endforeach
            </select></div>
        <div><label for="p-nat" class="block text-sm font-semibold mb-1">Nationalité</label>
            <input id="p-nat" name="nationalite" value="{{ $champ('nationalite', 'Congolaise') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div class="md:col-span-2"><label for="p-adresse" class="block text-sm font-semibold mb-1">Adresse</label>
            <input id="p-adresse" name="adresse" value="{{ $champ('adresse') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-lieu" class="block text-sm font-semibold mb-1">Lieu de naissance</label>
            <input id="p-lieu" name="lieu_naissance" value="{{ $champ('lieu_naissance') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-prov" class="block text-sm font-semibold mb-1">Province</label>
            <input id="p-prov" name="province" value="{{ $champ('province') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-terr" class="block text-sm font-semibold mb-1">Territoire</label>
            <input id="p-terr" name="territoire" value="{{ $champ('territoire') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-etat" class="block text-sm font-semibold mb-1">État civil</label>
            <select id="p-etat" name="situation_matrimoniale" class="w-full min-h-[44px] border rounded-lg px-3">
                @foreach(['inconnu' => 'Non précisé', 'celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'divorce' => 'Divorcé(e)', 'veuf' => 'Veuf (ve)', 'union_libre' => 'Union libre'] as $k => $v)
                <option value="{{ $k }}" @selected($champ('situation_matrimoniale') === $k)>{{ $v }}</option>@endforeach
            </select></div>
        <div><label for="p-prof" class="block text-sm font-semibold mb-1">Profession</label>
            <input id="p-prof" name="profession" value="{{ $champ('profession') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-tel" class="block text-sm font-semibold mb-1">Téléphone</label>
            <input id="p-tel" name="telephone" value="{{ $champ('telephone') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-cu-nom" class="block text-sm font-semibold mb-1">Personne à contacter</label>
            <input id="p-cu-nom" name="contact_urgence_nom" value="{{ $champ('contact_urgence_nom') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-cu-tel" class="block text-sm font-semibold mb-1">Son téléphone</label>
            <input id="p-cu-tel" name="contact_urgence_telephone" value="{{ $champ('contact_urgence_telephone') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="p-cu-lien" class="block text-sm font-semibold mb-1">Lien</label>
            <input id="p-cu-lien" name="contact_urgence_lien" value="{{ $champ('contact_urgence_lien') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div class="md:col-span-3"><label for="p-autres" class="block text-sm font-semibold mb-1">Autres détails</label>
            <textarea id="p-autres" name="autres_details" rows="2" class="w-full border rounded-lg px-3 py-2">{{ $champ('autres_details') }}</textarea></div>
        <div class="md:col-span-3"><button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer</button></div>
    </form>
</div>
@endsection
