@extends('layouts.app')
@section('title', 'Dossier médical — ' . $patient->nom_complet)
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-4 flex-wrap">
        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-sm">← Fiche patient</a>
        <h2 class="text-2xl font-bold text-gray-800">📋 Dossier médical — {{ $patient->nom_complet }}</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $patient->dossier_number }}</span>
    </div>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    @if($allergies->isNotEmpty())
    <div class="bg-red-50 border-2 border-red-300 rounded-xl px-4 py-3 mb-4">
        <p class="font-bold text-red-800 mb-1">⚠️ Allergies connues</p>
        <p class="text-sm text-red-700">
            @foreach($allergies as $a){{ $a->referentiel->libelle }}@if($a->severite) ({{ $a->severite }})@endif@if(! $loop->last) · @endif @endforeach
        </p>
    </div>
    @endif

    <div class="grid lg:grid-cols-2 gap-4 mb-4">
        {{-- ── Antécédents ──────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">🩺 Antécédents</div>
            <div class="divide-y divide-gray-100 max-h-64 overflow-y-auto">
                @forelse($antecedents as $entree)
                <div class="px-4 py-2 flex items-start gap-2">
                    <div class="flex-1">
                        <p class="text-sm text-gray-800">{{ $entree->referentiel->libelle }}</p>
                        <p class="text-[11px] text-gray-400">
                            {{ $entree->referentiel->categorie }}
                            @if($entree->date_constat) · constaté le {{ $entree->date_constat->format('d/m/Y') }}@endif
                            @if($entree->precision) · {{ $entree->precision }}@endif
                        </p>
                    </div>
                    <form method="POST" action="{{ route('dossier.referentiel.destroy', $entree) }}">
                        @csrf @method('DELETE')
                        <button class="text-gray-400 hover:text-red-600 text-xs" title="Retirer">✕</button>
                    </form>
                </div>
                @empty
                <p class="px-4 py-8 text-center text-sm text-gray-400">Aucun antécédent renseigné</p>
                @endforelse
            </div>
            <form method="POST" action="{{ route('dossier.referentiel.store', $patient) }}" class="px-4 py-3 border-t bg-gray-50 space-y-2">
                @csrf
                <label for="ant" class="block text-xs text-gray-500">Ajouter un antécédent</label>
                <select id="ant" name="referentiel_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">— Choisir dans la liste —</option>
                    @foreach($catalogueAntecedents as $categorie => $items)
                    <optgroup label="{{ $categorie }}">
                        @foreach($items as $item)
                        <option value="{{ $item->id }}">{{ $item->libelle }}</option>
                        @endforeach
                    </optgroup>
                    @endforeach
                </select>
                <div class="flex gap-2">
                    <input name="precision" placeholder="Précision (facultatif)" aria-label="Précision"
                        class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                    <input type="date" name="date_constat" aria-label="Date de constat"
                        class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                </div>
                <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-4 py-1.5 rounded-lg font-semibold">Valider</button>
            </form>
        </div>

        {{-- ── Allergies ────────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">🚫 Allergies</div>
            <div class="divide-y divide-gray-100 max-h-64 overflow-y-auto">
                @forelse($allergies as $entree)
                <div class="px-4 py-2 flex items-start gap-2">
                    <div class="flex-1">
                        <p class="text-sm text-gray-800">
                            {{ $entree->referentiel->libelle }}
                            @if($entree->severite)
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded ml-1
                                {{ $entree->severite === 'severe' ? 'bg-red-600 text-white' : ($entree->severite === 'moderee' ? 'bg-amber-400 text-amber-950' : 'bg-gray-100 text-gray-600') }}">
                                {{ $entree->severite }}
                            </span>
                            @endif
                        </p>
                        <p class="text-[11px] text-gray-400">
                            {{ $entree->referentiel->categorie }}
                            @if($entree->referentiel->molecule) · molécule : {{ $entree->referentiel->molecule }}@endif
                            @if($entree->precision) · {{ $entree->precision }}@endif
                        </p>
                    </div>
                    <form method="POST" action="{{ route('dossier.referentiel.destroy', $entree) }}">
                        @csrf @method('DELETE')
                        <button class="text-gray-400 hover:text-red-600 text-xs" title="Retirer">✕</button>
                    </form>
                </div>
                @empty
                <p class="px-4 py-8 text-center text-sm text-gray-400">Aucune allergie connue</p>
                @endforelse
            </div>
            <form method="POST" action="{{ route('dossier.referentiel.store', $patient) }}" class="px-4 py-3 border-t bg-gray-50 space-y-2">
                @csrf
                <label for="alg" class="block text-xs text-gray-500">Ajouter une allergie</label>
                <select id="alg" name="referentiel_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">— Choisir dans la liste —</option>
                    @foreach($catalogueAllergies as $categorie => $items)
                    <optgroup label="{{ $categorie }}">
                        @foreach($items as $item)
                        <option value="{{ $item->id }}">{{ $item->libelle }}</option>
                        @endforeach
                    </optgroup>
                    @endforeach
                </select>
                <div class="flex gap-2">
                    <label for="sev" class="sr-only">Sévérité</label>
                    <select id="sev" name="severite" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                        <option value="">Sévérité…</option>
                        <option value="legere">Légère</option>
                        <option value="moderee">Modérée</option>
                        <option value="severe">Sévère</option>
                    </select>
                    <input name="precision" placeholder="Réaction observée" aria-label="Réaction observée"
                        class="flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                </div>
                <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-4 py-1.5 rounded-lg font-semibold">Valider</button>
            </form>
        </div>
    </div>

    {{-- ── Documents cliniques (protocoles) ─────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">📄 Documents cliniques</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Catégorie</th>
                    <th class="px-4 py-2 text-left">Titre</th>
                    <th class="px-4 py-2 text-left">Prestataire</th>
                    <th class="px-4 py-2 text-left">Statut</th>
                    <th class="px-4 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($documents as $document)
                <tr>
                    <td class="px-4 py-2 text-xs">{{ $document->created_at->format('d/m/Y') }}</td>
                    <td class="px-4 py-2">{{ $document->libelleType() }}</td>
                    <td class="px-4 py-2">{{ $document->titre }}</td>
                    <td class="px-4 py-2 text-xs">Dr {{ trim(($document->auteur?->prenom ?? '') . ' ' . ($document->auteur?->nom ?? '')) }}</td>
                    <td class="px-4 py-2">
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded
                            {{ $document->statut === 'valide' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ $document->statut }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        @if($document->statut !== 'valide')
                        <form method="POST" action="{{ route('dossier.document.valider', $document) }}" class="inline">
                            @csrf<button class="text-xs text-green-700 hover:underline">Valider</button>
                        </form>
                        @endif
                        <a href="{{ route('dossier.document.imprimer', $document) }}" target="_blank"
                           class="text-xs text-blue-700 hover:underline ml-2">Imprimer</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucun document</td></tr>
                @endforelse
            </tbody>
        </table>

        <form method="POST" action="{{ route('dossier.document.store', $patient) }}" class="px-4 py-4 border-t bg-gray-50 space-y-3">
            @csrf
            <div class="grid md:grid-cols-2 gap-3">
                <div>
                    <label for="doc-type" class="block text-xs text-gray-500 mb-1">Catégorie</label>
                    <select id="doc-type" name="type" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach(\App\Models\DocumentClinique::TYPES as $cle => $libelle)
                        <option value="{{ $cle }}">{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="doc-titre" class="block text-xs text-gray-500 mb-1">Titre</label>
                    <input id="doc-titre" name="titre" required value="{{ old('titre') }}"
                        placeholder="Ex. Certificat d'aptitude physique"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label for="doc-contenu" class="block text-xs text-gray-500 mb-1">Contenu</label>
                <textarea id="doc-contenu" name="contenu" rows="4" required
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">{{ old('contenu') }}</textarea>
            </div>
            <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-5 py-2 rounded-lg font-semibold">
                + Enregistrer le document
            </button>
        </form>
    </div>
</div>
@endsection
