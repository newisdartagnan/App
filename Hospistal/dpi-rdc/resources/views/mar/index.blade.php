@extends('layouts.app')
@section('title', 'Plan de traitement — ' . $visit->patient->nom_complet)
@section('content')
@php $jourCarbon = \Carbon\Carbon::parse($jour); @endphp
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-4 flex-wrap">
        @if($visit->service)
        <a href="{{ route('services.dossier', [$visit->service, $visit]) }}" class="text-blue-700 hover:underline text-sm">← Dossier de séjour</a>
        @else
        <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline text-sm">← Parcours</a>
        @endif
        <h2 class="text-2xl font-bold text-gray-800">💉 Plan d'administration</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
            {{ $visit->patient->nom_complet }} · Lit {{ $visit->lit?->numero ?? '—' }}
        </span>
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

    {{-- Navigation par jour --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('mar.index', ['visit' => $visit->id, 'jour' => $jourCarbon->copy()->subDay()->toDateString()]) }}"
           class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Veille</a>
        <form method="GET" class="flex gap-2 items-center">
            <label for="jour" class="text-sm text-gray-600">Jour</label>
            <input id="jour" type="date" name="jour" value="{{ $jour }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <button class="px-3 py-1.5 bg-blue-700 text-white rounded-lg text-sm">Afficher</button>
        </form>
        <a href="{{ route('mar.index', ['visit' => $visit->id, 'jour' => $jourCarbon->copy()->addDay()->toDateString()]) }}"
           class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Lendemain →</a>

        <span class="font-semibold text-blue-900 ml-2">{{ $jourCarbon->locale('fr')->isoFormat('dddd D MMMM YYYY') }}</span>

        <form method="POST" action="{{ route('mar.copier', $visit) }}" class="ml-auto">
            @csrf
            <input type="hidden" name="jour" value="{{ $jour }}">
            <button class="px-4 py-1.5 bg-green-700 hover:bg-green-800 text-white rounded-lg text-sm font-semibold">
                📋 Copier vers le jour suivant
            </button>
        </form>
    </div>

    {{-- ── Grille traitement × 24 h ─────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">
            Grille d'administration — cochez chaque prise réellement administrée
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="bg-blue-100 text-blue-900">
                        <th class="border border-gray-300 px-2 py-2 text-left sticky left-0 bg-blue-100 min-w-56">Traitement</th>
                        @for($h = 0; $h < 24; $h++)
                        <th class="border border-gray-300 px-1 py-2 text-center w-9">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}</th>
                        @endfor
                        <th class="border border-gray-300 px-2 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($plans as $plan)
                    <tr>
                        <td class="border border-gray-300 px-2 py-1.5 sticky left-0 bg-white">
                            <span class="font-semibold">{{ $plan->libelle }}</span>
                            <span class="block text-gray-400 text-[10px]">
                                {{ count($plan->heures ?? []) }} prise(s) prévue(s) ·
                                {{ $plan->administrations->count() }} administrée(s)
                            </span>
                        </td>
                        @for($h = 0; $h < 24; $h++)
                        @php
                            $prevue = in_array($h, $plan->heures ?? [], true);
                            $faite = $plan->administreeA($h);
                            $retard = $plan->enRetardA($h);
                        @endphp
                        <td class="border border-gray-300 p-0 text-center
                            {{ $faite ? 'bg-green-100' : ($retard ? 'bg-red-100' : ($prevue ? 'bg-amber-50' : '')) }}">
                            @if($prevue || $faite)
                            <form method="POST" action="{{ route('mar.basculer', $plan) }}">
                                @csrf
                                <input type="hidden" name="heure" value="{{ $h }}">
                                <button type="submit" class="w-full h-8 hover:bg-blue-50"
                                    title="{{ $faite
                                        ? 'Administré à ' . $faite->administre_at->format('H:i') . ' par ' . ($faite->soignant?->nom ?? '') . ' — cliquer pour annuler'
                                        : ($retard ? 'Prise en retard — cliquer pour enregistrer' : 'Cliquer pour enregistrer la prise') }}">
                                    {{ $faite ? '✓' : ($retard ? '⚠' : '○') }}
                                </button>
                            </form>
                            @endif
                        </td>
                        @endfor
                        <td class="border border-gray-300 text-center">
                            <form method="POST" action="{{ route('mar.destroy', $plan) }}"
                                  onsubmit="return confirm('Retirer ce traitement du plan du jour ?');">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:text-red-800 px-1" title="Retirer du plan">✕</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="26" class="px-4 py-10 text-center text-gray-400">
                            Aucun traitement au plan pour cette journée — ajoutez-en ci-dessous ou reconduisez la veille.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 border-t bg-gray-50 text-[11px] text-gray-500 flex flex-wrap gap-4">
            <span>○ prise prévue</span>
            <span class="text-green-700">✓ administrée</span>
            <span class="text-red-700">⚠ prévue et non administrée (heure dépassée)</span>
        </div>
    </div>

    {{-- ── Ajouter un traitement au plan ────────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Ajouter un traitement au plan du jour</div>
        <form method="POST" action="{{ route('mar.store', $visit) }}" class="p-4 space-y-3">
            @csrf
            <input type="hidden" name="jour" value="{{ $jour }}">

            @if($lignesDisponibles->isNotEmpty())
            <div>
                <label for="ligne" class="block text-sm text-gray-600 mb-1">Depuis une ordonnance du séjour</label>
                <select id="ligne" name="ligne_prescription_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">— Saisie libre ci-dessous —</option>
                    @foreach($lignesDisponibles as $ligne)
                    <option value="{{ $ligne->id }}">
                        {{ $ligne->medicament->denomination_commune }} {{ $ligne->medicament->dosage }}
                        — {{ $ligne->dose }}, {{ $ligne->frequence }}
                    </option>
                    @endforeach
                </select>
            </div>
            @endif

            <div>
                <label for="libelle" class="block text-sm text-gray-600 mb-1">
                    Libellé du traitement @if($lignesDisponibles->isNotEmpty())<span class="text-gray-400">(laisser vide si choisi ci-dessus)</span>@endif
                </label>
                <input id="libelle" name="libelle" value="{{ old('libelle') }}"
                    placeholder="Ex. AMOXICILLINE 1 g x3/j IVD"
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>

            <div>
                <span class="block text-sm text-gray-600 mb-1">Heures d'administration</span>
                <div class="grid grid-cols-8 md:grid-cols-12 gap-1.5">
                    @for($h = 0; $h < 24; $h++)
                    <label class="flex items-center justify-center gap-1 border border-gray-300 rounded px-1 py-1.5 text-xs cursor-pointer hover:bg-blue-50">
                        <input type="checkbox" name="heures[]" value="{{ $h }}" class="rounded">
                        {{ str_pad($h, 2, '0', STR_PAD_LEFT) }} h
                    </label>
                    @endfor
                </div>
            </div>

            <button class="bg-blue-700 hover:bg-blue-800 text-white text-sm px-5 py-2 rounded-lg font-semibold">
                + Ajouter au plan
            </button>
        </form>
    </div>
</div>
@endsection
