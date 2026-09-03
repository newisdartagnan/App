<?php

namespace Database\Seeders;

use App\Models\ReferentielMedical;
use Illuminate\Database\Seeder;

/**
 * Les diagnostics courants, avec leur code CIM-11.
 *
 * Ce n'est pas la CIM-11 : celle-ci compte plus de dix-sept mille entrées et
 * n'a pas sa place dans une application qui doit tourner hors ligne sur un
 * poste d'hôpital. C'est la liste de ce qu'on voit réellement passer en
 * République Démocratique du Congo — paludisme, tuberculose, VIH, fièvre
 * typhoïde, malnutrition, drépanocytose — celle qui couvre l'immense
 * majorité des consultations.
 *
 * Deux précautions y sont attachées.
 *
 * La première : rien n'oblige à s'en servir. Le médecin écrit ce qu'il veut,
 * référencé ou non ; un catalogue incomplet ne doit jamais empêcher de poser
 * un diagnostic. Un cas rare s'écrit en toutes lettres, sans code, et le
 * dossier le garde tel quel.
 *
 * La seconde : ces codes sont posés d'après la classification publiée, mais
 * ils n'ont pas été relus par un médecin de la maison. Ils sont donc marqués
 * « à vérifier » tant que quelqu'un ne les a pas confirmés, et l'écran le
 * dit. Un code faux dans un rapport épidémiologique vaut moins que pas de
 * code du tout : il se propage sans qu'on le voie.
 *
 * Les synonymes sont ce que les gens tapent — « palu », « TB », « HTA » —
 * et non ce que la nomenclature écrit.
 */
