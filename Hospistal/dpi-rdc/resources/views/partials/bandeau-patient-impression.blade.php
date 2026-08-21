{{--
    Bandeau d'identification du patient, commun à tous les documents imprimés :
    bons d'examen, bulletins, comptes rendus, feuilles de bloc.

    La prise en charge y figure sous le nom de la société conventionnée
    (« SONAS — n° SN-8842 ») et non sous le mot « Assurance » : c'est ce nom
    que le patient et le tiers payant doivent lire sur la pièce.

    Variables : $patient (obligatoire), $lignes (paires libellé => valeur
    supplémentaires, facultatif).
--}}
@php $lignes = $lignes ?? []; @endphp
<div class="bloc">
    <div class="bloc-titre">Patient</div>
    <div class="info-patient">
        <div><strong>Nom :</strong> {{ mb_strtoupper($patient->nom) }} {{ $patient->postnom }} {{ $patient->prenom }}</div>
        <div><strong>Dossier :</strong> {{ $patient->dossier_number }}</div>
        <div><strong>Sexe / Âge :</strong> {{ $patient->sexe === 'F' ? 'Féminin' : 'Masculin' }}
            @if($patient->date_naissance) / {{ $patient->date_naissance->age }} ans @endif</div>
        <div><strong>Prise en charge :</strong> {{ $patient->libellePriseEnCharge() }}</div>
        @foreach($lignes as $libelle => $valeur)
        <div><strong>{{ $libelle }} :</strong> {{ $valeur }}</div>
        @endforeach
    </div>
</div>
