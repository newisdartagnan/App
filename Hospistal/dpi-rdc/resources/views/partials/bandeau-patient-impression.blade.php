{{--
    Bandeau d'identification du patient, commun à tous les documents imprimés :
    bons d'examen, bulletins, comptes rendus, feuilles de bloc.

    La prise en charge y figure sous le nom de la société conventionnée
    (« SONAS — n° SN-8842 ») et non sous le mot « Assurance » : c'est ce nom
    que le patient et le tiers payant doivent lire sur la pièce.

    Variables : $patient (obligatoire), $lignes (paires libellé => valeur
    supplémentaires, facultatif).
--}}
@php
    $lignes = $lignes ?? [];
    $maison = $patient->establishment;
@endphp

{{--
    L'en-tête de l'établissement.

    Six des huit documents imprimés ne nommaient l'hôpital nulle part — pas
    même l'ordonnance. Un papier qui sort d'ici finit chez un pharmacien, un
    laboratoire, un confrère : il doit dire d'où il vient et à quel numéro
    rappeler, sans quoi personne ne peut le vérifier ni le patient revenir.
--}}
<div class="text-center border-b-2 border-blue-800 pb-3 mb-4">
    <p class="text-lg font-bold text-blue-900 tracking-wide">
        {{ mb_strtoupper($maison?->name ?? config('dpi.establishment_name')) }}
    </p>
    <p class="text-xs text-gray-600">
        {{ collect([
            $maison?->adresse,
            $maison?->ville,
            $maison?->province ? 'Province du '.$maison->province : null,
        ])->filter()->implode(' · ') ?: 'République Démocratique du Congo' }}
    </p>
    @if($maison?->telephone)
    <p class="text-xs text-gray-600">Tél. {{ $maison->telephone }}</p>
    @endif
</div>

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
