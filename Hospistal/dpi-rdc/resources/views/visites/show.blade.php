@extends('layouts.app')
@section('title', 'Parcours patient')
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('visites.index') }}" class="text-blue-700 hover:underline text-sm">← Visites</a>
        <h2 class="text-2xl font-bold text-gray-800">Parcours — {{ $visit->patient->nom_complet }}</h2>
    </div>

    @foreach(['success','error','info'] as $type)
        @if(session($type))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border
            {{ $type==='success' ? 'bg-green-50 border-green-200 text-green-800' : ($type==='error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-blue-50 border-blue-200 text-blue-800') }}">
            {{ session($type) }}
        </div>
        @endif
    @endforeach

    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
        <div><span class="text-gray-500">Dossier</span><p class="font-semibold">{{ $visit->patient->dossier_number }}</p></div>
        <div><span class="text-gray-500">Type visite</span><p class="font-semibold">{{ str_replace('_',' ', $visit->type) }}</p></div>
        <div><span class="text-gray-500">Entrée</span><p class="font-semibold">{{ $visit->date_entree->format('d/m/Y H:i') }}</p></div>
        <div><span class="text-gray-500">Statut</span><p class="font-semibold">{{ ucfirst($visit->statut) }}</p></div>
    </div>

    {{-- Actions rapides --}}
    <div class="flex flex-wrap gap-2 mb-6">
        @if($visit->consultations->first())
        <a href="{{ route('consultations.show', $visit->consultations->first()) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">Consultation</a>
        @endif
        <a href="{{ route('labo.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">🔬 Prescrire labo</a>
        <a href="{{ route('imagerie.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">📷 Imagerie</a>
        @if($visit->consultations->first())
        <a href="{{ route('prescriptions.create', $visit->consultations->first()) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">💊 Prescrire</a>
        @endif
        <a href="{{ route('bloc.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">🏥 Bloc</a>
        <a href="{{ route('maternite.create', ['visit_id' => $visit->id]) }}" class="bg-white border px-4 py-2 rounded-lg text-sm hover:bg-gray-50">👶 Maternité</a>
    </div>

    {{-- Hospitalisation --}}
    @if($visit->statut === 'en_cours')
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h3 class="font-semibold text-gray-700 mb-4">Hospitalisation</h3>
        @if($visit->type !== 'hospitalisation')
        <form method="POST" action="{{ route('visites.hospitaliser', $visit) }}" class="grid md:grid-cols-3 gap-4">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">Service</label>
                <select name="service_id" required class="w-full border rounded-lg px-3 py-2 text-sm" onchange="this.form.querySelector('[name=lit_id]').innerHTML=this.options[this.selectedIndex].dataset.lits||''">
                    <option value="">— Choisir —</option>
                    @foreach($services as $service)
                    <option value="{{ $service->id }}" data-lits="@foreach($service->lits as $lit)<option value='{{ $lit->id }}'>Lit {{ $lit->numero }}</option>@endforeach">
                        {{ $service->nom }} ({{ $service->lits->count() }} lits libres)
                    </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Lit</label>
                <select name="lit_id" required class="w-full border rounded-lg px-3 py-2 text-sm"><option value="">— Service d'abord —</option></select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="bg-blue-700 text-white px-4 py-2 rounded-lg text-sm w-full">Admettre en hospitalisation</button>
            </div>
        </form>
        @else
        <p class="text-sm text-gray-600 mb-3">
            Service : <strong>{{ $visit->service?->nom ?? '—' }}</strong> —
            Lit : <strong>{{ $visit->lit?->numero ?? '—' }}</strong>
            ({{ $visit->joursHospitalisation() }} jour(s))
        </p>
        <form method="POST" action="{{ route('visites.facturer-sejour', $visit) }}" class="inline">
            @csrf
            <button type="submit" class="bg-amber-600 text-white px-4 py-2 rounded-lg text-sm mr-2">Facturer séjour</button>
        </form>
        @endif
    </div>
    @endif

    {{-- Factures --}}
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h3 class="font-semibold text-gray-700 mb-3">Factures ({{ $impayees }} impayée(s))</h3>
        <ul class="space-y-2 text-sm">
            @forelse($visit->factures as $facture)
            <li class="flex justify-between items-center border-b pb-2">
                <span>{{ $facture->numero_facture }} — {{ number_format($facture->total_ttc, 0, ',', ' ') }} CDF</span>
                <span class="px-2 py-0.5 rounded text-xs {{ $facture->statut==='payee' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">{{ $facture->statut }}</span>
                <a href="{{ route('caisse.show', $facture) }}" class="text-blue-700 text-xs">Guichet →</a>
            </li>
            @empty
            <li class="text-gray-400">Aucune facture</li>
            @endforelse
        </ul>
    </div>

    {{-- Examens --}}
    @if($visit->examensLaboratoire->count())
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h3 class="font-semibold text-gray-700 mb-3">Examens labo / imagerie</h3>
        @foreach($visit->examensLaboratoire as $examen)
        <div class="flex justify-between text-sm border-b py-2">
            <span>{{ $examen->domaine }} — {{ $examen->statut }} ({{ $examen->resultats->count() }} actes)</span>
            <a href="{{ route('labo.show', $examen) }}" class="text-blue-700">Voir bilan →</a>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Sortie --}}
    @if($visit->statut === 'en_cours' && $visit->type === 'hospitalisation')
    <div class="bg-white rounded-xl shadow p-6 border-2 border-green-200">
        <h3 class="font-semibold text-green-800 mb-3">Sortie patient</h3>
        @if($impayees > 0)
        <p class="text-red-600 text-sm mb-3">⚠️ {{ $impayees }} facture(s) impayée(s) — régler au guichet avant sortie.</p>
        @endif
        <form method="POST" action="{{ route('visites.sortir', $visit) }}" class="flex flex-wrap gap-3 items-end">
            @csrf
            <div>
                <label class="block text-xs text-gray-500 mb-1">Mode de sortie</label>
                <select name="mode_sortie" class="border rounded-lg px-3 py-2 text-sm">
                    @foreach(['gueri','ameliore','transfert','sortie_contre_avis'] as $m)
                    <option value="{{ $m }}">{{ ucfirst(str_replace('_',' ', $m)) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-green-700 text-white px-6 py-2 rounded-lg text-sm" @disabled($impayees > 0)>
                Valider la sortie & libérer le lit
            </button>
        </form>
    </div>
    @endif
</div>
@endsection
