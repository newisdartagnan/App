<?php
/**
 * Générateur PDF pour les comptes-rendus d'imagerie
 * 
 * Génère un PDF professionnel du compte-rendu d'examen d'imagerie
 * avec intégration possible des images
 */

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\Tcpdf\Fpdi as TcpdfFpdi;

// Vérifier si TCPDF est installé
$root_path = dirname(__DIR__);
$vendor_autoload = $root_path . '/vendor/autoload.php';
if (!file_exists($vendor_autoload)) {
    die('TCPDF non installé. Exécutez : composer require tecnickcom/tcpdf');
}
require_once $vendor_autoload;

if (!class_exists('TCPDF')) {
    $tcpdf_path = $root_path . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdf_path)) {
        require_once $tcpdf_path;
    } else {
        die('TCPDF non trouvé');
    }
}

/**
 * Classe PDF_Imagerie - Génération de PDF pour l'imagerie médicale
 */
class PDF_Imagerie extends TCPDF
{
    // En-tête du document
    public function Header()
    {
        // Logo
        $logo_path = __DIR__ . '/../assets/images/logo_CSK.jpg';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, 15, 10, 30, 0, 'JPG');
        }
        
        $this->SetY(12);
        $this->SetX(50);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(0, 86, 179);
        $this->Cell(0, 8, 'CLINIQUES SPECIALISEES DE KINSHASA', 0, 1, 'L');
        
        $this->SetX(50);
        $this->SetFont('helvetica', '', 10);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 5, 'Service d\'imagerie médicale', 0, 1, 'L');
        
        $this->Ln(8);
    }
    
    // Pied de page
    public function Footer()
    {
        $this->SetY(-20);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'Document généré le ' . date('d/m/Y à H:i'), 0, 0, 'C');
        $this->Ln(3);
        $this->SetFont('helvetica', 'B', 7);
        $this->SetTextColor(0, 86, 179);
        $this->Cell(0, 5, 'Cliniques Spécialisées de Kinshasa - Imagerie Médicale', 0, 0, 'C');
    }
    
    /**
     * Génère le compte-rendu complet
     */
    public function genererCompteRendu($examen, $patient, $fichiers = [])
    {
        $this->AddPage();
        
        // Titre
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(0, 86, 179);
        $this->Cell(0, 10, 'COMPTE RENDU D\'EXAMEN D\'IMAGERIE', 0, 1, 'C');
        $this->Ln(5);
        
        // Informations patient
        $this->SetFillColor(245, 245, 245);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(0, 86, 179);
        $this->Cell(0, 8, 'INFORMATIONS PATIENT', 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 10);
        
        $age = '';
        if (!empty($patient['date_naissance'])) {
            $dob = new DateTime($patient['date_naissance']);
            $today = new DateTime();
            $age = $dob->diff($today)->y . ' ans';
        }
        
        $html = '
        <table cellpadding="5">
            <tr>
                <td width="25%"><strong>Patient :</strong></td>
                <td width="75%">' . strtoupper($patient['nom'] . ' ' . $patient['prenom']) . '</td>
            </tr>
            <tr>
                <td width="25%"><strong>Sexe/Age :</strong></td>
                <td width="75%">' . ($patient['sexe'] === 'M' ? 'Masculin' : 'Féminin') . ' / ' . $age . '</td>
            </tr>
            <tr>
                <td width="25%"><strong>Code examen :</strong></td>
                <td width="75%">' . $examen['code_examen'] . '</td>
            </tr>
            <tr>
                <td width="25%"><strong>Date examen :</strong></td>
                <td width="75%">' . date('d/m/Y H:i', strtotime($examen['date_examen'] ?? $examen['date_rdv'] ?? 'now')) . '</td>
            </tr>
            <tr>
                <td width="25%"><strong>Type d\'examen :</strong></td>
                <td width="75%">' . ($examen['type_examen'] ?? '-') . '</td>
            </tr>
            <tr>
                <td width="25%"><strong>Prescripteur :</strong></td>
                <td width="75%">' . ($examen['prescripteur_nom'] ?? 'Non spécifié') . '</td>
            </tr>
        </table>
        ';
        
        $this->writeHTML($html, true, false, false, false, '');
        $this->Ln(5);
        
        // Résultats / CR
        $this->SetFillColor(245, 245, 245);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(0, 86, 179);
        $this->Cell(0, 8, 'COMPTE RENDU', 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
        
        // Technique
        if (!empty($examen['technique'])) {
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 6, 'Technique utilisée :', 0, 1, 'L');
            $this->SetFont('helvetica', '', 10);
            $this->MultiCell(0, 5, $examen['technique'], 0, 'L');
            $this->Ln(3);
        }
        
        // Description
        if (!empty($examen['description_resultats'])) {
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 6, 'Description et résultats :', 0, 1, 'L');
            $this->SetFont('helvetica', '', 10);
            $this->MultiCell(0, 5, $examen['description_resultats'], 0, 'L');
            $this->Ln(3);
        }
        
        // Conclusion
        if (!empty($examen['conclusion'])) {
            $this->SetFont('helvetica', 'B', 10);
            $this->SetTextColor(0, 100, 0);
            $this->Cell(0, 6, 'Conclusion :', 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('helvetica', '', 10);
            $this->MultiCell(0, 5, $examen['conclusion'], 0, 'L');
            $this->Ln(3);
        }
        
        // Recommandations
        if (!empty($examen['recommandations'])) {
            $this->SetFont('helvetica', 'B', 10);
            $this->Cell(0, 6, 'Recommandations :', 0, 1, 'L');
            $this->SetFont('helvetica', '', 10);
            $this->MultiCell(0, 5, $examen['recommandations'], 0, 'L');
            $this->Ln(3);
        }
        
        // Informations techniques
        $this->SetFillColor(245, 245, 245);
        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(0, 86, 179);
        $this->Cell(0, 8, 'INFORMATIONS TECHNIQUES', 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('helvetica', '', 9);
        
        $this->Cell(0, 5, 'Radiologue : ' . ($examen['radiologue_nom'] ?? 'Non spécifié'), 0, 1, 'L');
        if (!empty($examen['technicien_imagerie_nom'])) {
            $this->Cell(0, 5, 'Technicien : ' . $examen['technicien_imagerie_nom'], 0, 1, 'L');
        }
        $this->Cell(0, 5, 'Date de rédaction : ' . date('d/m/Y H:i'), 0, 1, 'L');
        
        // Liste des fichiers joints
        if (!empty($fichiers)) {
            $this->Ln(5);
            $this->SetFillColor(245, 245, 245);
            $this->SetFont('helvetica', 'B', 11);
            $this->SetTextColor(0, 86, 179);
            $this->Cell(0, 8, 'DOCUMENTS ANNEXES', 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
            
            foreach ($fichiers as $index => $f) {
                $this->SetFont('helvetica', 'B', 9);
                $this->Cell(0, 5, 'Document ' . ($index + 1) . ' : ' . $f['fichier_original'], 0, 1, 'L');
                if (!empty($f['description'])) {
                    $this->SetFont('helvetica', 'I', 8);
                    $this->Cell(0, 4, '  ' . $f['description'], 0, 1, 'L');
                }
                $this->SetFont('helvetica', '', 8);
                $this->Cell(0, 4, '  Ajouté le ' . date('d/m/Y H:i', strtotime($f['date_upload'])), 0, 1, 'L');
                $this->Ln(2);
            }
        }
    }
}

/**
 * Fonction utilitaire pour générer un PDF de compte-rendu
 * 
 * @param array $examen Données de l'examen
 * @param array $patient Données du patient
 * @param array $fichiers Liste des fichiers annexes
 * @return string Chemin du fichier PDF généré
 */
function generateCompteRenduPDF($examen, $patient, $fichiers = [])
{
    $pdf = new PDF_Imagerie('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetAutoPageBreak(true, 25);
    
    $pdf->genererCompteRendu($examen, $patient, $fichiers);
    
    // Sauvegarder le PDF
    $upload_dir = __DIR__ . '/../uploads/imagerie/pdf/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $filename = 'CR_' . $examen['code_examen'] . '_' . date('Ymd_His') . '.pdf';
    $filepath = $upload_dir . $filename;
    $pdf->Output($filepath, 'F');
    
    return $filepath;
}

/**
 * Génère un ZIP contenant tous les fichiers + CR pour gravure CD
 * 
 * @param array $examen Données de l'examen
 * @param array $patient Données du patient
 * @param array $fichiers Liste des fichiers
 * @param string $cr_path Chemin du fichier PDF du CR
 * @return string Chemin du fichier ZIP généré
 */
function generateGravureZIP($examen, $patient, $fichiers, $cr_path = '')
{
    $zip = new ZipArchive();
    $upload_dir = __DIR__ . '/../uploads/imagerie/zip/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $zip_filename = $upload_dir . 'CD_' . $examen['code_examen'] . '_' . date('Ymd_His') . '.zip';
    
    if ($zip->open($zip_filename, ZipArchive::CREATE) !== TRUE) {
        return false;
    }
    
    // Ajouter le CR
    if (!empty($cr_path) && file_exists($cr_path)) {
        $zip->addFile($cr_path, 'COMPTE_RENDU/' . basename($cr_path));
    }
    
    // Ajouter les fichiers
    foreach ($fichiers as $f) {
        if (file_exists($f['chemin_fichier'])) {
            $type_folder = match($f['type_fichier']) {
                'image' => 'IMAGES',
                'video' => 'VIDEOS',
                'dicom' => 'DICOM',
                'pdf' => 'DOCUMENTS',
                default => 'AUTRES'
            };
            $zip->addFile($f['chemin_fichier'], $type_folder . '/' . $f['fichier_original']);
        }
    }
    
    // Ajouter un fichier README.txt
    $readme_content = "CD D'ARCHIVAGE - EXAMEN D'IMAGERIE\n";
    $readme_content .= "================================\n\n";
    $readme_content .= "Patient : " . $patient['nom'] . ' ' . $patient['prenom'] . "\n";
    $readme_content .= "Code examen : " . $examen['code_examen'] . "\n";
    $readme_content .= "Date : " . date('d/m/Y H:i') . "\n";
    $readme_content .= "Type d'examen : " . ($examen['type_examen'] ?? 'Non spécifié') . "\n\n";
    $readme_content .= "Contenu du CD :\n";
    $readme_content .= "- COMPTE_RENDU/ : Compte-rendu médical en PDF\n";
    $readme_content .= "- IMAGES/ : Images de l'examen\n";
    $readme_content .= "- VIDEOS/ : Vidéos de l'examen\n";
    $readme_content .= "- DICOM/ : Fichiers DICOM\n";
    $readme_content .= "- DOCUMENTS/ : Documents annexes\n";
    
    $zip->addFromString('README.txt', $readme_content);
    
    $zip->close();
    return $zip_filename;
}

/**
 * Génère le HTML du compte-rendu (fallback si PDF non disponible)
 */
function generateCompteRenduHTML($examen, $patient, $fichiers = [])
{
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>CR - <?= htmlspecialchars($examen['code_examen']) ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .header { text-align: center; border-bottom: 2px solid #0056b3; padding-bottom: 10px; margin-bottom: 20px; }
            .header h1 { color: #0056b3; margin: 0; }
            .header p { color: #666; margin: 5px 0; }
            .patient-info { background: #f5f5f5; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
            .section { margin-bottom: 20px; }
            .section-title { background: #0056b3; color: white; padding: 8px 12px; border-radius: 5px; margin-bottom: 10px; }
            .content { padding: 10px; white-space: pre-wrap; }
            .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>CLINIQUES SPÉCIALISÉES DE KINSHASA</h1>
            <p>Service d'imagerie médicale</p>
            <p><strong>Code examen :</strong> <?= htmlspecialchars($examen['code_examen']) ?></p>
        </div>
        
        <div class="patient-info">
            <strong>Patient :</strong> <?= htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']) ?><br>
            <strong>Date examen :</strong> <?= date('d/m/Y H:i', strtotime($examen['date_examen'] ?? $examen['date_rdv'] ?? 'now')) ?><br>
            <strong>Type d'examen :</strong> <?= htmlspecialchars($examen['type_examen'] ?? '-') ?>
        </div>
        
        <?php if (!empty($examen['technique'])): ?>
        <div class="section">
            <div class="section-title">Technique utilisée</div>
            <div class="content"><?= nl2br(htmlspecialchars($examen['technique'])) ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($examen['description_resultats'])): ?>
        <div class="section">
            <div class="section-title">Description et résultats</div>
            <div class="content"><?= nl2br(htmlspecialchars($examen['description_resultats'])) ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($examen['conclusion'])): ?>
        <div class="section">
            <div class="section-title" style="background: #198754;">Conclusion</div>
            <div class="content"><?= nl2br(htmlspecialchars($examen['conclusion'])) ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($examen['recommandations'])): ?>
        <div class="section">
            <div class="section-title" style="background: #0dcaf0; color: #000;">Recommandations</div>
            <div class="content"><?= nl2br(htmlspecialchars($examen['recommandations'])) ?></div>
        </div>
        <?php endif; ?>
        
        <div class="footer">
            Document généré le <?= date('d/m/Y à H:i') ?><br>
            Cliniques Spécialisées de Kinshasa - Imagerie Médicale
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}