@extends('print.layout')
@section('title', 'Feuille de service — diète et ménage')
@section('service', 'Diète et ménage — service hôtelier')

@section('numero')
    <div class="numero">DIETE</div>
    <div>{{ \Carbon\Carbon::parse($jour)->format('d/m/Y') }}</div>
@endsection

@section('contenu')
    <h2 class="titre-doc">Feuille de service — diète et ménage</h2>

    <div class="bloc">
        <div class="info-patient">
            <div><strong>Jour :</strong> {{ \Carbon\Carbon::parse($jour)->locale('fr')->isoFormat('dddd D MMMM YYYY') }}</div>
            <div><strong>Service :</strong> {{ $service?->nom ?? 'Tous les services' }}</div>
            <div><strong>Patients servis :</strong> {{ $sejours->flatten()->count() }}</div>
            <div><strong>Éditée le :</strong> {{ now()->format('d/m/Y à H:i') }}</div>
        </div>
    </div>

    @forelse($sejours as $nomService => $lignes)
    <div class="bloc">
        <div class="bloc-titre">{{ $nomService }} — {{ $lignes->count() }} patient(s)</div>
        <table class="donnees">
            <thead>
                <tr>
                    <th style="width:26%">Patient</th>
                    <th style="width:10%">Lit</th>
                    <th style="width:8%">Âge</th>
                    <th style="width:8%">DS</th>
                    <th style="width:26%">Diète à servir</th>
                    <th style="width:22%">Ménage effectué</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $v)
                @php $diete = $v->prescriptionsDiete->first(); @endphp
                <tr>
                    <td>{{ $v->patient->nom_complet }}</td>
                    <td>{{ $v->lit?->numero ?? '—' }}</td>
                    <td class="num">{{ $v->patient->age ?? '—' }}</td>
                    <td class="num">{{ $v->joursHospitalisation() }} j</td>
                    <td class="{{ $diete ? '' : 'anormal' }}">
                        {{ $diete?->typeDiete->libelle ?? 'AUCUNE DIÈTE PRESCRITE' }}
                    </td>
                    <td></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @empty
    <p style="text-align:center;color:#888;margin:30px 0">Aucun patient hospitalisé pour ce filtre.</p>
    @endforelse

    <div class="signature">
        <div class="cadre">
            <div class="ligne">Responsable cuisine</div>
        </div>
        <div class="cadre">
            <div class="ligne">Responsable entretien</div>
        </div>
    </div>
@endsection
