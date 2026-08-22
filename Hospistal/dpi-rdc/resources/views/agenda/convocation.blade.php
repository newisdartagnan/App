@extends('layouts.app')
@section('title', 'Convocation — rendez-vous')
@section('content')
<div class="max-w-2xl mx-auto px-4 py-6">

    <div class="flex flex-wrap items-center gap-3 mb-4 dpi-sans-impression">
        <a href="{{ route('agenda.index', ['jour' => $rendezVous->debut->toDateString()]) }}"
           class="text-blue-700 hover:underline text-sm">← L'agenda</a>
        <h2 class="text-2xl font-bold text-gray-800">Rendez-vous à remettre</h2>

        {{-- Rien n'invitait à imprimer : il fallait connaître Ctrl+P. --}}
        <button type="button" data-imprimer
                class="ml-auto inline-flex items-center gap-2 bg-blue-700 hover:bg-blue-800 text-white
                       font-semibold rounded-lg px-5 py-2.5 text-sm min-h-[44px]">
            🖨️ Imprimer
        </button>
    </div>
    <p class="text-xs text-gray-500 mb-4 dpi-sans-impression">
        Le papier ci-dessous est à donner au patient avant qu'il ne reparte.
    </p>

    <div class="bg-white rounded-xl shadow p-8">

        @include('partials.bandeau-patient-impression', ['patient' => $rendezVous->patient])

        <h3 class="text-center text-lg font-bold text-gray-800 tracking-wide my-5">
            RENDEZ-VOUS
        </h3>

        {{-- La date, en grand : c'est la seule chose que le patient doit retenir. --}}
        <div class="text-center border-2 border-blue-200 bg-blue-50 rounded-xl py-5 mb-5">
            <p class="text-3xl font-bold text-blue-900">
                {{ $rendezVous->debut->translatedFormat('l j F Y') }}
            </p>
            <p class="text-2xl font-semibold text-blue-800 mt-1">
                à {{ $rendezVous->debut->format('H:i') }}
            </p>
            <p class="text-sm text-blue-700 mt-2">
                Durée prévue : {{ $rendezVous->duree_minutes }} minutes.
                Présentez-vous 15 minutes avant.
            </p>
        </div>

        <table class="w-full text-sm mb-5">
            <tbody>
                <tr>
                    <td class="py-1.5 pr-3 text-gray-500 align-top w-40">Consultation</td>
                    <td class="py-1.5 font-medium">
                        {{ $rendezVous->typeConsultation?->libelle ?? 'Consultation' }}
                        @if($rendezVous->typeConsultation?->specialite)
                        <span class="text-gray-500">— {{ $rendezVous->typeConsultation->specialite }}</span>
                        @endif
                    </td>
                </tr>
                @if($rendezVous->prestataire)
                <tr>
                    <td class="py-1.5 pr-3 text-gray-500 align-top">Avec</td>
                    <td class="py-1.5 font-medium">{{ $rendezVous->prestataire->nom_complet }}</td>
                </tr>
                @endif
                @if($rendezVous->motif)
                <tr>
                    <td class="py-1.5 pr-3 text-gray-500 align-top">Motif</td>
                    <td class="py-1.5">{{ $rendezVous->motif }}</td>
                </tr>
                @endif
                @if($rendezVous->contact)
                <tr>
                    <td class="py-1.5 pr-3 text-gray-500 align-top">Contact laissé</td>
                    <td class="py-1.5 font-mono">{{ $rendezVous->contact }}</td>
                </tr>
                @endif
                <tr>
                    <td class="py-1.5 pr-3 text-gray-500 align-top">État</td>
                    <td class="py-1.5">{{ $rendezVous->libelleStatut() }}</td>
                </tr>
            </tbody>
        </table>

        <div class="border border-gray-200 rounded-lg px-4 py-3 text-sm text-gray-700 mb-5">
            <p class="font-semibold mb-1">À apporter</p>
            <p>
                @php
                    // Un @if collé à un mot n'est pas compilé par Blade, et
                    // l'espace avant la virgule se voyait sur le papier.
                    $assurance = $rendezVous->patient->estAssure()
                        ? ' et votre carte d\'assuré'.($rendezVous->patient->assuranceEnVigueur()?->assurance?->nom
                            ? ' ('.$rendezVous->patient->assuranceEnVigueur()->assurance->nom.')' : '')
                        : '';
                @endphp
                Ce papier, votre carte de dossier{{ $assurance }}, ainsi que vos
                derniers examens s'il y en a.
            </p>
            <p class="mt-1 text-gray-500">
                En cas d'empêchement, prévenez l'accueil pour libérer le créneau.
            </p>
        </div>

        <div class="flex justify-between items-end text-xs text-gray-500">
            <p>
                Établi le {{ now()->format('d/m/Y à H:i') }} — {{ $etablissement }}
                @if($rendezVous->creePar) · {{ $rendezVous->creePar->nom_complet }} @endif
            </p>
            <p class="font-mono">RDV-{{ strtoupper(substr($rendezVous->id, 0, 8)) }}</p>
        </div>
    </div>
</div>
@endsection
