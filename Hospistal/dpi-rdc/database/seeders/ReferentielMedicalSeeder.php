<?php

namespace Database\Seeders;

use App\Models\ReferentielMedical;
use Illuminate\Database\Seeder;

/**
 * Référentiels structurés des antécédents et des allergies.
 *
 * Ces listes remplacent la saisie en texte libre : c'est la codification qui
 * rend possible l'alerte automatique lorsqu'un produit prescrit correspond à
 * une allergie connue du patient (colonne « molecule »).
 */
class ReferentielMedicalSeeder extends Seeder
{
    public function run(): void
    {
        $antecedents = [
            // Médicaux
            ['ATCD-HTA', 'Hypertension artérielle', 'Médical'],
            ['ATCD-DIAB', 'Diabète', 'Médical'],
            ['ATCD-DREP', 'Drépanocytose', 'Médical'],
            ['ATCD-ASTH', 'Asthme', 'Médical'],
            ['ATCD-EPIL', 'Épilepsie', 'Médical'],
            ['ATCD-TUBE', 'Tuberculose', 'Médical'],
            ['ATCD-VIH', 'Infection à VIH', 'Médical'],
            ['ATCD-HEPB', 'Hépatite B', 'Médical'],
            ['ATCD-PALU', 'Paludisme à répétition', 'Médical'],
            ['ATCD-CARD', 'Cardiopathie', 'Médical'],
            ['ATCD-INSR', 'Insuffisance rénale chronique', 'Médical'],
            ['ATCD-ULCE', 'Ulcère gastro-duodénal', 'Médical'],
            ['ATCD-ANEM', 'Anémie chronique', 'Médical'],
            // Chirurgicaux
            ['ATCD-CESA', 'Césarienne', 'Chirurgical'],
            ['ATCD-APPE', 'Appendicectomie', 'Chirurgical'],
            ['ATCD-HERN', 'Cure de hernie', 'Chirurgical'],
            ['ATCD-LAPA', 'Laparotomie', 'Chirurgical'],
            ['ATCD-FRAC', 'Ostéosynthèse / fracture opérée', 'Chirurgical'],
            // Gynéco-obstétricaux
            ['ATCD-GEST', 'Grossesses antérieures', 'Gynéco-obstétrical'],
            ['ATCD-FAUS', 'Fausse couche', 'Gynéco-obstétrical'],
            ['ATCD-PREE', 'Pré-éclampsie', 'Gynéco-obstétrical'],
            // Familiaux
            ['ATCD-FHTA', 'HTA familiale', 'Familial'],
            ['ATCD-FDIA', 'Diabète familial', 'Familial'],
            ['ATCD-FCAN', 'Cancer familial', 'Familial'],
            ['ATCD-FDRE', 'Drépanocytose familiale', 'Familial'],
            // Mode de vie
            ['ATCD-TABA', 'Tabagisme', 'Mode de vie'],
            ['ATCD-ALCO', 'Consommation d\'alcool', 'Mode de vie'],
        ];

        foreach ($antecedents as [$code, $libelle, $categorie]) {
            ReferentielMedical::updateOrCreate(
                ['type' => 'antecedent', 'code' => $code],
                ['libelle' => $libelle, 'categorie' => $categorie, 'est_actif' => true]
            );
        }

        // La molécule permet de confronter l'allergie aux produits prescrits
        $allergies = [
            ['ALG-PENI', 'Allergie à la pénicilline', 'Médicamenteuse', 'pénicilline'],
            ['ALG-AMOX', 'Allergie à l\'amoxicilline', 'Médicamenteuse', 'amoxicilline'],
            ['ALG-CEFT', 'Allergie à la ceftriaxone', 'Médicamenteuse', 'ceftriaxone'],
            ['ALG-SULF', 'Allergie aux sulfamides', 'Médicamenteuse', 'sulfam'],
            ['ALG-ASPI', 'Allergie à l\'aspirine', 'Médicamenteuse', 'acide acétylsalicylique'],
            ['ALG-AINS', 'Allergie aux anti-inflammatoires', 'Médicamenteuse', 'diclofénac'],
            ['ALG-PARA', 'Allergie au paracétamol', 'Médicamenteuse', 'paracétamol'],
            ['ALG-QUIN', 'Allergie à la quinine', 'Médicamenteuse', 'quinine'],
            ['ALG-METR', 'Allergie au métronidazole', 'Médicamenteuse', 'métronidazole'],
            ['ALG-IODE', 'Allergie à l\'iode / produits de contraste', 'Médicamenteuse', 'iode'],
            ['ALG-ARAC', 'Allergie à l\'arachide', 'Alimentaire', null],
            ['ALG-FRUI', 'Allergie aux fruits de mer', 'Alimentaire', null],
            ['ALG-OEUF', 'Allergie à l\'œuf', 'Alimentaire', null],
            ['ALG-LATE', 'Allergie au latex', 'Contact', null],
            ['ALG-POUS', 'Allergie à la poussière / acariens', 'Respiratoire', null],
            ['ALG-PIQU', 'Allergie aux piqûres d\'insectes', 'Autre', null],
        ];

        foreach ($allergies as [$code, $libelle, $categorie, $molecule]) {
            ReferentielMedical::updateOrCreate(
                ['type' => 'allergie', 'code' => $code],
                [
                    'libelle' => $libelle,
                    'categorie' => $categorie,
                    'molecule' => $molecule,
                    'est_actif' => true,
                ]
            );
        }
    }
}
