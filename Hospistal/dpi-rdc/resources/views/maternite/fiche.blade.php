@extends('print.layout')
@section('title', 'Fiche obstétricale — '.$grossesse->patient->nom_complet)
@section('service', 'Maternité — suivi de la grossesse')
@section('numero')
    <div class="numero">{{ $grossesse->formuleObstetricale() }}</div>
    <div>{{ $grossesse->patient->dossier_number }}</div>
@endsection

@section('contenu')
<h2 class="titre-doc">Fiche obstétricale</h2>

@include('partials.bandeau-patient-impression', [
    'patient' => $grossesse->patient,
    'lignes' => array_filter([
        'Dernières règles' => $grossesse->date_dernieres_regles?->format('d/m/Y'),
        'Terme prévu' => $grossesse->date_prevue_accouchement?->format('d/m/Y'),
        'Formule obstétricale' => $grossesse->formuleObstetricale(),
        'Groupe sanguin' => $grossesse->groupe_sanguin,
    ]),
])

@if($grossesse->grossesse_a_risque || $grossesse->antecedents || $grossesse->serologiesPositives() !== [])
<div class="bloc">
    <div class="bloc-titre">Éléments de vigilance</div>
    <table class="donnees">
        <tbody>
            @if($grossesse->grossesse_a_risque)
            <tr>
                <th style="width:30%;">Grossesse à risque</th>
                <td class="anormal">{{ $grossesse->motif_risque ?: 'Oui' }}</td>
            </tr>
            @endif
            @if($grossesse->antecedents)
            <tr><th>Antécédents</th><td>{{ $grossesse->antecedents }}</td></tr>
            @endif
            @if($grossesse->serologiesPositives() !== [])
            <tr>
                <th>Sérologies positives</th>
                <td class="anormal">{{ implode(' · ', $grossesse->serologiesPositives()) }}</td>
            </tr>
            @endif
        </tbody>
    </table>
</div>
@endif

<div class="bloc">
    <div class="bloc-titre">Consultations prénatales</div>
    <table class="donnees">
        <thead><tr>
            <th>N°</th><th>Date</th><th>Terme</th><th class="num">Poids</th>
            <th>Tension</th><th class="num">HU</th><th class="num">BCF</th>
            <th>Alb.</th><th class="num">Hb</th><th>Conduite</th>
        </tr></thead>
        <tbody>
            @forelse($grossesse->consultations as $cpn)
            @php $alertes = $cpn->alertes(); @endphp
            <tr>
                <td>{{ $cpn->numero }}</td>
                <td>{{ $cpn->date_consultation->format('d/m/Y') }}</td>
                <td>{{ $cpn->terme_semaines ? $cpn->terme_semaines.' SA' : '—' }}</td>
                <td class="num">{{ $cpn->poids_kg ? ($cpn->poids_kg + 0).' kg' : '—' }}</td>
                <td class="{{ $cpn->tension_systolique >= 140 ? 'anormal' : '' }}">{{ $cpn->tension() ?? '—' }}</td>
                <td class="num">{{ $cpn->hauteur_uterine_cm ? ($cpn->hauteur_uterine_cm + 0) : '—' }}</td>
                <td class="num">{{ $cpn->bruits_coeur_foetal ?? '—' }}</td>
                <td class="{{ in_array($cpn->albuminurie, ['+','++','+++'], true) ? 'anormal' : '' }}">
                    {{ $cpn->albuminurie ?: '—' }}
                </td>
                <td class="num {{ $cpn->hemoglobine !== null && $cpn->hemoglobine < 11 ? 'anormal' : '' }}">
                    {{ $cpn->hemoglobine ? ($cpn->hemoglobine + 0) : '—' }}
                </td>
                <td>{{ $cpn->conduite_a_tenir ?: ($alertes !== [] ? implode(' · ', $alertes) : '—') }}</td>
            </tr>
            @empty
            <tr><td colspan="10" style="text-align:center;color:#888;padding:12px;">
                Aucune consultation enregistrée.
            </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($grossesse->accouchement)
