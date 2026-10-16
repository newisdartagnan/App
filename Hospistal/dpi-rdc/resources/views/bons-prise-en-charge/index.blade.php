@extends('layouts.app')
@section('title', 'Bons de prise en charge — '.$patient->nom_complet)
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-sm">← Dossier de {{ $patient->nom_complet }}</a>
    <h2 class="text-2xl font-bold text-gray-800 mt-1">Bons de prise en charge</h2>
    <p class="text-sm text-gray-500 mb-5">
        L'accord écrit de l'employeur ou de l'assureur. Quand la convention l'exige, c'est le bon
        qui ouvre le tiers payant : sans bon valide, le patient paie tout et l'assureur n'est pas facturé.
    </p>

    @if($patient->type_prise_en_charge === 'assurance')
        @if($motif)
        <div class="bg-amber-50 border border-amber-300 rounded-xl px-4 py-3 mb-5 text-sm text-amber-900">
            ⚠️ Aujourd'hui, le tiers payant ne s'applique pas : {{ $motif }}.
        </div>
        @elseif($bonApplique)
        <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 mb-5 text-sm text-green-800">
            ✓ Le bon n° <strong>{{ $bonApplique->numero }}</strong> couvre les factures émises aujourd'hui
            (valable jusqu'au {{ $bonApplique->date_expiration->format('d/m/Y') }}).
        </div>
        @else
        <div class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 mb-5 text-sm text-green-800">
            ✓ Le tiers payant s'applique ; la convention du patient n'exige pas de bon.
        </div>
        @endif
    @endif

    <div class="bg-white rounded-xl shadow p-5 mb-5">
        <h3 class="font-semibold text-gray-700 mb-3">Enregistrer un bon</h3>
        <form method="POST" action="{{ route('bpc.store', $patient) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @csrf
            <div>
                <label for="b-assurance" class="block text-xs font-semibold text-gray-600 mb-1">Convention <span class="text-red-500">*</span></label>
                <select id="b-assurance" name="assurance_id" required class="w-full min-h-[40px] border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    @foreach($assurances as $a)
                    <option value="{{ $a->id }}" @selected(old('assurance_id', $assuranceDuPatient) === $a->id)>
                        {{ $a->nom }}{{ $a->exige_bon_prise_en_charge ? ' — bon exigé' : '' }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="b-numero" class="block text-xs font-semibold text-gray-600 mb-1">N° du bon <span class="text-red-500">*</span></label>
                <input id="b-numero" name="numero" required maxlength="60" value="{{ old('numero') }}" placeholder="Ex. : BPC-2026-0457"
                       class="w-full min-h-[40px] border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="b-objet" class="block text-xs font-semibold text-gray-600 mb-1">Objet</label>
                <input id="b-objet" name="objet" maxlength="255" value="{{ old('objet') }}" placeholder="Hospitalisation, consultations…"
                       class="w-full min-h-[40px] border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="b-emission" class="block text-xs font-semibold text-gray-600 mb-1">Émis le <span class="text-red-500">*</span></label>
                <input id="b-emission" name="date_emission" type="date" required value="{{ old('date_emission', now()->toDateString()) }}"
                       class="w-full min-h-[40px] border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label for="b-expiration" class="block text-xs font-semibold text-gray-600 mb-1">Valable jusqu'au <span class="text-red-500">*</span></label>
                <input id="b-expiration" name="date_expiration" type="date" required value="{{ old('date_expiration') }}"
                       class="w-full min-h-[40px] border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex gap-2">
                <div class="flex-1">
                    <label for="b-montant" class="block text-xs font-semibold text-gray-600 mb-1">Montant accordé</label>
                    <input id="b-montant" name="montant_autorise" type="number" step="0.01" min="0" value="{{ old('montant_autorise') }}"
                           placeholder="sans limite"
                           class="w-full min-h-[40px] border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="b-devise" class="block text-xs font-semibold text-gray-600 mb-1">Devise</label>
                    <select id="b-devise" name="devise" class="min-h-[40px] border border-gray-300 rounded-lg px-2 py-2 text-sm">
                        <option value="CDF" @selected(old('devise', 'CDF') === 'CDF')>CDF</option>
                        <option value="USD" @selected(old('devise') === 'USD')>USD</option>
                    </select>
                </div>
            </div>
            <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                <button class="min-h-[40px] bg-blue-700 hover:bg-blue-800 text-white text-sm font-semibold px-5 py-2 rounded-lg">Enregistrer le bon</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        @if($bons->isEmpty())
        <p class="px-5 py-8 text-sm text-gray-400">Aucun bon enregistré pour ce patient.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-4 py-2 text-left">Bon</th>
                        <th class="px-4 py-2 text-left">Validité</th>
                        <th class="px-4 py-2 text-right">Montant</th>
                        <th class="px-4 py-2 text-left">État</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($bons as $bon)
                    @php $etat = $bon->etat(); @endphp
                    <tr>
                        <td class="px-4 py-2">
                            <p class="font-medium">{{ $bon->numero }}</p>
                            <p class="text-xs text-gray-500">{{ $bon->assurance?->nom }}{{ $bon->objet ? ' · '.$bon->objet : '' }}</p>
                            <p class="text-xs text-gray-400">{{ $bon->factures_count }} facture(s) · saisi par {{ $bon->auteur?->nom_complet ?? '—' }}</p>
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $bon->date_emission->format('d/m/Y') }} → {{ $bon->date_expiration->format('d/m/Y') }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if($bon->montant_autorise !== null)
                            {{ $bon->formater((float) $bon->montant_consomme) }} / {{ $bon->formater((float) $bon->montant_autorise) }}
                            @else
                            <span class="text-gray-400">sans limite</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $etat === 'Valable' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700' }}">{{ $etat }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            @if($bon->statut === 'valide')
                            <details class="inline-block text-left">
                                <summary class="text-xs text-red-700 cursor-pointer">Annuler</summary>
                                <form method="POST" action="{{ route('bpc.annuler', $bon) }}" class="mt-2 flex gap-2">
                                    @csrf
                                    <label for="m-{{ $bon->id }}" class="sr-only">Motif d'annulation</label>
                                    <input id="m-{{ $bon->id }}" name="motif" required minlength="3" placeholder="Motif"
                                           class="border border-gray-300 rounded px-2 py-1 text-xs w-36">
                                    <button class="text-xs bg-red-700 text-white rounded px-2 py-1">OK</button>
                                </form>
                            </details>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