class DiagnosticCim11Seeder extends Seeder
{
    /**
     * @var array<string, array<int, array{0: string, 1: string, 2: string}>>
     *                                                                        Catégorie => [code CIM-11, libellé, synonymes]
     */
    private const DIAGNOSTICS = [
        'Paludisme' => [
            ['1F40', 'Paludisme à Plasmodium falciparum', 'palu, malaria, falciparum, paludisme simple'],
            ['1F41', 'Paludisme à Plasmodium vivax', 'palu vivax'],
            ['1F42', 'Paludisme à Plasmodium malariae', 'palu malariae'],
            ['1F43', 'Paludisme à Plasmodium ovale', 'palu ovale'],
            ['1F45', 'Paludisme grave', 'palu grave, paludisme compliqué, neuropaludisme'],
            ['1F4Z', 'Paludisme, sans précision', 'palu, paludisme'],
        ],
        'Infections intestinales' => [
            ['1A00', 'Choléra', 'cholera'],
            ['1A07', 'Fièvre typhoïde', 'typhoide, typhoïde, salmonellose, widal'],
            ['1A36', 'Amibiase', 'amibe, amibiase intestinale, dysenterie amibienne'],
            ['1A40', 'Gastro-entérite ou colite infectieuse', 'gastro, diarrhée infectieuse, GEA'],
            ['1A2Z', 'Shigellose', 'dysenterie bacillaire'],
            ['1B96', 'Parasitose intestinale', 'vers, ascaris, helminthiase, ankylostome'],
        ],
        'Tuberculose et VIH' => [
            ['1B10', 'Tuberculose pulmonaire', 'TB, TBC, tuberculose, BK, BAAR'],
            ['1B12', 'Tuberculose extrapulmonaire', 'TB extrapulmonaire, mal de Pott'],
            ['1B1Z', 'Tuberculose, sans précision', 'TB'],
            ['1C62', 'Infection à VIH confirmée', 'VIH, SIDA, HIV, séropositif'],
            ['1C6Z', 'Infection à VIH, sans précision', 'VIH'],
        ],
        'Autres maladies transmissibles' => [
            ['1F03', 'Rougeole', 'rougeole'],
            ['1C12', 'Tétanos', 'tetanos'],
            ['1E50', 'Hépatite B', 'hepatite B, VHB'],
            ['1E51', 'Hépatite C', 'hepatite C, VHC'],
            ['1D01', 'Méningite bactérienne', 'meningite'],
            ['1G40', 'Sepsis', 'septicémie, sepsis grave, choc septique'],
            ['1F2Z', 'Fièvre virale, sans précision', 'fievre virale'],
            ['1D60', 'Maladie à virus Ebola', 'ebola, fièvre hémorragique'],
        ],
        'Appareil respiratoire' => [
            ['CA40', 'Pneumonie', 'pneumonie, pneumopathie, broncho-pneumonie'],
            ['CA20', 'Bronchite aiguë', 'bronchite'],
            ['CA23', 'Asthme', 'asthme, crise d\'asthme'],
            ['CA07', 'Infection aiguë des voies respiratoires supérieures', 'IRA, rhinopharyngite, IVRS'],
            ['CA0J', 'Angine aiguë', 'angine, amygdalite, pharyngite'],
            ['AA9Z', 'Otite moyenne aiguë', 'otite, OMA'],
        ],
        'Appareil circulatoire' => [
            ['BA00', 'Hypertension artérielle essentielle', 'HTA, hypertension, tension'],
            ['BA41', 'Insuffisance cardiaque', 'IC, insuffisance cardiaque congestive, ICC'],
            ['8B20', 'Accident vasculaire cérébral', 'AVC, hémiplégie'],
            ['BD10', 'Artériopathie des membres inférieurs', 'AOMI'],
        ],
        'Endocrinien et métabolique' => [
            ['5A11', 'Diabète sucré de type 2', 'diabete, diabète type 2, DT2'],
            ['5A10', 'Diabète sucré de type 1', 'diabète type 1, DT1'],
            ['5B50', 'Malnutrition aiguë sévère', 'MAS, malnutrition sévère, marasme, kwashiorkor'],
            ['5B51', 'Malnutrition aiguë modérée', 'MAM, malnutrition modérée'],
            ['5B71', 'Carence en fer', 'carence martiale'],
            ['5B5K', 'Déshydratation', 'deshydratation, DHA'],
        ],
        'Sang' => [
            ['3A9Z', 'Anémie, sans précision', 'anemie, anémie'],
            ['3A00', 'Anémie ferriprive', 'anémie par carence en fer'],
            ['3A51', 'Drépanocytose', 'drepanocytose, SS, sicklémie, crise vaso-occlusive'],
        ],
        'Digestif' => [
            ['DA60', 'Ulcère gastroduodénal', 'ulcere, ulcère gastrique, UGD'],
            ['DA42', 'Gastrite', 'gastrite, épigastralgie'],
            ['DB92', 'Hépatopathie chronique', 'cirrhose, hépatopathie'],
            ['DC11', 'Appendicite aiguë', 'appendicite'],
            ['DD91', 'Hernie inguinale', 'hernie'],
        ],
        'Génito-urinaire' => [
            ['GC08', 'Infection des voies urinaires', 'IU, infection urinaire, cystite'],
            ['GB61', 'Pyélonéphrite aiguë', 'pyelonephrite, PNA'],
            ['GB61.Z', 'Insuffisance rénale', 'IRA, IRC, insuffisance rénale'],
            ['GA05', 'Infection génitale basse', 'vaginite, leucorrhées, IST'],
        ],
        'Grossesse et accouchement' => [
            ['JA65', 'Pré-éclampsie', 'preeclampsie, pré-éclampsie, HTA gravidique'],
            ['JA66', 'Éclampsie', 'eclampsie'],
            ['JB43', 'Hémorragie du post-partum', 'HPP, hémorragie de la délivrance'],
            ['JA00', 'Avortement spontané', 'fausse couche, avortement'],
            ['JA86', 'Menace d\'accouchement prématuré', 'MAP'],
            ['JB0Z', 'Accouchement, sans complication', 'accouchement eutocique'],
        ],
        'Nouveau-né' => [
            ['KA21', 'Asphyxie néonatale', 'asphyxie, souffrance néonatale'],
            ['KA60', 'Ictère néonatal', 'ictere du nouveau-né, jaunisse'],
            ['KA60.0', 'Infection néonatale', 'infection néonatale, sepsis néonatal'],
            ['KA21.4', 'Prématurité', 'premature, prématuré'],
        ],
        'Traumatismes' => [
            ['NA0Z', 'Traumatisme crânien', 'TC, traumatisme cranien'],
            ['NC5Z', 'Fracture, sans précision', 'fracture'],
            ['ND56', 'Plaie', 'plaie, blessure'],
            ['NE2Z', 'Brûlure', 'brulure, brûlure'],
            ['PA80', 'Accident de la voie publique', 'AVP, accident de circulation'],
        ],
        'Symptômes et situations' => [
            ['MG50', 'Fièvre, sans cause précisée', 'fievre, fièvre, hyperthermie'],
            ['MD11', 'Douleur abdominale', 'douleur abdo, algie abdominale'],
            ['MB40', 'Céphalées', 'cephalees, mal de tête, céphalée'],
            ['MG43', 'Asthénie', 'fatigue, asthenie'],
            ['QA00', 'Consultation de contrôle', 'controle, suivi, contrôle'],
            ['QA46', 'Consultation prénatale', 'CPN, prénatal'],
        ],
    ];

    public function run(): void
    {
        foreach (self::DIAGNOSTICS as $categorie => $entrees) {
            foreach ($entrees as [$code, $libelle, $synonymes]) {
                // Idempotent : le seeder tourne à chaque déploiement, et un
                // code corrigé à la main par un médecin ne doit pas être
                // réécrit par la reprise du socle.
                $existant = ReferentielMedical::where('type', 'diagnostic')
                    ->where('code', $code)
                    ->first();

                if ($existant) {
                    // On complète ce qui manque sans toucher au reste.
                    $existant->fill([
                        'synonymes' => $existant->synonymes ?: $synonymes,
                        'categorie' => $existant->categorie ?: $categorie,
                    ])->save();

                    continue;
                }

                ReferentielMedical::create([
                    'type' => 'diagnostic',
                    'code' => $code,
                    'libelle' => $libelle,
                    'categorie' => $categorie,
                    'synonymes' => $synonymes,
                    'code_verifie' => false,
                    'est_actif' => true,
                ]);
            }
        }
    }
}