@php $acc = $grossesse->accouchement; @endphp
<div class="bloc">
    <div class="bloc-titre vert">Accouchement</div>
    <table class="donnees">
        <tbody>
            <tr>
                <th style="width:30%;">Date et heure</th>
                <td>{{ $acc->date_accouchement->format('d/m/Y à H:i') }}
                    @if($acc->dureeTravail()) — travail de {{ $acc->dureeTravail() }} @endif</td>
            </tr>
            <tr>
                <th>Mode</th>
                <td>{{ $acc->libelleMode() }}
                    @if($acc->presentation) — présentation {{ mb_strtolower(\App\Models\Accouchement::PRESENTATIONS[$acc->presentation] ?? $acc->presentation) }}@endif</td>
            </tr>
            <tr>
                <th>Terme</th>
                <td class="{{ $acc->estPremature() ? 'anormal' : '' }}">
                    {{ $acc->terme_semaines ? $acc->terme_semaines.' SA' : '—' }}
                    @if($acc->estPremature()) — prématuré @endif
                </td>
            </tr>
            <tr>
                <th>Délivrance</th>
                <td>{{ \App\Models\Accouchement::DELIVRANCES[$acc->delivrance] ?? '—' }}
                    @if($acc->episiotomie) · épisiotomie @endif
                    @if($acc->dechirure && $acc->dechirure !== 'aucune')
                        · déchirure {{ \App\Models\Accouchement::DECHIRURES[$acc->dechirure] ?? $acc->dechirure }}
                    @endif
                </td>
            </tr>
            <tr>
                <th>Saignement</th>
                <td class="{{ $acc->estHemorragique() ? 'anormal' : '' }}">
                    {{ $acc->saignement_ml !== null ? $acc->saignement_ml.' ml' : '—' }}
                    @if($acc->estHemorragique()) — hémorragie de la délivrance @endif
                    @if($acc->transfusion) · transfusion @endif
                </td>
            </tr>
            <tr>
                <th>Équipe</th>
                <td>{{ $acc->accoucheur?->nom_complet ?? '—' }}
                    @if($acc->sage_femme) · sage-femme : {{ $acc->sage_femme }}@endif</td>
            </tr>
            <tr>
                <th>État de la mère</th>
                <td class="{{ in_array($acc->etat_mere, ['grave','deces'], true) ? 'anormal' : 'normal' }}">
                    {{ \App\Models\Accouchement::ETATS_MERE[$acc->etat_mere] ?? $acc->etat_mere }}
                    @if($acc->complications) — {{ $acc->complications }}@endif
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="bloc">
    <div class="bloc-titre vert">Nouveau-né{{ $acc->nouveauNes->count() > 1 ? 's' : '' }}</div>
    <table class="donnees">
        <thead><tr>
            <th>Rang</th><th>Sexe</th><th class="num">Poids</th><th class="num">Taille</th>
            <th class="num">PC</th><th>Apgar</th><th>État</th><th>Dossier</th>
        </tr></thead>
        <tbody>
            @foreach($acc->nouveauNes as $enfant)
            <tr>
                <td>{{ $enfant->rang }}</td>
                <td>{{ $enfant->libelleSexe() }}</td>
                <td class="num {{ $enfant->estPetitPoids() ? 'anormal' : '' }}">
                    {{ $enfant->poids_g ? $enfant->poids_g.' g' : '—' }}
                </td>
                <td class="num">{{ $enfant->taille_cm ? ($enfant->taille_cm + 0).' cm' : '—' }}</td>
                <td class="num">{{ $enfant->perimetre_cranien_cm ? ($enfant->perimetre_cranien_cm + 0).' cm' : '—' }}</td>
                <td class="{{ $enfant->souffranceNeonatale() ? 'anormal' : '' }}">{{ $enfant->apgar() }}</td>
                <td class="{{ $enfant->estVivant() ? 'normal' : 'anormal' }}">{{ $enfant->libelleStatut() }}</td>
                <td>{{ $enfant->patient?->dossier_number ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="signature">
    <div class="cadre">La sage-femme<div class="ligne">Signature</div></div>
    <div class="cadre">Le médecin<div class="ligne">Signature et cachet</div></div>
</div>
@endsection
