<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Les assets compilés, et le manifeste qui les nomme.
 *
 * Ils sont versionnés : un hôpital se déploie sans npm et souvent sans
 * réseau. Mais ils sont aussi réécrits à chaque build, si bien qu'une
 * fusion git peut semer des marqueurs de conflit dans `manifest.json`. Le
 * JSON devient illisible, et Laravel répond « Unable to locate file in
 * Vite manifest » sur TOUTES les pages — sans que rien, à l'écran, ne dise
 * où est le problème.
 *
 * C'est arrivé en salle. Le conteneur répare désormais tout seul au
 * démarrage ; ce test-ci attrape la même incohérence avant qu'elle ne
 * soit poussée.
 */
class AssetsCompilesTest extends TestCase
{
    /** Les points d'entrée déclarés dans vite.config.js. */
    private const ENTREES = ['resources/css/app.css', 'resources/js/app.js'];

    private function cheminDuManifeste(): string
    {
        return public_path('build/manifest.json');
    }

    public function test_le_manifeste_est_present_et_lisible(): void
    {
        $chemin = $this->cheminDuManifeste();

        $this->assertFileExists($chemin,
            'Les assets ne sont pas compilés : lancez « npm run build ».');

        $manifeste = json_decode((string) file_get_contents($chemin), true);

        $this->assertIsArray($manifeste,
            'Le manifeste n\'est pas du JSON valide — une fusion git y a '
            .'probablement laissé des marqueurs de conflit. '
            .'Réparez avec « git checkout -- public/build » puis « npm run build ».');
    }

    public function test_chaque_point_dentree_a_son_fichier_sur_le_disque(): void
    {
        $chemin = $this->cheminDuManifeste();
        $manifeste = json_decode((string) file_get_contents($chemin), true);

        foreach (self::ENTREES as $entree) {
            $this->assertArrayHasKey($entree, $manifeste,
                "Le manifeste ne connaît pas « {$entree} » : le build est incomplet.");

            $fichier = $manifeste[$entree]['file'] ?? null;

            $this->assertNotEmpty($fichier,
                "Le manifeste ne nomme aucun fichier pour « {$entree} ».");

            // Le manifeste peut être à jour et le fichier manquant : c'est
            // ce qui arrive quand un « git pull » n'a livré que la moitié
            // du dossier. La page tombe alors exactement de la même façon.
            $this->assertFileExists(public_path('build/'.$fichier),
                "Le manifeste désigne « {$fichier} » pour « {$entree} », "
                .'mais ce fichier n\'est pas là.');
        }
    }

    public function test_la_feuille_de_style_nest_pas_vide(): void
    {
        $manifeste = json_decode((string) file_get_contents($this->cheminDuManifeste()), true);
        $css = public_path('build/'.$manifeste['resources/css/app.css']['file']);

        // Un build interrompu laisse un fichier de zéro octet : les pages
        // répondent, mais sans une ligne de mise en forme.
        $this->assertGreaterThan(1024, filesize($css),
            'La feuille de style compilée est suspectement petite.');
    }
}
