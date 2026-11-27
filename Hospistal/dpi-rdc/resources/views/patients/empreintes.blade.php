@extends('layouts.app')
@section('title', 'Empreintes — '.$patient->nom_complet)
@section('content')
<div class="max-w-5xl mx-auto space-y-4">
    <div>
        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-sm">← Dossier de {{ $patient->nom_complet }}</a>
        <h2 class="text-2xl font-bold text-gray-800">Empreintes digitales</h2>
        <p class="text-sm text-gray-500">Branchez le lecteur d'empreintes sur ce poste. Joignez l'image qu'il produit et, s'il en exporte un, collez son gabarit (template) : il servira à reconnaître le patient.</p>
    </div>
    @include('catalogue._erreurs')
    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3">
        @foreach(\App\Models\EmpreintePatient::DOIGTS as $cle => $libelle)
        @php $e = $empreintes[$cle] ?? null; @endphp
        <div class="bg-white border rounded-xl p-3 text-center {{ $e ? 'border-green-400' : '' }}">
            <p class="text-sm font-semibold mb-2">{{ $libelle }}</p>
            @if($e?->image_path)
                <img src="{{ route('patients.empreintes.image', $e) }}" alt="Empreinte {{ $libelle }}" class="mx-auto h-24 object-contain">
            @elseif($e)
                <p class="text-xs text-green-700">Gabarit enregistré</p>
            @else
                <p class="text-4xl text-gray-300" aria-hidden="true">☝</p>
            @endif
            @if($e)
            <p class="text-xs text-gray-500 mt-1">{{ $e->updated_at->format('d/m/Y') }} — {{ $e->auteur?->nom_complet }}</p>
            <form method="POST" action="{{ route('patients.empreintes.destroy', $e) }}" class="mt-1">@csrf @method('DELETE')
                <button class="text-xs text-red-700 hover:underline">Supprimer</button></form>
            @endif
        </div>
        @endforeach
    </div>
    <form method="POST" action="{{ route('patients.empreintes.store', $patient) }}" enctype="multipart/form-data" class="bg-white rounded-xl shadow p-4 grid md:grid-cols-3 gap-3 items-end">
        @csrf
        <div><label for="em-doigt" class="block text-sm font-semibold mb-1">Doigt</label>
            <select id="em-doigt" name="doigt" class="w-full min-h-[44px] border rounded-lg px-3">
                @foreach(\App\Models\EmpreintePatient::DOIGTS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select></div>
        <div><label for="em-image" class="block text-sm font-semibold mb-1">Image du lecteur</label>
            <input id="em-image" type="file" name="image" accept="image/*" class="w-full text-sm border rounded-lg"></div>
        <div class="md:col-span-3"><label for="em-gab" class="block text-sm font-semibold mb-1">Gabarit (facultatif)</label>
            <textarea id="em-gab" name="gabarit" rows="2" class="w-full border rounded-lg px-3 py-2 font-mono text-xs" placeholder="Collez ici le gabarit exporté par le logiciel du lecteur"></textarea></div>
        <div><button class="min-h-[44px] px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer l'empreinte</button></div>
    </form>
</div>
@endsection
