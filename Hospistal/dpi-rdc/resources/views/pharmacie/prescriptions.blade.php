@extends('layouts.app')
@section('title', 'Ordonnances')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('pharmacie.dashboard') }}" class="text-blue-700 hover:underline text-sm">← Pharmacie</a>
        <h2 class="text-2xl font-bold text-gray-800">Ordonnances</h2>
    </div>

    @php
        $statuts = [
            'en_attente_paiement' => ['label' => 'En attente paiement guichet', 'badge' => 'bg-amber-100 text-amber-800'],
            'brouillon' => ['label' => 'Brouillon / à facturer', 'badge' => 'bg-gray-100 text-gray-700'],
            'en_attente' => ['label' => 'Payées — à dispenser', 'badge' => 'bg-green-100 text-green-800'],
            'dispensee' => ['label' => 'Dispensées', 'badge' => 'bg-blue-100 text-blue-800'],
        ];
        $prescriptions = \App\Models\Prescription::with(['patient', 'prescripteur', 'lignes', 'factures'])
            ->whereIn('statut', array_keys($statuts))
            ->orderByDesc('date_prescription')
            ->get()
            ->groupBy('statut');
    @endphp

    @foreach($statuts as $statut => $info)
        @php $groupe = $prescriptions[$statut] ?? collect(); @endphp
        @if($groupe->count() > 0)
        <div class="mb-6">
            <h3 class="font-semibold text-gray-600 mb-2 text-sm uppercase tracking-wide">
                {{ $info['label'] }} ({{ $groupe->count() }})
            </h3>
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Patient</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Prescripteur</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                            <th class="text-left px-4 py-3 font-medium text-gray-600">Médicaments</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($groupe as $prescription)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $prescription->patient->nom_complet }}</p>
                                <p class="text-xs text-gray-400">{{ $prescription->patient->dossier_number }}</p>
                                <span class="text-xs {{ $prescription->patient->type_prise_en_charge === 'assurance' ? 'text-purple-600' : 'text-gray-500' }}">
                                    {{ ucfirst($prescription->patient->type_prise_en_charge) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                Dr {{ $prescription->prescripteur->nom }}
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ $prescription->date_prescription->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ $prescription->lignes->count() }} médicament(s)
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                @if(in_array($statut, ['en_attente_paiement', 'brouillon']))
                                    @php
                                        $factureEnAttente = $prescription->factures
                                            ->whereIn('statut', ['emise', 'partiellement_payee'])->first();
                                    @endphp
                                    @if($factureEnAttente)
                                        <a href="{{ route('caisse.show', $factureEnAttente) }}"
                                           class="inline-block bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold px-3 py-1.5 rounded-lg">
                                            Payer au guichet
                                        </a>
                                    @else
                                        <form method="POST" action="{{ route('caisse.facturer', $prescription) }}" class="inline">
                                            @csrf
                                            <button type="submit"
                                                class="bg-blue-700 hover:bg-blue-800 text-white text-xs font-semibold px-3 py-1.5 rounded-lg">
                                                Facturer
                                            </button>
                                        </form>
                                    @endif
                                @elseif($statut === 'en_attente')
                                    <a href="{{ route('pharmacie.prescription', $prescription) }}"
                                       class="inline-block bg-green-600 hover:bg-green-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg">
                                        Dispenser
                                    </a>
                                @else
                                    <a href="{{ route('pharmacie.prescription', $prescription) }}"
                                       class="text-blue-700 hover:underline text-xs">Voir</a>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    @endforeach

    @if($prescriptions->flatten()->isEmpty())
        <p class="text-gray-500 text-center py-12">Aucune ordonnance en cours.</p>
    @endif
</div>
@endsection
