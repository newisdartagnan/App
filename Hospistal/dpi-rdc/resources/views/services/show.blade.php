@extends('layouts.app')
@section('title', $service->nom)
@section('content')
@php
    $occupes = $visites->count();
    $statutsLibres = ['libre' => 'Libre', 'a_nettoyer' => 'À nettoyer', 'a_reparer' => 'À réparer', 'maintenance' => 'Maintenance', 'reserve' => 'Réservé'];
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <a href="{{ route('services.index') }}" class="text-blue-700 hover:underline text-sm">← Services</a>
        <h2 class="text-2xl font-bold text-gray-800">{{ $service->nom }}</h2>
        <span class="text-sm bg-blue-100 text-blue-800 px-3 py-1 rounded-full font-semibold">{{ $occupes }} / {{ $lits->count() }} lits occupés</span>
    </div>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-blue-900 text-white">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold">Lit</th>
                        <th class="px-3 py-2 text-left font-semibold">Statut</th>
                        <th class="px-3 py-2 text-center font-semibold" title="Durée de séjour">DS</th>
                        <th class="px-3 py-2 text-left font-semibold">Nom &amp; post-nom</th>
                        <th class="px-3 py-2 text-center font-semibold">Sexe</th>
                        <th class="px-3 py-2 text-center font-semibold">Âge</th>
                        <th class="px-3 py-2 text-left font-semibold">Prise en charge</th>
                        <th class="px-3 py-2 text-left font-semibold">Entrée</th>
                        <th class="px-3 py-2 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($lits as $lit)
                    @php $visit = $visites->get($lit->id); @endphp
                    <tr class="{{ $visit ? 'bg-blue-50/40' : '' }}">
                        <td class="px-3 py-2 font-semibold text-gray-800">🛏 {{ $lit->numero }}</td>
                        <td class="px-3 py-2">
                            @if($visit)
                            <span class="text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded font-semibold">Occupé</span>
                            @else
                            <span class="text-xs px-2 py-0.5 rounded font-semibold
                                {{ $lit->statut === 'libre' ? 'bg-green-100 text-green-800' : ($lit->statut === 'a_reparer' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800') }}">
                                {{ $statutsLibres[$lit->statut] ?? ucfirst($lit->statut) }}
                            </span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center text-gray-600">{{ $visit ? $visit->joursHospitalisation() . ' j' : '—' }}</td>
                        <td class="px-3 py-2">
                            @if($visit)
                            <a href="{{ route('services.dossier', [$service, $visit]) }}" class="font-semibold text-blue-800 hover:underline">{{ $visit->patient->nom_complet }}</a>
                            <span class="block text-xs text-gray-500">{{ $visit->patient->dossier_number }}</span>
                            @else
                            <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-center">{{ $visit?->patient->sexe ?? '—' }}</td>
                        <td class="px-3 py-2 text-center">{{ $visit?->patient->date_naissance?->age ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if($visit)
                                {{ $visit->patient->type_prise_en_charge === 'assurance' ? '🛡 ' . ($visit->patient->assurance_nom ?: 'Assurance') : 'Privé' }}
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600">{{ $visit?->date_entree->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            @if($visit)
                            <a href="{{ route('services.dossier', [$service, $visit]) }}" class="text-blue-700 text-xs font-semibold hover:underline">Dossier →</a>
                            @else
                            <form method="POST" action="{{ route('lits.statut', $lit) }}" class="flex gap-1 justify-end items-center">
                                @csrf
                                <label for="statut-{{ $lit->id }}" class="sr-only">Statut du lit {{ $lit->numero }}</label>
                                <select id="statut-{{ $lit->id }}" name="statut" class="border border-gray-300 rounded px-1.5 py-1 text-xs">
                                    @foreach($statutsLibres as $val => $lib)
                                    <option value="{{ $val }}" @selected($lit->statut === $val)>{{ $lib }}</option>
                                    @endforeach
                                </select>
                                <button class="text-xs border border-blue-300 text-blue-700 rounded px-2 py-1 hover:bg-blue-50">OK</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @endforeach

                    @foreach($sansLit as $visit)
                    <tr class="bg-amber-50/60">
                        <td class="px-3 py-2 text-amber-800 font-semibold">— sans lit</td>
                        <td class="px-3 py-2"><span class="text-xs bg-amber-100 text-amber-800 px-2 py-0.5 rounded font-semibold">En attente</span></td>
                        <td class="px-3 py-2 text-center text-gray-600">{{ $visit->joursHospitalisation() }} j</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('services.dossier', [$service, $visit]) }}" class="font-semibold text-blue-800 hover:underline">{{ $visit->patient->nom_complet }}</a>
                            <span class="block text-xs text-gray-500">{{ $visit->patient->dossier_number }}</span>
                        </td>
                        <td class="px-3 py-2 text-center">{{ $visit->patient->sexe }}</td>
                        <td class="px-3 py-2 text-center">{{ $visit->patient->date_naissance?->age }}</td>
                        <td class="px-3 py-2 text-xs">{{ $visit->patient->type_prise_en_charge === 'assurance' ? '🛡 Assurance' : 'Privé' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-600">{{ $visit->date_entree->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 text-right"><a href="{{ route('services.dossier', [$service, $visit]) }}" class="text-blue-700 text-xs font-semibold hover:underline">Dossier →</a></td>
                    </tr>
                    @endforeach

                    @if($lits->isEmpty() && $sansLit->isEmpty())
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">Aucun lit configuré pour ce service.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
