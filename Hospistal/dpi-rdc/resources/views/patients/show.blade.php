@extends('layouts.app')
@section('title', $patient->nom_complet)
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    {{-- En-tête --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('patients.index') }}" class="text-blue-700 hover:underline text-sm">← Retour</a>
            <h2 class="text-2xl font-bold text-gray-800">{{ $patient->nom_complet }}</h2>
            <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $patient->dossier_number }}</span>
        </div>
    </div>

    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>@endif
    @if(session('info'))<div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg px-4 py-3 mb-4">{{ session('info') }}</div>@endif

    {{-- Workflow : le patient passe d'abord à la caisse, puis voit le médecin --}}
    @php $visiteActive = app(\App\Services\VisiteService::class)->visiteActive($patient); @endphp
    @if($visiteActive)
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 flex items-center justify-between">
        <div class="text-sm">
            <p class="font-semibold text-blue-800">Visite en cours — {{ $visiteActive->type === 'urgence' ? '🚨 Urgence' : ($visiteActive->type === 'hospitalisation' ? '🛏️ Hospitalisation' : 'Ambulatoire') }}</p>
            <p class="text-blue-700 mt-0.5">
                @if($visiteActive->statut === 'en_attente')
                    En attente de paiement de la consultation à la caisse.
                @elseif($visiteActive->consultations()->exists())
                    Consultation réalisée — suivre le parcours (bilans, pharmacie, hospitalisation).
                @else
                    Consultation payée — le patient attend le médecin.
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            @if($visiteActive->statut === 'en_attente')
                @php $factC = $visiteActive->factures()->where('statut', 'emise')->first(); @endphp
                @if($factC)
                <a href="{{ route('caisse.show', $factC) }}" class="bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold px-4 py-2 rounded-lg">💰 Caisse →</a>
                @endif
            @elseif(! $visiteActive->consultations()->exists())
                @can('consultation.create')
                <a href="{{ route('visites.consulter', $visiteActive) }}" class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-4 py-2 rounded-lg">🩺 Consulter →</a>
                @endcan
            @endif
            <a href="{{ route('visites.show', $visiteActive) }}" class="bg-blue-700 hover:bg-blue-800 text-white text-sm font-semibold px-4 py-2 rounded-lg">Parcours →</a>
        </div>
    </div>
    @else
    <form method="POST" action="{{ route('patients.envoyer-caisse', $patient) }}"
          class="bg-white rounded-xl shadow p-4 mb-6 flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label for="type-visite" class="block text-sm font-medium text-gray-700 mb-1">Nouvelle visite</label>
            <select id="type-visite" name="type" class="min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
                <option value="consultation_externe">Consultation ambulatoire</option>
                <option value="urgence">🚨 Urgence</option>
            </select>
        </div>
        <div class="flex-1 min-w-[220px]">
            <label for="motif-visite" class="block text-sm font-medium text-gray-700 mb-1">Motif (optionnel)</label>
            <input id="motif-visite" name="motif" type="text" placeholder="Ex: fièvre, contrôle..."
                class="w-full min-h-[44px] rounded-lg border border-gray-300 px-3 py-2">
        </div>
        <button type="submit"
            class="min-h-[44px] bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            💰 Envoyer à la caisse
        </button>
        <p class="w-full text-xs text-gray-400 -mt-1">Le patient règle la consultation au guichet, puis apparaît dans la file d'attente du médecin.</p>
    </form>
    @endif

    {{-- Données patient --}}
    <div class="bg-white rounded-xl shadow p-6 grid grid-cols-2 gap-4 text-sm mb-6">
        <div><span class="font-medium text-gray-600">Sexe :</span> {{ $patient->sexe }}</div>
        <div><span class="font-medium text-gray-600">Date de naissance :</span> {{ $patient->date_naissance?->format('d/m/Y') ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Lieu de naissance :</span> {{ $patient->lieu_naissance ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Téléphone :</span> {{ $patient->telephone ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Province :</span> {{ $patient->province ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Territoire :</span> {{ $patient->territoire ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Adresse :</span> {{ $patient->adresse ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Profession :</span> {{ $patient->profession ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Situation matrimoniale :</span> {{ $patient->situation_matrimoniale }}</div>
        <div><span class="font-medium text-gray-600">Niveau d'instruction :</span> {{ $patient->niveau_instruction }}</div>
        <div><span class="font-medium text-gray-600">Prise en charge :</span> {{ $patient->type_prise_en_charge }}</div>
        @if($patient->assurance_nom)
        <div><span class="font-medium text-gray-600">Assurance :</span> {{ $patient->assurance_nom }} — {{ $patient->assurance_numero }}</div>
        @endif
        @if($patient->contact_urgence_nom)
        <div class="col-span-2"><span class="font-medium text-gray-600">Contact urgence :</span>
            {{ $patient->contact_urgence_nom }} ({{ $patient->contact_urgence_lien }}) — {{ $patient->contact_urgence_telephone }}</div>
        @endif
    </div>

    {{-- Historique des consultations --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-6 py-4 border-b">
            <h3 class="font-semibold text-gray-700">Historique des consultations</h3>
        </div>
        @forelse($patient->visits()->with(['consultations', 'user'])->orderByDesc('date_entree')->get() as $visit)
        <div class="px-6 py-4 border-b hover:bg-gray-50 transition">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-sm">{{ $visit->date_entree->format('d/m/Y à H:i') }}</p>
                    <p class="text-sm text-gray-600">{{ $visit->motif_consultation }}</p>
                    <p class="text-xs text-gray-400 mt-1">
                        {{ $visit->type === 'urgence' ? '🚨 Urgence' : 'Ambulatoire' }}
                        @if($visit->user) — Dr {{ $visit->user->nom }} {{ $visit->user->prenom }} @endif
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-2 py-1 rounded-full text-xs
                        {{ $visit->statut === 'termine' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ ucfirst(str_replace('_', ' ', $visit->statut)) }}
                    </span>
                    @if($visit->consultations->first())
                    <a href="{{ route('consultations.show', $visit->consultations->first()) }}"
                       class="text-blue-700 hover:underline text-xs font-medium">Voir →</a>
                    @endif
                </div>
            </div>
        </div>
        @empty
        <div class="px-6 py-8 text-center text-gray-400 text-sm">Aucune consultation enregistrée</div>
        @endforelse
    </div>

</div>
@endsection