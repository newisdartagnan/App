@extends('layouts.app')
@section('title', 'Prescrire examens')
@section('content')
<div class="max-w-3xl mx-auto px-4 py-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-2">
        Prescrire — {{ $domaine === 'imagerie' ? 'Imagerie' : 'Laboratoire' }}
    </h2>
    <p class="text-sm text-gray-600 mb-6">Patient : <strong>{{ $visit->patient->nom_complet }}</strong> ({{ $visit->patient->dossier_number }})</p>

    <form method="POST" action="{{ route('labo.store') }}" class="bg-white rounded-xl shadow p-6 space-y-4">
        @csrf
        <input type="hidden" name="visit_id" value="{{ $visit->id }}">
        <input type="hidden" name="domaine" value="{{ $domaine }}">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Examens à prescrire</label>
            <div class="grid gap-2 max-h-96 overflow-y-auto border rounded-lg p-3">
                @foreach($types->groupBy('categorie') as $cat => $group)
                <p class="text-xs font-semibold text-gray-500 uppercase mt-2">{{ $cat }}</p>
                @foreach($group as $type)
                @php $parametres = $type->valeurs_reference['parametres'] ?? []; @endphp
                @if(count($parametres) > 1)
                {{-- Panel à sous-examens : cocher le tout, ou déplier et choisir --}}
                <details class="border border-gray-200 rounded-lg">
                    <summary class="flex items-center gap-2 text-sm px-2 py-1.5 cursor-pointer select-none hover:bg-gray-50">
                        <input type="checkbox" name="types[]" value="{{ $type->id }}" class="rounded"
                               onclick="event.stopPropagation()">
                        <span class="font-medium">{{ $type->libelle }}</span>
                        <span class="text-xs text-gray-400">({{ count($parametres) }} sous-examens ▾)</span>
                        <span class="text-gray-400 ml-auto">{{ number_format($type->prix ?? 0, 0, ',', ' ') }} CDF</span>
                    </summary>
                    <div class="pl-8 pr-2 pb-2 space-y-1 bg-gray-50/60">
                        <p class="text-xs text-gray-400 pt-1">Ne rien cocher = tout le panel · ou choisir les sous-examens :</p>
                        @foreach($parametres as $param)
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" name="parametres[{{ $type->id }}][]" value="{{ $param['nom'] }}" class="rounded">
                            {{ $param['nom'] }} @if(!empty($param['unite']))<span class="text-gray-400">({{ $param['unite'] }})</span>@endif
                        </label>
                        @endforeach
                    </div>
                </details>
                @else
                <label class="flex items-center gap-2 text-sm px-2 py-1">
                    <input type="checkbox" name="types[]" value="{{ $type->id }}" class="rounded">
                    <span>{{ $type->libelle }}</span>
                    <span class="text-gray-400 ml-auto">{{ number_format($type->prix ?? 0, 0, ',', ' ') }} CDF</span>
                </label>
                @endif
                @endforeach
                @endforeach
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="urgence" value="1" class="rounded"> Urgence
        </label>

        <div>
            <label class="block text-sm text-gray-600 mb-1">Observations cliniques</label>
            <textarea name="observations" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm"></textarea>
        </div>

        <p class="text-xs text-amber-700 bg-amber-50 p-3 rounded-lg">Une facture sera émise au guichet avant réalisation des examens.</p>

        <button type="submit" class="bg-blue-700 text-white px-6 py-2 rounded-lg font-semibold">Prescrire & facturer</button>
    </form>
</div>
@endsection
