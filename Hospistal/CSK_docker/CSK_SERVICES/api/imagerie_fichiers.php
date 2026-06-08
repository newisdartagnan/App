<?php
/**
 * API Imagerie Fichiers - Upload, suppression, generation PDF, ZIP pour gravure CD.
 *
 * Endpoints :
 *   POST  ?action=upload          -> Upload de fichiers (multipart)
 *   POST  ?action=delete          -> Suppression d'un fichier
 *   POST  ?action=save_cr         -> Sauvegarde du compte-rendu (form POST, redirect)
 *   GET   ?action=generate_pdf    -> Generation du PDF du CR
 *   GET   ?action=download_all    -> ZIP de tous les fichiers + CR pour gravure CD
 */

header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/imagerie_helpers.php';
require_once __DIR__ . '/../includes/pdf_generator.php';

session_start();

// Auth check
if (!isLoggedIn()) {
    http_response_code(401);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { die('Non authentifie.'); }
    echo json_encode(['success' => false, 'error' => 'Non authentifie.']);
    exit();
}

$user_id          = $_SESSION['user_id'];
$user_profil_code = $_SESSION['user_profil_code'] ?? '';
$user_services    = $_SESSION['services_autorises'] ?? [];

if (!in_array('imagerie', $user_services) && $user_profil_code !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acces refuse.']);
    exit();
}

try {
    $db = new Database();
    $conn_services = $db->getServicesConnection();
    $conn_base     = $db->getBaseConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur BDD.']);
    exit();
}

$action = sanitizeInput($_GET['action'] ?? '');

// Repertoire d'upload
define('IMAGERIE_UPLOADS_PATH', __DIR__ . '/../uploads/imagerie/fichiers');

// Extensions et types MIME autorises
$allowed_extensions = [
    // Images
    'jpg','jpeg','png','gif','bmp','tiff','tif','webp','svg',
    // Videos
    'mp4','avi','mov','wmv','mkv','webm','flv',
    // PDF
    'pdf',
    // DICOM
    'dcm','dicom',
];

$ext_to_type = [
    'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
    'bmp' => 'image', 'tiff' => 'image', 'tif' => 'image', 'webp' => 'image', 'svg' => 'image',
    'mp4' => 'video', 'avi' => 'video', 'mov' => 'video', 'wmv' => 'video',
    'mkv' => 'video', 'webm' => 'video', 'flv' => 'video',
    'pdf' => 'pdf',
    'dcm' => 'dicom', 'dicom' => 'dicom',
];

switch ($action) {
    case 'upload':
        handleUpload($conn_services);
        break;
    case 'delete':
        handleDelete($conn_services);
        break;
    case 'save_cr':
        handleSaveCR($conn_services);
        break;
    case 'generate_pdf':
        handleGeneratePDF($conn_services, $conn_base);
        break;
    case 'download_all':
        handleDownloadAll($conn_services, $conn_base);
        break;
    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Action non reconnue.']);
        break;
}
exit();

// =============================================
// UPLOAD
// =============================================
function handleUpload(PDO $conn) {
    global $user_id, $allowed_extensions, $ext_to_type;
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST requis.']);
        return;
    }

    $idexamen = (int)($_POST['idexamen'] ?? 0);
    if ($idexamen <= 0) {
        echo json_encode(['success' => false, 'error' => 'idexamen manquant.']);
        return;
    }

    // Verifier que l'examen existe
    $stmt = $conn->prepare("SELECT idexamen FROM imagerie_examens WHERE idexamen = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $idexamen]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Examen introuvable.']);
        return;
    }

    if (empty($_FILES['fichiers'])) {
        echo json_encode(['success' => false, 'error' => 'Aucun fichier recu.']);
        return;
    }

    // Creer le dossier d'upload
    $upload_dir = IMAGERIE_UPLOADS_PATH . '/' . $idexamen;
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Ordre max actuel
    $stmt_o = $conn->prepare("SELECT COALESCE(MAX(ordre), 0) FROM imagerie_fichiers WHERE idexamen = :id");
    $stmt_o->execute([':id' => $idexamen]);
    $ordre = (int)$stmt_o->fetchColumn();

    $uploaded = [];
    $errors = [];
    $files = $_FILES['fichiers'];

    $count = is_array($files['name']) ? count($files['name']) : 1;
    
    for ($i = 0; $i < $count; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
        $err  = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = "$name : erreur upload ($err)";
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            $errors[] = "$name : extension non autorisee ($ext)";
            continue;
        }

        // Taille max : 200 Mo
        if ($size > 200 * 1024 * 1024) {
            $errors[] = "$name : fichier trop volumineux (max 200 Mo)";
            continue;
        }

        $type_fichier = $ext_to_type[$ext] ?? 'image';
        $mime = mime_content_type($tmp) ?: 'application/octet-stream';
        $safe_name = time() . '_' . $i . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $dest = $upload_dir . '/' . $safe_name;

        if (move_uploaded_file($tmp, $dest)) {
            $ordre++;
            $chemin_rel = 'uploads/imagerie/fichiers/' . $idexamen . '/' . $safe_name;
            $stmt_ins = $conn->prepare("
                INSERT INTO imagerie_fichiers (idexamen, nom_fichier, chemin_fichier, type_fichier, mime_type, taille_octets, ordre, uploaded_by)
                VALUES (:idex, :nom, :chemin, :type, :mime, :taille, :ordre, :uid)
            ");
            $stmt_ins->execute([
                ':idex'   => $idexamen,
                ':nom'    => $name,
                ':chemin' => $chemin_rel,
                ':type'   => $type_fichier,
                ':mime'   => $mime,
                ':taille' => $size,
                ':ordre'  => $ordre,
                ':uid'    => $user_id,
            ]);
            $uploaded[] = $name;
        } else {
            $errors[] = "$name : echec du deplacement";
        }
    }

    echo json_encode([
        'success' => count($uploaded) > 0,
        'uploaded' => $uploaded,
        'errors'   => $errors,
        'message'  => count($uploaded) . ' fichier(s) uploade(s)' . (count($errors) > 0 ? ', ' . count($errors) . ' erreur(s)' : ''),
    ]);
}

