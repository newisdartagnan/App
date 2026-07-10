@extends('layouts.app')
@section('title', 'Bilan examens')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ $examen->domaine === 'imagerie' ? route('imagerie.index') : route('labo.index') }}" class="text-blue-700 text-sm">← Retour</a>
        <h2 class="text-2xl font-bold">Bilan — {{ $examen->patient->nom_complet }}</h2>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4">{{ session('error') }}</div>
    @endif

    <div class="bg-blue-50 rounded-xl p-4 mb-6 text-sm flex justify-between">
        <span>Statut : <strong>{{ $examen->statut }}</strong></span>
        <span>Facture :
            @if($examen->facture)
            <a href="{{ route('caisse.show', $examen->facture) }}" class="text-blue-700">{{ $examen->facture->numero_facture }} ({{ $examen->facture->statut }})</a>
            @else — @endif
        </span>
    </div>

    @php $peutSaisir = $examen->facture && $examen->facture->statut === 'payee' && $examen->statut !== 'valide'; @endphp

    @if($peutSaisir)
    <form method="POST" action="{{ route('labo.resultats', $examen) }}" class="bg-white rounded-xl shadow p-6 mb-6">
        @csrf
        <h3 class="font-semibold mb-4">Saisie des résultats</h3>
        @foreach($examen->resultats as $resultat)
        <div class="border-b py-4">
            <p class="font-medium text-sm mb-2">{{ $resultat->typeExamen->libelle }}</p>
            <input type="hidden" name="resultats[{{ $resultat->id }}][id]" value="{{ $resultat->id }}">
            <div class="grid grid-cols-2 gap-3">
                <input name="resultats[{{ $resultat->id }}][valeur_brute]" placeholder="Valeur / texte" class="border rounded px-3 py-2 text-sm">
                <input name="resultats[{{ $resultat->id }}][valeur_numerique]" placeholder="Valeur numérique" type="number" step="any" class="border rounded px-3 py-2 text-sm">
            </div>
            @if($resultat->valeur_reference_min || $resultat->valeur_reference_max)
            <p class="text-xs text-gray-500 mt-1">Réf. : {{ $resultat->valeur_reference_min }} — {{ $resultat->valeur_reference_max }} {{ $resultat->unite }}</p>
            @endif
        </div>
        @endforeach
        <button type="submit" class="mt-4 bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">Enregistrer résultats</button>
    </form>
    @elseif(!$examen->facture || $examen->facture->statut !== 'payee')
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-800">
        Paiement guichet requis avant saisie des résultats.
        @if($examen->facture)
        <a href="{{ route('caisse.show', $examen->facture) }}" class="text-blue-700 underline ml-1">Aller à la caisse →</a>
        @endif
    </div>
    @endif

    {{-- Résultats affichés --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-6 py-4 border-b font-semibold">Résultats</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-2 text-left">Examen</th>
                <th class="px-4 py-2 text-left">Résultat</th>
                <th class="px-4 py-2 text-left">Interprétation</th>
            </tr></thead>
            <tbody>
                @foreach($examen->resultats as $r)
                <tr class="border-t">
                    <td class="px-4 py-3">{{ $r->typeExamen->libelle }}</td>
                    <td class="px-4 py-3">{{ $r->valeur_brute ?? $r->valeur_numerique }} {{ $r->unite }}</td>
                    <td class="px-4 py-3">
                        @if($r->interpretation)
                        <span class="px-2 py-0.5 rounded text-xs
                            @if(in_array($r->interpretation,['bas','eleve','critique'])) bg-red-100 text-red-700
                            @elseif($r->interpretation==='normal') bg-green-100 text-green-700
                            @else bg-gray-100 @endif">{{ $r->interpretation }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($examen->statut === 'resultat_disponible')
    <form method="POST" action="{{ route('labo.valider', $examen) }}" class="mt-4">
        @csrf
        <button type="submit" class="bg-green-700 text-white px-6 py-2 rounded-lg text-sm">Valider le bilan</button>
    </form>
    @endif
</div>
@endsection
