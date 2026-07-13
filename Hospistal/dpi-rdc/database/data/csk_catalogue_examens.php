<?php

/**
 * Catalogue d'examens laboratoire et imagerie — extrait du système CSK
 * (Cliniques Spécialisées de Kinshasa) : libellés, prix et valeurs de
 * référence par sexe / âge (homme, femme, enfant < 16 ans).
 *
 * Généré depuis Hospistal/CSK_docker/csk_base_20260309.sql
 */

return [
    'labo' => [
        [
            'code' => 'NFS',
            'libelle' => 'Hémogramme Complet (NFS)',
            'categorie' => 'hematologie',
            'prix' => 5000.0,
            'parametres' => [
                ['nom' => 'Hémoglobine (Hb)', 'unite' => 'g/dL', 'homme' => ['min' => 13.0, 'max' => 17.0], 'femme' => ['min' => 12.0, 'max' => 16.0], 'enfant' => ['min' => 11.0, 'max' => 15.0]],
                ['nom' => 'Hématocrite (Ht)', 'unite' => '%', 'homme' => ['min' => 40.0, 'max' => 54.0], 'femme' => ['min' => 36.0, 'max' => 46.0], 'enfant' => ['min' => 33.0, 'max' => 44.0]],
                ['nom' => 'Globules rouges (GR)', 'unite' => '×10⁶/µL', 'homme' => ['min' => 4.5, 'max' => 5.9], 'femme' => ['min' => 4.0, 'max' => 5.4], 'enfant' => ['min' => 3.8, 'max' => 5.2]],
                ['nom' => 'Globules blancs (GB)', 'unite' => '×10³/µL', 'homme' => ['min' => 4.0, 'max' => 10.0], 'femme' => ['min' => 4.0, 'max' => 10.0], 'enfant' => ['min' => 6.0, 'max' => 15.0]],
                ['nom' => 'Plaquettes', 'unite' => '×10³/µL', 'homme' => ['min' => 150.0, 'max' => 400.0], 'femme' => ['min' => 150.0, 'max' => 400.0], 'enfant' => ['min' => 150.0, 'max' => 400.0]],
            ],
        ],
        [
            'code' => 'GLYC-JEUN',
            'libelle' => 'Glycémie à Jeun',
            'categorie' => 'biochimie',
            'prix' => 3000.0,
            'parametres' => [
                ['nom' => 'Glycémie à jeun', 'unite' => 'g/L', 'homme' => ['min' => 0.7, 'max' => 1.1], 'femme' => ['min' => 0.7, 'max' => 1.1], 'enfant' => ['min' => 0.6, 'max' => 1.0]],
            ],
        ],
        [
            'code' => 'UREE-CREAT',
            'libelle' => 'Urée et Créatinine',
            'categorie' => 'biochimie',
            'prix' => 4000.0,
            'parametres' => [
                ['nom' => 'Urée sanguine', 'unite' => 'g/L', 'homme' => ['min' => 0.15, 'max' => 0.45], 'femme' => ['min' => 0.15, 'max' => 0.45], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Créatinémie', 'unite' => 'mg/L', 'homme' => ['min' => 7.0, 'max' => 13.0], 'femme' => ['min' => 5.0, 'max' => 11.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'BIL-HEP',
            'libelle' => 'Bilan Hépatique Complet',
            'categorie' => 'biochimie',
            'prix' => 8000.0,
            'parametres' => [
                ['nom' => 'ASAT (TGO)', 'unite' => 'UI/L', 'homme' => ['min' => 0.0, 'max' => 40.0], 'femme' => ['min' => 0.0, 'max' => 35.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'ALAT (TGP)', 'unite' => 'UI/L', 'homme' => ['min' => 0.0, 'max' => 41.0], 'femme' => ['min' => 0.0, 'max' => 31.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Bilirubine totale', 'unite' => 'mg/L', 'homme' => ['min' => 0.0, 'max' => 10.0], 'femme' => ['min' => 0.0, 'max' => 10.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Phosphatases alcalines', 'unite' => 'UI/L', 'homme' => ['min' => 40.0, 'max' => 130.0], 'femme' => ['min' => 35.0, 'max' => 105.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'IONO',
            'libelle' => 'Ionogramme Sanguin',
            'categorie' => 'biochimie',
            'prix' => 6000.0,
            'parametres' => [
                ['nom' => 'Sodium (Na)', 'unite' => 'mmol/L', 'homme' => ['min' => 135.0, 'max' => 145.0], 'femme' => ['min' => 135.0, 'max' => 145.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Potassium (K)', 'unite' => 'mmol/L', 'homme' => ['min' => 3.5, 'max' => 5.0], 'femme' => ['min' => 3.5, 'max' => 5.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Chlorures (Cl)', 'unite' => 'mmol/L', 'homme' => ['min' => 98.0, 'max' => 106.0], 'femme' => ['min' => 98.0, 'max' => 106.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Bicarbonates', 'unite' => 'mmol/L', 'homme' => ['min' => 22.0, 'max' => 29.0], 'femme' => ['min' => 22.0, 'max' => 29.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'CRP',
            'libelle' => 'CRP (Protéine C Réactive)',
            'categorie' => 'biochimie',
            'prix' => 4000.0,
            'parametres' => [
                ['nom' => 'CRP (Protéine C Réactive)', 'unite' => 'mg/L', 'homme' => ['min' => 0.0, 'max' => 6.0], 'femme' => ['min' => 0.0, 'max' => 6.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'VS',
            'libelle' => 'VS (Vitesse de Sédimentation)',
            'categorie' => 'hematologie',
            'prix' => 3000.0,
            'parametres' => [
                ['nom' => 'VS 1ère heure', 'unite' => 'mm/h', 'homme' => ['min' => 0.0, 'max' => 15.0], 'femme' => ['min' => 0.0, 'max' => 20.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'COAG',
            'libelle' => 'TP, TCA, INR',
            'categorie' => 'hematologie',
            'prix' => 7000.0,
            'parametres' => [
                ['nom' => 'TP (Taux de Prothrombine)', 'unite' => '%', 'homme' => ['min' => 70.0, 'max' => 100.0], 'femme' => ['min' => 70.0, 'max' => 100.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'TCA', 'unite' => 's', 'homme' => ['min' => 25.0, 'max' => 35.0], 'femme' => ['min' => 25.0, 'max' => 35.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'INR', 'unite' => null, 'homme' => ['min' => 0.8, 'max' => 1.2], 'femme' => ['min' => 0.8, 'max' => 1.2], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'BHCG',
            'libelle' => 'Test de Grossesse Sanguin (β-HCG)',
            'categorie' => 'biochimie',
            'prix' => 5000.0,
            'parametres' => [
                ['nom' => 'β-HCG quantitatif', 'unite' => 'mUI/mL', 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => 5.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'ECBU',
            'libelle' => 'ECBU (Examen Cyto-Bactériologique Urines)',
            'categorie' => 'microbiologie',
            'prix' => 6000.0,
            'parametres' => [
                ['nom' => 'Leucocytes urinaires', 'unite' => '/mm³', 'homme' => ['min' => 0.0, 'max' => 10.0], 'femme' => ['min' => 0.0, 'max' => 10.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Hématies urinaires', 'unite' => '/mm³', 'homme' => ['min' => 0.0, 'max' => 5.0], 'femme' => ['min' => 0.0, 'max' => 5.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Germes / Bactériologie', 'unite' => null, 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'FROT-VAG',
            'libelle' => 'Frottis Vaginal',
            'categorie' => 'microbiologie',
            'prix' => 5000.0,
            'parametres' => [
                ['nom' => 'Cytologie vaginale', 'unite' => null, 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'COPRO',
            'libelle' => 'Coproculture',
            'categorie' => 'microbiologie',
            'prix' => 7000.0,
            'parametres' => [
                ['nom' => 'Bactériologie des selles', 'unite' => null, 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'GROUP-ABO',
            'libelle' => 'Groupage Sanguin ABO-Rhésus',
            'categorie' => 'hematologie',
            'prix' => 6000.0,
            'parametres' => [
                ['nom' => 'Groupage ABO-Rhésus', 'unite' => null, 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'SERO-VIH',
            'libelle' => 'Sérologie VIH',
            'categorie' => 'serologie',
            'prix' => 5000.0,
            'parametres' => [
                ['nom' => 'Sérologie VIH 1 & 2', 'unite' => null, 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'SERO-HBV',
            'libelle' => 'Sérologie Hépatite B',
            'categorie' => 'serologie',
            'prix' => 7000.0,
            'parametres' => [
                ['nom' => 'Sérologie Hépatite B', 'unite' => null, 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'FROT-SANG',
            'libelle' => 'Frottis Sanguin',
            'categorie' => 'hematologie',
            'prix' => 5000.0,
            'parametres' => [
                ['nom' => 'Frottis sanguin / Goutte épaisse', 'unite' => null, 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'MYELO',
            'libelle' => 'Myélogramme',
            'categorie' => 'hematologie',
            'prix' => 25000.0,
            'parametres' => [],
        ],
        [
            'code' => 'ELECTRO-HB',
            'libelle' => 'Électrophorèse de l\'Hémoglobine',
            'categorie' => 'hematologie',
            'prix' => 15000.0,
            'parametres' => [],
        ],
        [
            'code' => 'FERRIT',
            'libelle' => 'Dosage de la Ferritine',
            'categorie' => 'hematologie',
            'prix' => 7000.0,
            'parametres' => [],
        ],
        [
            'code' => 'VIT-B12',
            'libelle' => 'Dosage de la Vitamine B12',
            'categorie' => 'hematologie',
            'prix' => 8000.0,
            'parametres' => [],
        ],
        [
            'code' => 'FOLATE',
            'libelle' => 'Dosage de l\'Acide Folique',
            'categorie' => 'hematologie',
            'prix' => 8000.0,
            'parametres' => [],
        ],
        [
            'code' => 'COOMBS-D',
            'libelle' => 'Test de Coombs Direct',
            'categorie' => 'hematologie',
            'prix' => 10000.0,
            'parametres' => [],
        ],
        [
            'code' => 'COAG-ETUDE',
            'libelle' => 'Étude de la Coagulation',
            'categorie' => 'hematologie',
            'prix' => 20000.0,
            'parametres' => [],
        ],
        [
            'code' => 'LIPID',
            'libelle' => 'Bilan Lipidique',
            'categorie' => 'biochimie',
            'prix' => 10000.0,
            'parametres' => [
                ['nom' => 'Cholestérol total', 'unite' => 'g/L', 'homme' => ['min' => 0.0, 'max' => 2.0], 'femme' => ['min' => 0.0, 'max' => 2.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'HDL-Cholestérol', 'unite' => 'g/L', 'homme' => ['min' => 0.4, 'max' => null], 'femme' => ['min' => 0.5, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'LDL-Cholestérol', 'unite' => 'g/L', 'homme' => ['min' => 0.0, 'max' => 1.3], 'femme' => ['min' => 0.0, 'max' => 1.3], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Triglycérides', 'unite' => 'g/L', 'homme' => ['min' => 0.0, 'max' => 1.5], 'femme' => ['min' => 0.0, 'max' => 1.5], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'THYRO',
            'libelle' => 'Bilan Thyroïdien Complet',
            'categorie' => 'biochimie',
            'prix' => 15000.0,
            'parametres' => [],
        ],
        [
            'code' => 'RENAL',
            'libelle' => 'Bilan Rénal Complet',
            'categorie' => 'biochimie',
            'prix' => 12000.0,
            'parametres' => [
                ['nom' => 'Urée', 'unite' => 'g/L', 'homme' => ['min' => 0.15, 'max' => 0.45], 'femme' => ['min' => 0.15, 'max' => 0.45], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Créatinémie', 'unite' => 'mg/L', 'homme' => ['min' => 7.0, 'max' => 13.0], 'femme' => ['min' => 5.0, 'max' => 11.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Clairance créatinine', 'unite' => 'mL/min', 'homme' => ['min' => 60.0, 'max' => null], 'femme' => ['min' => 60.0, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Acide urique (Uricémie)', 'unite' => 'mg/L', 'homme' => ['min' => 25.0, 'max' => 70.0], 'femme' => ['min' => 20.0, 'max' => 60.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'HEPAT',
            'libelle' => 'Bilan Hépatique Élargi',
            'categorie' => 'biochimie',
            'prix' => 12000.0,
            'parametres' => [
                ['nom' => 'ASAT (TGO)', 'unite' => 'UI/L', 'homme' => ['min' => 0.0, 'max' => 40.0], 'femme' => ['min' => 0.0, 'max' => 35.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'ALAT (TGP)', 'unite' => 'UI/L', 'homme' => ['min' => 0.0, 'max' => 41.0], 'femme' => ['min' => 0.0, 'max' => 31.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'GGT', 'unite' => 'UI/L', 'homme' => ['min' => 0.0, 'max' => 55.0], 'femme' => ['min' => 0.0, 'max' => 35.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Bilirubine totale', 'unite' => 'mg/L', 'homme' => ['min' => 0.0, 'max' => 10.0], 'femme' => ['min' => 0.0, 'max' => 10.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Albumine', 'unite' => 'g/L', 'homme' => ['min' => 35.0, 'max' => 50.0], 'femme' => ['min' => 35.0, 'max' => 50.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'PHOSPHO',
            'libelle' => 'Bilan Phosphocalcique',
            'categorie' => 'biochimie',
            'prix' => 8000.0,
            'parametres' => [
                ['nom' => 'Calcémie', 'unite' => 'mg/L', 'homme' => ['min' => 85.0, 'max' => 105.0], 'femme' => ['min' => 85.0, 'max' => 105.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Phosphorémie', 'unite' => 'mg/L', 'homme' => ['min' => 25.0, 'max' => 45.0], 'femme' => ['min' => 25.0, 'max' => 45.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Phosphatases alc.', 'unite' => 'UI/L', 'homme' => ['min' => 40.0, 'max' => 130.0], 'femme' => ['min' => 35.0, 'max' => 105.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'ENZ-CARD',
            'libelle' => 'Dosage des Enzymes Cardiaques',
            'categorie' => 'biochimie',
            'prix' => 15000.0,
            'parametres' => [
                ['nom' => 'Troponine I', 'unite' => 'ng/L', 'homme' => ['min' => 0.0, 'max' => 34.0], 'femme' => ['min' => 0.0, 'max' => 16.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'CK-MB', 'unite' => 'UI/L', 'homme' => ['min' => 0.0, 'max' => 25.0], 'femme' => ['min' => 0.0, 'max' => 25.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Myoglobine', 'unite' => 'µg/L', 'homme' => ['min' => 0.0, 'max' => 92.0], 'femme' => ['min' => 0.0, 'max' => 76.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'TUMOR',
            'libelle' => 'Dosage des Marqueurs Tumoraux',
            'categorie' => 'biochimie',
            'prix' => 20000.0,
            'parametres' => [
                ['nom' => 'PSA total', 'unite' => 'ng/mL', 'homme' => ['min' => 0.0, 'max' => 4.0], 'femme' => ['min' => null, 'max' => null], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'ACE', 'unite' => 'ng/mL', 'homme' => ['min' => 0.0, 'max' => 5.0], 'femme' => ['min' => 0.0, 'max' => 5.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'CA 15-3', 'unite' => 'UI/mL', 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => 0.0, 'max' => 38.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'CA 19-9', 'unite' => 'UI/mL', 'homme' => ['min' => 0.0, 'max' => 37.0], 'femme' => ['min' => 0.0, 'max' => 37.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'HORM-STERO',
            'libelle' => 'Dosage des Hormones Stéroïdiennes',
            'categorie' => 'biochimie',
            'prix' => 18000.0,
            'parametres' => [
                ['nom' => 'Cortisol (8h)', 'unite' => 'µg/L', 'homme' => ['min' => 60.0, 'max' => 230.0], 'femme' => ['min' => 60.0, 'max' => 230.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Testostérone', 'unite' => 'ng/L', 'homme' => ['min' => 280.0, 'max' => 1100.0], 'femme' => ['min' => 15.0, 'max' => 70.0], 'enfant' => ['min' => null, 'max' => null]],
                ['nom' => 'Œstradiol (E2)', 'unite' => 'pg/mL', 'homme' => ['min' => null, 'max' => null], 'femme' => ['min' => 20.0, 'max' => 400.0], 'enfant' => ['min' => null, 'max' => null]],
            ],
        ],
        [
            'code' => 'GE',
            'libelle' => 'Goutte épaisse (paludisme)',
            'categorie' => 'parasitologie',
            'prix' => 5000.0,
            'parametres' => [],
        ],
    ],

    'imagerie' => [
        ['code' => 'IMG-RX-THOR-FP', 'libelle' => 'Radiographie Thorax Face/Profil', 'prix' => 8000.0],
        ['code' => 'IMG-RX-ASP', 'libelle' => 'Radiographie Abdomen sans Préparation', 'prix' => 8000.0],
        ['code' => 'IMG-RX-RACH-LOMB', 'libelle' => 'Radiographie Rachis Lombaire', 'prix' => 10000.0],
        ['code' => 'IMG-ECHO-ABD', 'libelle' => 'Échographie Abdominale', 'prix' => 15000.0],
        ['code' => 'IMG-ECHO-PELV', 'libelle' => 'Échographie Pelvienne', 'prix' => 12000.0],
        ['code' => 'IMG-ECHO-OBST', 'libelle' => 'Échographie Obstétricale', 'prix' => 10000.0],
        ['code' => 'IMG-ECHO-MAM', 'libelle' => 'Échographie Mammaire', 'prix' => 12000.0],
        ['code' => 'IMG-ECHO-THYR', 'libelle' => 'Échographie Thyroïdienne', 'prix' => 10000.0],
        ['code' => 'IMG-ECHO-TEST', 'libelle' => 'Échographie Testiculaire', 'prix' => 10000.0],
        ['code' => 'IMG-MAMMO', 'libelle' => 'Mammographie Numérique', 'prix' => 20000.0],
    ],
];