// =============================================
// DELETE
// =============================================
function handleDelete(PDO $conn) {
    global $user_id;
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $idfichier = (int)($input['idfichier'] ?? 0);

    if ($idfichier <= 0) {
        echo json_encode(['success' => false, 'error' => 'idfichier manquant.']);
        return;
    }

    $stmt = $conn->prepare("SELECT * FROM imagerie_fichiers WHERE idfichier = :id");
    $stmt->execute([':id' => $idfichier]);
    $fichier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fichier) {
        echo json_encode(['success' => false, 'error' => 'Fichier introuvable.']);
        return;
    }

    // Supprimer le fichier physique
    $full_path = __DIR__ . '/../' . $fichier['chemin_fichier'];
    if (file_exists($full_path)) {
        @unlink($full_path);
    }

    // Supprimer l'enregistrement
    $stmt_d = $conn->prepare("DELETE FROM imagerie_fichiers WHERE idfichier = :id");
    $stmt_d->execute([':id' => $idfichier]);

    echo json_encode(['success' => true, 'message' => 'Fichier supprime.']);
}

// =============================================
// SAVE CR (form POST -> redirect)
// =============================================
function handleSaveCR(PDO $conn) {
    global $user_id;

    $code = sanitizeInput($_POST['code_examen'] ?? '');
    if (empty($code)) {
        setFlash('error', 'Code examen manquant.');
        redirect('index.php?page=imagerie&action=resultats');
        return;
    }

    $technique = trim($_POST['technique'] ?? '');
    $desc      = trim($_POST['description_resultats'] ?? '');
    $conclusion = trim($_POST['conclusion'] ?? '');
    $recommandations = trim($_POST['recommandations'] ?? '');

    try {
        $stmt = $conn->prepare("
            UPDATE imagerie_examens SET
                technique = :tech,
                description_resultats = :desc,
                conclusion = :concl,
                recommandations = :reco,
                date_compte_rendu = NOW(),
                updated_at = NOW()
            WHERE code_examen = :code AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':tech'  => $technique,
            ':desc'  => $desc,
            ':concl' => $conclusion,
            ':reco'  => $recommandations,
            ':code'  => $code,
        ]);

        // Si le statut est avant 'compte_rendu_en_cours', le passer a 'compte_rendu_en_cours'
        $stmt_s = $conn->prepare("SELECT statut, idexamen FROM imagerie_examens WHERE code_examen = :c");
        $stmt_s->execute([':c' => $code]);
        $ex = $stmt_s->fetch(PDO::FETCH_ASSOC);

        if ($ex && in_array($ex['statut'], ['en_cours', 'acquisition_faite'])) {
            $conn->prepare("UPDATE imagerie_examens SET statut = 'compte_rendu_en_cours' WHERE idexamen = :id")
                 ->execute([':id' => $ex['idexamen']]);

            // Log historique
            $conn->prepare("
                INSERT INTO imagerie_workflow_history (idexamen, action, ancien_statut, nouveau_statut, idutilisateur, observation)
                VALUES (:id, 'Saisie du compte-rendu', :ancien, 'compte_rendu_en_cours', :uid, 'Compte-rendu saisi/modifie')
            ")->execute([':id' => $ex['idexamen'], ':ancien' => $ex['statut'], ':uid' => $user_id]);
        }

        setFlash('success', 'Compte-rendu enregistre avec succes.');
    } catch (Exception $e) {
        error_log("[CSK Services] Erreur save_cr: " . $e->getMessage());
        setFlash('error', 'Erreur lors de l\'enregistrement du CR.');
    }

    redirect('index.php?page=imagerie&action=resultats&code=' . urlencode($code));
}

