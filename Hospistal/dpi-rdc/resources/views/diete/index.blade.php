@extends('layouts.app')
@section('title', 'Diète et ménage')
@section('content')
@php
    $jourCarbon = \Carbon\Carbon::parse($jour);
    $typesMenage = \App\Models\TacheMenage::TYPES;
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <h2 class="text-2xl font-bold text-gray-800">🍽️ Diète et ménage</h2>
        <a href="{{ route('diete.imprimer', ['jour' => $jour, 'service_id' => $serviceId]) }}"
           class="ml-auto px-4 py-2 bg-gray-700 hover:bg-gray-800 text-white rounded-lg text-sm">
            🖨️ Feuille de service
        </a>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Patients hospitalisés nourris par l'hôpital. La diète servie est facturée
        au patient, jour par jour, sur la facture de son séjour. L'entretien de la
        chambre, lui, est compris dans le prix de la journée d'hospitalisation :
        il se suit ici mais ne se facture pas à part.
    </p>

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

    {{-- Filtres --}}
    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-service" class="block text-xs font-semibold text-gray-600 mb-1">Service</label>
            <select id="f-service" name="service_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Tous les services</option>
                @foreach($services as $s)
                <option value="{{ $s->id }}" @selected($serviceId === $s->id)>{{ $s->nom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-jour" class="block text-xs font-semibold text-gray-600 mb-1">Jour</label>
            <input id="f-jour" type="date" name="jour" value="{{ $jour }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm">Afficher</button>
        <span class="ml-auto text-sm font-semibold text-blue-900">{{ $jourCarbon->locale('fr')->isoFormat('dddd D MMMM YYYY') }}</span>
    </form>

    {{-- Récapitulatif cuisine --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4">
        <p class="text-sm font-semibold text-gray-700 mb-3">Plateaux à préparer</p>
        <div class="flex flex-wrap gap-3">
            <div class="px-4 py-2 rounded-lg bg-blue-50 border border-blue-200">
                <span class="text-2xl font-bold text-blue-800">{{ $sejours->count() }}</span>
                <span class="text-xs text-gray-600 ml-1">patients hospitalisés</span>
            </div>
            @forelse($plateaux as $libelle => $n)
            <div class="px-4 py-2 rounded-lg bg-gray-50 border border-gray-200">
                <span class="text-2xl font-bold text-gray-800">{{ $n }}</span>
                <span class="text-xs text-gray-600 ml-1">{{ $libelle }}</span>
            </div>
            @empty
            <p class="text-sm text-gray-400 self-center">Aucune diète prescrite pour l'instant.</p>
            @endforelse
            @if($sansDiete)
            <div class="px-4 py-2 rounded-lg bg-amber-50 border border-amber-300">
                <span class="text-2xl font-bold text-amber-800">{{ $sansDiete }}</span>
                <span class="text-xs text-amber-800 ml-1">sans diète prescrite</span>
            </div>
            @endif
        </div>
    </div>

    {{-- Liste des séjours --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Séjours en cours</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">Patient</th>
                        <th class="px-3 py-2 text-center">Sexe</th>
                        <th class="px-3 py-2 text-center">Âge</th>
                        <th class="px-3 py-2 text-left">Service</th>
                        <th class="px-3 py-2 text-left">Lit</th>
                        <th class="px-3 py-2 text-left">Entrée</th>
                        <th class="px-3 py-2 text-center">DS</th>
                        <th class="px-3 py-2 text-left">Diète en cours</th>
                        <th class="px-3 py-2 text-left">Ménage du jour</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($sejours as $v)
                    @php
                        $diete = $v->prescriptionsDiete->first();
                        $taches = $v->tachesMenage;
                    @endphp
                    <tr class="align-top">
                        <td class="px-3 py-3">
                            <a href="{{ route('visites.show', $v) }}" class="text-blue-700 hover:underline font-semibold">
                                {{ $v->patient->nom_complet }}
                            </a>
                            <p class="text-xs text-gray-400">{{ $v->patient->dossier_number }}</p>
                        </td>
                        <td class="px-3 py-3 text-center text-xs">{{ $v->patient->sexe }}</td>
                        <td class="px-3 py-3 text-center text-xs">{{ $v->patient->age ?? '—' }}</td>
                        <td class="px-3 py-3 text-xs">{{ $v->service?->nom ?? '—' }}</td>
                        <td class="px-3 py-3 text-xs">{{ $v->lit?->numero ?? '—' }}</td>
                        <td class="px-3 py-3 text-xs whitespace-nowrap">{{ $v->date_entree->format('d/m/Y') }}</td>
                        <td class="px-3 py-3 text-center font-semibold text-blue-800">{{ $v->joursHospitalisation() }} j</td>
                        <td class="px-3 py-3">
                            @if($diete)
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800">
                                {{ $diete->typeDiete->libelle }}
                            </span>
                            <p class="text-[11px] text-gray-400 mt-1">
                                depuis le {{ $diete->debut->format('d/m') }} · {{ $diete->joursServis() }} j servis
                                · {{ number_format($diete->montant(), 0, ',', ' ') }} CDF
                            </p>
                            @if($diete->estFacturee())
                            <p class="text-[11px] text-green-700">✓ Portée sur la facture du séjour</p>
                            @endif
                            @else
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Aucune</span>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            @forelse($taches as $t)
                            <p class="text-[11px] {{ $t->statut === 'fait' ? 'text-green-700' : 'text-amber-700' }}">
                                {{ $t->statut === 'fait' ? '✓' : '○' }} {{ $t->libelleType() }}
                            </p>
                            @empty
                            <span class="text-[11px] text-gray-400">Rien de fait</span>
                            @endforelse
                        </td>
                        <td class="px-3 py-3 w-96">
                            <form method="POST" action="{{ route('diete.prescrire', $v) }}" class="flex flex-wrap gap-1 mb-2">
                                @csrf
                                <input type="hidden" name="debut" value="{{ $jour }}">
                                <label for="d-{{ $v->id }}" class="sr-only">Prescrire une diète</label>
                                <select id="d-{{ $v->id }}" name="type_diete_id" required class="flex-1 border border-gray-300 rounded px-2 py-1 text-xs">
                                    @foreach($types as $type)
                                    <option value="{{ $type->id }}" @selected($diete?->type_diete_id === $type->id)>{{ $type->libelle }}</option>
                                    @endforeach
                                </select>
                                <button class="bg-blue-700 hover:bg-blue-800 text-white rounded px-3 py-1 text-xs font-semibold">Prescrire</button>
                            </form>
                            @if($diete && $diete->fin === null)
                            {{-- Une mise à jeun avant bloc arrête la diète : sans ce
                                 bouton, la cuisine continuait de servir et la facture
                                 de compter les jours. --}}
                            <form method="POST" action="{{ route('diete.arreter', $v) }}" class="mb-2">
                                @csrf
                                <button class="text-xs text-red-700 hover:underline"
                                        title="Clôture la diète en cours — à faire avant une mise à jeun">
                                    Arrêter la diète
                                </button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('diete.menage', $v) }}" class="flex flex-wrap gap-1">
                                @csrf
                                <input type="hidden" name="jour" value="{{ $jour }}">
                                <input type="hidden" name="statut" value="fait">
                                <label for="m-{{ $v->id }}" class="sr-only">Enregistrer une prestation de ménage</label>
                                <select id="m-{{ $v->id }}" name="type" required class="flex-1 border border-gray-300 rounded px-2 py-1 text-xs">
                                    @foreach($typesMenage as $c => $l)
                                    <option value="{{ $c }}">{{ $l }}</option>
                                    @endforeach
                                </select>
                                <button class="bg-gray-700 hover:bg-gray-800 text-white rounded px-3 py-1 text-xs font-semibold">Ménage fait</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="px-3 py-10 text-center text-gray-400">Aucun patient hospitalisé pour ce filtre</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
