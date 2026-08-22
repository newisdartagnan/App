@extends('layouts.app')
@section('title', 'Bulletin de sortie')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-4 print:hidden">
        <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline text-sm">← Le séjour</a>
        <h2 class="text-2xl font-bold text-gray-800">🖨️ Bulletin de sortie</h2>
        <span class="ml-auto text-xs text-gray-500">
            Remettre au patient ; un exemplaire pour le médecin traitant.
        </span>
    </div>

    @include('partials._flash')

    <div class="bg-white rounded-xl shadow p-8 text-sm">

        @include('partials.bandeau-patient-impression', ['patient' => $visit->patient])

        <h3 class="text-center text-lg font-bold text-gray-800 tracking-wide my-4">BULLETIN DE SORTIE</h3>

        {{-- Le séjour --}}
        <table class="w-full mb-5">
            <tbody>
                <tr>
                    <td class="py-1 pr-3 text-gray-500 align-top w-40">Service</td>
                    <td class="py-1 font-medium">{{ $visit->service?->nom ?? 'Ambulatoire' }}</td>
                    <td class="py-1 pr-3 text-gray-500 align-top w-32">Admission</td>
                    <td class="py-1 font-medium">{{ $visit->date_entree->format('d/m/Y à H:i') }}</td>
                </tr>
                <tr>
                    <td class="py-1 pr-3 text-gray-500 align-top">Type de passage</td>
                    <td class="py-1">{{ ucfirst(str_replace('_', ' ', $visit->type)) }}</td>
                    <td class="py-1 pr-3 text-gray-500 align-top">Sortie</td>
                    <td class="py-1 font-medium">
                        {{ $visit->date_sortie?->format('d/m/Y à H:i') ?? 'séjour en cours' }}
                    </td>
                </tr>
                <tr>
                    <td class="py-1 pr-3 text-gray-500 align-top">Durée</td>
                    <td class="py-1">
                        {{ $visit->duree_sejour_jours ?? $visit->joursHospitalisation() }} jour(s)
                    </td>
                    <td class="py-1 pr-3 text-gray-500 align-top">Issue</td>
                    <td class="py-1 font-semibold {{ $visit->mode_sortie === 'deces' ? 'text-red-700' : '' }}">
                        {{ $visit->libelleModeSortie() }}
                    </td>
                </tr>
            </tbody>
        </table>

        {{-- Motif et diagnostics --}}
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Motif d'admission</p>
            <p>{{ $visit->motif_consultation ?: 'Non précisé' }}</p>
        </div>

        @if($diagnostics->isNotEmpty())
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Diagnostics retenus</p>
            <ul class="list-disc list-inside">
                @foreach($diagnostics as $diag)
                <li>
                    {{ $diag['libelle'] }}
                    @if($diag['code_cim10'] ?? null)
                    <span class="font-mono text-xs text-gray-500">({{ $diag['code_cim10'] }})</span>
                    @endif
                </li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- Examens --}}
        @if($visit->examensLaboratoire->isNotEmpty())
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Examens réalisés</p>
            @foreach($visit->examensLaboratoire as $examen)
            <p>
                <span class="font-mono text-xs">{{ $examen->numero_bon }}</span>
                — {{ $examen->domaine === 'imagerie' ? 'Imagerie' : 'Laboratoire' }},
                {{ $examen->date_resultat?->format('d/m/Y') ?? 'en cours' }}
                @if($examen->conclusion)
                <br><span class="text-gray-700">{{ $examen->conclusion }}</span>
                @endif
            </p>
            @endforeach
        </div>
        @endif

        {{-- Actes --}}
        @if($visit->actesCliniques->isNotEmpty())
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Actes pratiqués</p>
            @foreach($visit->actesCliniques as $acte)
            <p>
                {{ $acte->libelle }}
                @if($acte->date_realisation) — {{ $acte->date_realisation->format('d/m/Y') }} @endif
                @if($acte->operateur) · Dr {{ $acte->operateur->nom_complet }} @endif
                @if($acte->statut !== 'realise' && $acte->statut !== 'facture')
                <span class="text-amber-700">(non réalisé)</span>
                @endif
            </p>
            @endforeach
        </div>
        @endif

        @if($visit->transfusions->isNotEmpty())
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Transfusions</p>
            @foreach($visit->transfusions as $transfusion)
            <p>
                {{ $transfusion->libelleProduit() }} — poche {{ $transfusion->numero_poche }}
                ({{ $transfusion->groupe_donneur }} → {{ $transfusion->groupe_receveur }}),
                {{ $transfusion->jour?->format('d/m/Y') }}
                @if($transfusion->avecIncident())
                <span class="text-red-700">— {{ $transfusion->libelleIncident() }}</span>
                @endif
            </p>
            @endforeach
        </div>
        @endif

        {{-- Traitement --}}
        @if($prescriptions->isNotEmpty())
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Traitement prescrit</p>
            @foreach($prescriptions as $prescription)
                @foreach($prescription->lignes as $ligne)
                <p>
                    • {{ $ligne->medicament?->designation() ?? $ligne->libelle_externe }}
                    @if($ligne->dose || $ligne->frequence)
                    — {{ $ligne->dose }} {{ $ligne->frequence }}
                    @endif
                    @if($ligne->duree_jours) pendant {{ $ligne->duree_jours }} jour(s) @endif
                    @if($ligne->est_externe)
                    <span class="text-xs text-gray-500">(à se procurer à l'extérieur)</span>
                    @endif
                </p>
                @endforeach
            @endforeach
        </div>
        @endif

        {{-- Évolution et consignes : ce qui était saisi et jeté --}}
        @if($visit->observations_sortie)
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Évolution durant le séjour</p>
            <p>{{ $visit->observations_sortie }}</p>
        </div>
        @endif

        @if($visit->recommandations_sortie || $visit->rendez_vous_controle)
        <div class="border-t pt-3 mb-4">
            <p class="font-semibold text-gray-700 mb-1">Recommandations</p>
            @if($visit->recommandations_sortie)<p>{{ $visit->recommandations_sortie }}</p>@endif
            @if($visit->rendez_vous_controle)
            <p class="font-semibold text-blue-800 mt-1">
                Contrôle à prévoir le {{ $visit->rendez_vous_controle->format('d/m/Y') }}.
            </p>
            @endif
        </div>
        @endif

        {{-- Signature --}}
        <div class="border-t pt-4 mt-6 flex justify-between items-end">
            <p class="text-xs text-gray-500">
                Document établi le {{ now()->format('d/m/Y à H:i') }} — {{ $etablissement }}.
            </p>
            <div class="text-center">
                <p class="text-sm font-medium">
                    {{ $visit->sortiePar?->nom_complet
                        ?? $visit->consultations->last()?->user?->nom_complet
                        ?? 'Le médecin' }}
                </p>
                <p class="text-xs text-gray-500 border-t border-gray-400 pt-1 mt-6 px-8">
                    Signature et cachet
                </p>
            </div>
        </div>
    </div>

    <div class="mt-4 print:hidden">
        <a href="{{ route('visites.show', $visit) }}" class="text-sm text-blue-700 hover:underline">
            ← Retour au séjour
        </a>
    </div>
</div>
@endsection