// =============================================
// GENERATE PDF
// =============================================
function handleGeneratePDF(PDO $conn_services, PDO $conn_base) {
    $code = sanitizeInput($_GET['code'] ?? '');
    if (empty($code)) { die('Code examen manquant.'); }

    // Charger l'examen
    $stmt = $conn_services->prepare("
        SELECT ie.*, eq.nom as equipement_nom,
               u_rad.nom as radiologue_nom, u_rad.prenom as radiologue_prenom
        FROM imagerie_examens ie
        LEFT JOIN csk_base.equipements_imagerie eq ON ie.equipement = eq.id
        LEFT JOIN csk_base.utilisateur u_rad ON ie.radiologue = u_rad.idutilisateur
        WHERE ie.code_examen = :code AND ie.deleted_at IS NULL
    ");
    $stmt->execute([':code' => $code]);
    $examen = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$examen) { die('Examen introuvable.'); }

    // Charger le patient
    $stmt_p = $conn_base->prepare("SELECT * FROM patient WHERE idpatient = :id");
    $stmt_p->execute([':id' => $examen['idpatient']]);
    $patient = $stmt_p->fetch(PDO::FETCH_ASSOC) ?: [];

    // Charger l'acte
    if (!empty($examen['idactes_presc'])) {
        $stmt_a = $conn_base->prepare("SELECT a.libelle FROM actes_presc ap JOIN acte a ON ap.idacte = a.idacte WHERE ap.idactes_presc = :id");
        $stmt_a->execute([':id' => $examen['idactes_presc']]);
        $acte = $stmt_a->fetch(PDO::FETCH_ASSOC);
        $examen['acte_libelle'] = $acte['libelle'] ?? '';
    }

    // Charger les fichiers images uniquement pour le PDF
    $stmt_f = $conn_services->prepare("SELECT * FROM imagerie_fichiers WHERE idexamen = :id AND type_fichier = 'image' ORDER BY ordre ASC");
    $stmt_f->execute([':id' => $examen['idexamen']]);
    $fichiers = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

    $pdf_path = generateCompteRenduPDF($examen, $patient, $fichiers);

    if ($pdf_path && file_exists($pdf_path)) {
        $ext = pathinfo($pdf_path, PATHINFO_EXTENSION);
        $mime = ($ext === 'pdf') ? 'application/pdf' : 'text/html';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="CR_' . $code . '.' . $ext . '"');
        header('Content-Length: ' . filesize($pdf_path));
        readfile($pdf_path);
    } else {
        // Fallback : generer le HTML directement
        echo generateCompteRenduHTML($examen, $patient, $fichiers);
    }
}

// =============================================
// DOWNLOAD ALL (ZIP pour gravure CD)
// =============================================
function handleDownloadAll(PDO $conn_services, PDO $conn_base) {
    $idexamen = (int)($_GET['idexamen'] ?? 0);
    if ($idexamen <= 0) { die('idexamen manquant.'); }

    // Charger l'examen
    $stmt = $conn_services->prepare("
        SELECT ie.*, eq.nom as equipement_nom,
               u_rad.nom as radiologue_nom, u_rad.prenom as radiologue_prenom
        FROM imagerie_examens ie
        LEFT JOIN csk_base.equipements_imagerie eq ON ie.idequipement = eq.idequipement
        LEFT JOIN csk_base.utilisateur u_rad ON ie.radiologue = u_rad.idutilisateur
        WHERE ie.idexamen = :id AND ie.deleted_at IS NULL
    ");
    $stmt->execute([':id' => $idexamen]);
    $examen = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$examen) { die('Examen introuvable.'); }

    // Patient
    $stmt_p = $conn_base->prepare("SELECT * FROM patient WHERE idpatient = :id");
    $stmt_p->execute([':id' => $examen['idpatient']]);
    $patient = $stmt_p->fetch(PDO::FETCH_ASSOC) ?: [];

    // Acte
    if (!empty($examen['idactes_presc'])) {
        $stmt_a = $conn_base->prepare("SELECT a.libelle FROM actes_presc ap JOIN acte a ON ap.idacte = a.idacte WHERE ap.idactes_presc = :id");
        $stmt_a->execute([':id' => $examen['idactes_presc']]);
        $acte = $stmt_a->fetch(PDO::FETCH_ASSOC);
        $examen['acte_libelle'] = $acte['libelle'] ?? '';
    }

    // Tous les fichiers
    $stmt_f = $conn_services->prepare("SELECT * FROM imagerie_fichiers WHERE idexamen = :id ORDER BY ordre ASC");
    $stmt_f->execute([':id' => $idexamen]);
    $fichiers = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

    // Generer le CR d'abord
    $cr_path = generateCompteRenduPDF($examen, $patient, 
        array_filter($fichiers, fn($f) => $f['type_fichier'] === 'image'));

    // Generer le ZIP
    $zip_path = generateGravureZIP($examen, $patient, $fichiers, $cr_path ?: '');

    if ($zip_path && file_exists($zip_path)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        // Nettoyer le ZIP apres envoi
        @unlink($zip_path);
    } else {
        die('Erreur lors de la generation du ZIP.');
    }
}