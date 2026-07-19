@extends('layouts.app')
@section('title', $examen->domaine === 'imagerie' ? 'Compte-rendu imagerie' : 'Bilan examens')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ $examen->domaine === 'imagerie' ? route('imagerie.index') : route('labo.index') }}" class="text-blue-700 text-sm">← Retour</a>
            <h2 class="text-2xl font-bold">{{ $examen->domaine === 'imagerie' ? 'Imagerie' : 'Bilan' }} — {{ $examen->patient->nom_complet }}</h2>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('patients.bulletin-jour', ['patient' => $examen->patient_id, 'date' => $examen->date_prescription->toDateString()]) }}" target="_blank"
               class="border border-green-700 text-green-700 hover:bg-green-50 text-sm font-medium px-4 py-2 rounded-lg">📄 Bulletin du jour</a>
            <a href="{{ route('labo.bon', $examen) }}" target="_blank"
               class="border border-blue-700 text-blue-700 hover:bg-blue-50 text-sm font-medium px-4 py-2 rounded-lg">🖨️ Bon d'examen</a>
            @if(in_array($examen->statut, ['resultat_disponible', 'valide']))
            <a href="{{ route('labo.bulletin', $examen) }}" target="_blank"
               class="bg-blue-700 hover:bg-blue-800 text-white text-sm font-medium px-4 py-2 rounded-lg">🖨️ {{ $examen->domaine === 'imagerie' ? 'Compte-rendu' : 'Bulletin' }}</a>
            @endif
        </div>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4">{{ session('error') }}</div>
    @endif

    @php $aCredit = $examen->visit?->serviACredit(); @endphp

    <div class="bg-blue-50 rounded-xl p-4 mb-6 text-sm flex flex-wrap gap-x-6 gap-y-1 justify-between">
        <span>N° bon : <strong class="font-mono">{{ $examen->numero_bon ?? '—' }}</strong></span>
        <span>Statut : <strong>{{ str_replace('_', ' ', $examen->statut) }}</strong></span>
        <span>Facture :
            @if($examen->facture)
            <a href="{{ route('caisse.show', $examen->facture) }}" class="text-blue-700 underline">{{ $examen->facture->numero_facture }} ({{ $examen->facture->statut }})</a>
            @else — @endif
        </span>
        @if($aCredit)
        <span class="text-indigo-700 font-medium">🛏️ Hospitalisé — servi à crédit, règlement avant la sortie</span>
        @endif
    </div>

    @php
        $paye = $examen->facture && $examen->facture->statut === 'payee';
        $peutSaisir = ($paye || $aCredit) && $examen->statut !== 'valide';
    @endphp

    @if($peutSaisir)
    <form method="POST" action="{{ route('labo.resultats', $examen) }}" class="bg-white rounded-xl shadow p-6 mb-6">
        @csrf
        <h3 class="font-semibold mb-4">Saisie des résultats</h3>
        @foreach($examen->resultats as $resultat)
        <div class="border-b py-4">
            <p class="font-medium text-sm mb-2">
                {{ $resultat->typeExamen->libelle }}
                @if($resultat->parametre)
                <span class="text-blue-700">— {{ $resultat->parametre }}</span>
                @endif
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="res-brute-{{ $resultat->id }}" class="sr-only">Valeur texte</label>
                    <input id="res-brute-{{ $resultat->id }}" name="resultats[{{ $resultat->id }}][valeur_brute]"
                           value="{{ $resultat->valeur_brute }}"
                           placeholder="Valeur / texte (positif, négatif…)" class="w-full border rounded px-3 py-2 text-sm min-h-[44px]">
                </div>
                <div>
                    <label for="res-num-{{ $resultat->id }}" class="sr-only">Valeur numérique</label>
                    <input id="res-num-{{ $resultat->id }}" name="resultats[{{ $resultat->id }}][valeur_numerique]"
                           value="{{ $resultat->valeur_numerique }}"
                           placeholder="Valeur numérique" type="number" step="any" class="w-full border rounded px-3 py-2 text-sm min-h-[44px]">
                </div>
            </div>
            @if($resultat->valeur_reference_min !== null || $resultat->valeur_reference_max !== null)
            <p class="text-xs text-gray-500 mt-1">Réf. : {{ $resultat->valeur_reference_min + 0 }} — {{ $resultat->valeur_reference_max + 0 }} {{ $resultat->unite }}</p>
            @endif
        </div>
        @endforeach
        <button type="submit" class="mt-4 bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm min-h-[44px]">Enregistrer les résultats</button>
    </form>
    @elseif(! $paye && ! $aCredit)
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-800">
        Paiement guichet requis avant la réalisation.
        @if($examen->facture)
        <a href="{{ route('caisse.show', $examen->facture) }}" class="text-blue-700 underline ml-1">Aller à la caisse →</a>
        @endif
    </div>
    @endif

    {{-- Résultats --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-6 py-4 border-b font-semibold">Résultats</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr>
                <th class="px-4 py-2 text-left">Examen / paramètre</th>
                <th class="px-4 py-2 text-left">Résultat</th>
                <th class="px-4 py-2 text-left">Référence</th>
                <th class="px-4 py-2 text-left">Interprétation</th>
            </tr></thead>
            <tbody>
                @foreach($examen->resultats as $r)
                <tr class="border-t">
                    <td class="px-4 py-3">
                        {{ $r->typeExamen->libelle }}
                        @if($r->parametre)<span class="text-gray-500">— {{ $r->parametre }}</span>@endif
                    </td>
                    <td class="px-4 py-3 font-medium">{{ $r->valeur_brute ?? ($r->valeur_numerique !== null ? $r->valeur_numerique + 0 : '—') }} {{ $r->unite }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        @if($r->valeur_reference_min !== null || $r->valeur_reference_max !== null)
                        {{ $r->valeur_reference_min + 0 }} — {{ $r->valeur_reference_max + 0 }}
                        @else — @endif
                    </td>
                    <td class="px-4 py-3">
                        @if($r->interpretation)
                        <span class="px-2 py-0.5 rounded text-xs
                            @if($r->interpretation === 'critique') bg-red-600 text-white
                            @elseif(in_array($r->interpretation,['bas','eleve','positif'])) bg-red-100 text-red-700
                            @elseif(in_array($r->interpretation,['normal','negatif'])) bg-green-100 text-green-700
                            @else bg-gray-100 @endif">{{ $r->interpretation }}</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($examen->conclusion)
    <div class="bg-green-50 border border-green-200 rounded-xl p-4 mt-4 text-sm">
        <p class="font-semibold text-green-800 mb-1">Conclusion</p>
        <p class="text-gray-700 whitespace-pre-line">{{ $examen->conclusion }}</p>
    </div>
    @endif

    @if($examen->statut === 'resultat_disponible')
    <form method="POST" action="{{ route('labo.valider', $examen) }}" class="mt-4 bg-white rounded-xl shadow p-4 space-y-3">
        @csrf
        @if($examen->domaine === 'imagerie')
        <div>
            <label for="technique" class="block text-sm font-medium text-gray-700 mb-1">Technique utilisée</label>
            <textarea id="technique" name="technique" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ $examen->technique }}</textarea>
        </div>
        @endif
        <div>
            <label for="conclusion" class="block text-sm font-medium text-gray-700 mb-1">
                {{ $examen->domaine === 'imagerie' ? 'Description & conclusion du compte-rendu' : 'Conclusion du biologiste' }}
            </label>
            <textarea id="conclusion" name="conclusion" rows="3"
                class="w-full border rounded-lg px-3 py-2 text-sm">{{ $examen->conclusion }}</textarea>
        </div>
        @if($examen->domaine === 'imagerie')
        <div>
            <label for="recommandations" class="block text-sm font-medium text-gray-700 mb-1">Recommandations</label>
            <textarea id="recommandations" name="recommandations" rows="2" class="w-full border rounded-lg px-3 py-2 text-sm">{{ $examen->recommandations }}</textarea>
        </div>
        @endif
        <button type="submit" class="bg-green-700 hover:bg-green-800 text-white px-6 py-2 rounded-lg text-sm min-h-[44px]">
            ✓ Valider {{ $examen->domaine === 'imagerie' ? 'le compte-rendu' : 'le bilan' }}
        </button>
    </form>
    @endif

    @if($examen->statut === 'valide')
    <form method="POST" action="{{ route('labo.rouvrir', $examen) }}" class="mt-4">
        @csrf
        <button type="submit" class="border border-amber-600 text-amber-700 hover:bg-amber-50 px-5 py-2 rounded-lg text-sm min-h-[44px]">
            ✏️ Modifier le bilan (rouvrir)
        </button>
    </form>
    @endif

    {{-- Fichiers joints : photos, images, vidéos, PDF (imagerie surtout) --}}
    <div class="bg-white rounded-xl shadow p-4 mt-4">
        <h4 class="font-semibold text-gray-700 mb-3 text-sm">📎 Fichiers joints ({{ $examen->fichiers->count() }})</h4>
        @if($examen->fichiers->count() > 0)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            @foreach($examen->fichiers as $fichier)
            <a href="{{ asset('storage/' . $fichier->chemin) }}" target="_blank" class="border rounded-lg p-2 hover:border-blue-400 block">
                @if($fichier->type === 'image')
                <img src="{{ asset('storage/' . $fichier->chemin) }}" alt="{{ $fichier->nom_original }}" class="w-full h-24 object-cover rounded mb-1">
                @else
                <div class="w-full h-24 bg-gray-100 rounded mb-1 flex items-center justify-center text-3xl">
                    {{ $fichier->type === 'video' ? '🎬' : ($fichier->type === 'pdf' ? '📄' : '📁') }}
                </div>
                @endif
                <p class="text-xs truncate">{{ $fichier->nom_original }}</p>
                @if($fichier->description)<p class="text-xs text-gray-400 truncate">{{ $fichier->description }}</p>@endif
            </a>
            @endforeach
        </div>
        @endif
        <form method="POST" action="{{ route('labo.fichiers', $examen) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label for="fichier" class="block text-xs font-medium text-gray-600 mb-1">Ajouter (image, vidéo, PDF, DICOM — 50 Mo max)</label>
                <input id="fichier" name="fichier" type="file" required class="text-sm">
            </div>
            <div class="flex-1 min-w-[160px]">
                <label for="fichier-desc" class="block text-xs font-medium text-gray-600 mb-1">Description</label>
                <input id="fichier-desc" name="description" type="text" class="w-full min-h-[40px] rounded-lg border border-gray-300 px-3 py-1 text-sm">
            </div>
            <button type="submit" class="min-h-[40px] px-4 py-1 bg-blue-700 text-white rounded-lg text-sm">Téléverser</button>
        </form>
    </div>
</div>
@endsection
