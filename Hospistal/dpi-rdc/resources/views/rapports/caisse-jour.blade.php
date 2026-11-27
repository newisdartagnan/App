@extends('layouts.app')
@section('title', 'Caisse du '.\Illuminate\Support\Carbon::parse($jour)->format('d/m/Y'))
@section('content')
<a href="{{ route('rapports.afficher', ['rapport' => 'caisse', 'du' => \Illuminate\Support\Carbon::parse($jour)->startOfMonth()->toDateString(), 'au' => \Illuminate\Support\Carbon::parse($jour)->endOfMonth()->toDateString()]) }}" class="text-blue-700 hover:underline text-sm dpi-sans-impression">← Rapport de caisse</a>
<div class="flex flex-wrap items-center justify-between gap-2 mb-3">
    <h2 class="text-2xl font-bold text-blue-900">Factures du {{ \Illuminate\Support\Carbon::parse($jour)->format('d/m/Y') }} ({{ $factures->count() }})</h2>
    <button type="button" onclick="window.print()" class="min-h-[40px] px-4 rounded-lg border bg-white dpi-sans-impression">🖨 Imprimer</button>
</div>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">N° facture</th><th class="px-3 py-2">Heure</th><th class="px-3 py-2">Patient</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2 text-right">Part patient</th><th class="px-3 py-2 text-right">Encaissé</th><th class="px-3 py-2 text-right">Reste</th></tr></thead>
        <tbody class="divide-y">
        @forelse($factures as $f)
            <tr>
                <td class="px-3 py-2 font-mono text-xs"><a href="{{ route('caisse.show', $f) }}" class="text-blue-700 hover:underline">{{ $f->numero_facture }}</a></td>
                <td class="px-3 py-2">{{ $f->date_facture?->format('H:i') }}</td>
                <td class="px-3 py-2">{{ $f->patient?->nom_complet }}</td>
                <td class="px-3 py-2">{{ ['brouillon' => 'Brouillon', 'emise' => 'Non payée', 'partiellement_payee' => 'Partiellement payée', 'payee' => 'Payée', 'annulee' => 'Annulée'][$f->statut] ?? $f->statut }}</td>
                <td class="px-3 py-2 text-right">{{ $f->formater((float) $f->patient_part) }}</td>
                <td class="px-3 py-2 text-right">{{ $f->formater($f->montantPaye()) }}</td>
                <td class="px-3 py-2 text-right">{{ $f->formater($f->soldeRestant()) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400">Aucune facture ce jour-là.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
