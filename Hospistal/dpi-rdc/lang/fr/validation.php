<?php

/**
 * Messages de validation en français.
 *
 * L'application s'adresse à des soignants francophones : un « validation.uuid »
 * affiché à l'écran ne veut rien dire pour eux. Les messages métier restent
 * définis au cas par cas dans les contrôleurs ; ceux-ci sont le filet.
 */
return [
    'accepted' => 'Le champ :attribute doit être accepté.',
    'after' => 'Le champ :attribute doit être postérieur à :date.',
    'after_or_equal' => 'Le champ :attribute doit être postérieur ou égal à :date.',
    'array' => 'Le champ :attribute doit être une liste.',
    'before' => 'Le champ :attribute doit être antérieur à :date.',
    'before_or_equal' => 'Le champ :attribute doit être antérieur ou égal à :date.',
    'between' => [
        'array' => 'Le champ :attribute doit compter entre :min et :max éléments.',
        'file' => 'Le fichier :attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit comporter entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ :attribute doit valoir oui ou non.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'date' => 'Le champ :attribute n\'est pas une date valide.',
    'date_format' => 'Le champ :attribute ne correspond pas au format :format.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit comporter :digits chiffres.',
    'digits_between' => 'Le champ :attribute doit comporter entre :min et :max chiffres.',
    'email' => 'Le champ :attribute doit être une adresse électronique valide.',
    'exists' => 'La valeur choisie pour :attribute n\'existe pas.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute doit être renseigné.',
    'gt' => [
        'numeric' => 'Le champ :attribute doit être supérieur à :value.',
        'string' => 'Le champ :attribute doit comporter plus de :value caractères.',
    ],
    'gte' => [
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
    ],
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur choisie pour :attribute n\'est pas autorisée.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'lt' => [
        'numeric' => 'Le champ :attribute doit être inférieur à :value.',
    ],
    'lte' => [
        'numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.',
    ],
    'max' => [
        'array' => 'Le champ :attribute ne peut compter plus de :max éléments.',
        'file' => 'Le fichier :attribute ne peut dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut dépasser :max.',
        'string' => 'Le champ :attribute ne peut dépasser :max caractères.',
    ],
    'mimes' => 'Le champ :attribute doit être un fichier de type :values.',
    'min' => [
        'array' => 'Le champ :attribute doit compter au moins :min éléments.',
        'file' => 'Le fichier :attribute doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string' => 'Le champ :attribute doit comporter au moins :min caractères.',
    ],
    'not_in' => 'La valeur choisie pour :attribute n\'est pas autorisée.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'present' => 'Le champ :attribute doit être présent.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire lorsque :other vaut :value.',
    'required_with' => 'Le champ :attribute est obligatoire lorsque :values est renseigné.',
    'same' => 'Les champs :attribute et :other doivent correspondre.',
    'size' => [
        'array' => 'Le champ :attribute doit compter :size éléments.',
        'file' => 'Le fichier :attribute doit peser :size kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit comporter :size caractères.',
    ],
    'string' => 'Le champ :attribute doit être du texte.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => 'Le fichier :attribute n\'a pas pu être téléversé.',
    'url' => 'Le format du champ :attribute est invalide.',
    'uuid' => 'Le champ :attribute doit être un identifiant valide.',

    'custom' => [],

    /**
     * Noms lisibles des champs : « Le champ patient_id est obligatoire »
     * devient « Le champ patient est obligatoire ».
     */
    'attributes' => [
        'patient_id' => 'patient',
        'medicament_id' => 'médicament',
        'generateur_id' => 'générateur',
        'salle_id' => 'salle d\'opération',
        'operateur_id' => 'chirurgien',
        'anesthesiste_id' => 'anesthésiste',
        'nephrologue_id' => 'néphrologue',
        'accoucheur_id' => 'accoucheur',
        'poche_id' => 'poche de sang',
        'groupe_sanguin' => 'groupe sanguin',
        'date_seance' => 'date de la séance',
        'date_prevue' => 'date prévue',
        'date_accouchement' => 'date d\'accouchement',
        'date_dernieres_regles' => 'date des dernières règles',
        'duree_minutes' => 'durée',
        'poids_avant_kg' => 'poids d\'entrée',
        'poids_apres_kg' => 'poids de sortie',
        'heure_entree_salle' => 'heure d\'entrée en salle',
        'heure_sortie_salle' => 'heure de sortie de salle',
        'compte_rendu' => 'compte rendu',
        'nombre_poches' => 'nombre de poches',
        'type_produit' => 'type de produit',
        'taux_cdf' => 'taux',
        'mot_de_passe' => 'mot de passe',
        'password' => 'mot de passe',
        'telephone' => 'téléphone',
        'email' => 'adresse électronique',
    ],
];
