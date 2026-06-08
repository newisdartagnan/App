<?php
/**
 * Module Prescriptions - Creation depuis csk_services
 * VERSION CORRIGÉE avec filtrage par service
 */

require_once __DIR__ . '/../includes/prescription_helpers.php';
require_once __DIR__ . '/../includes/notifications_helpers.php';

$db = new Database();
$conn_base     = $db->getBaseConnection();
$conn_services = $db->getServicesConnection();

// =============================================
// GESTION DES ACCÈS SELON LE PROFIL
// =============================================

// Définir les types de prescriptions autorisés pour l'utilisateur
$types_autorises = [];

if ($is_admin) {
    // Admin peut tout faire
    $types_autorises = ['labo', 'imagerie', 'pharmacie'];
} else {
    // Utilisateur simple : uniquement les services auxquels il a accès
    if ($has_labo) $types_autorises[] = 'labo';
    if ($has_imagerie) $types_autorises[] = 'imagerie';
    if ($has_pharmacie) $types_autorises[] = 'pharmacie';
}

// Si l'utilisateur n'a accès à aucun service, rediriger
if (empty($types_autorises)) {
    setFlash('error', 'Vous n\'avez pas accès à la création de prescriptions.');
    redirect('index.php?page=dashboard');
    exit();
}

// Pour le formulaire : vérifier que le type sélectionné est autorisé
$type_selected = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';

if (!empty($type_selected) && !in_array($type_selected, $types_autorises)) {
    setFlash('error', 'Vous n\'êtes pas autorisé à créer ce type de prescription.');
    redirect('index.php?page=prescriptions&sub=nouvelle');
    exit();
}

// Si un seul type est autorisé, le sélectionner automatiquement
if (count($types_autorises) === 1 && empty($type_selected)) {
    $type_selected = $types_autorises[0];
}

// Sous-action
$sub = isset($_GET['sub']) ? sanitizeInput($_GET['sub']) : 'liste';

// =============================================
// GÉNÉRATION TOKEN ANTI-DOUBLON
// =============================================
if (!isset($_SESSION['prescription_token'])) {
    $_SESSION['prescription_token'] = [];
}

// =============================================
// TRAITEMENT POST : CREATION DE PRESCRIPTION
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verification CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        setFlash('error', 'Token de securite invalide. Rechargez la page.');
        redirect('index.php?page=prescriptions&sub=nouvelle');
        exit();
    }
    
    // ✅ ANTI-DOUBLON : Vérifier le token unique de soumission
    $submission_token = $_POST['submission_token'] ?? '';
    if (empty($submission_token)) {
        setFlash('error', 'Token de soumission manquant.');
        redirect('index.php?page=prescriptions&sub=nouvelle');
        exit();
    }

    // Vérifier si ce token a déjà été utilisé
    if (in_array($submission_token, $_SESSION['prescription_token'])) {
        setFlash('error', 'Cette prescription a déjà été soumise. (Double soumission évitée)');
        redirect('index.php?page=prescriptions');
        exit();
    }

    // Stocker le token pour éviter les doubles soumissions
    $_SESSION['prescription_token'][] = $submission_token;
    // Nettoyer les vieux tokens (garder les 50 derniers)
    if (count($_SESSION['prescription_token']) > 50) {
        $_SESSION['prescription_token'] = array_slice($_SESSION['prescription_token'], -50);
    }

    $type_presc   = sanitizeInput($_POST['type_prescription'] ?? '');

    // Vérifier que le type est autorisé
    if (!in_array($type_presc, $types_autorises)) {
        setFlash('error', 'Vous n\'êtes pas autorisé à créer ce type de prescription.');
        redirect('index.php?page=prescriptions');
        exit();
    }

    $idpatient    = (int)($_POST['idpatient'] ?? 0);
    $idsous_sejour = (int)($_POST['idsous_sejour'] ?? 0);
    $urgence      = isset($_POST['urgence']) && $_POST['urgence'] == '1';
    $observation_generale = trim($_POST['observation'] ?? '');
    
    if ($idpatient <= 0 || $idsous_sejour <= 0) {
        setFlash('error', 'Patient et sejour sont obligatoires.');
        redirect('index.php?page=prescriptions&sub=nouvelle');
        exit();
    }
    
    // Récupérer tous les items
    $items = $_POST['items'] ?? [];
    
    if (empty($items)) {
        setFlash('error', 'Veuillez ajouter au moins un acte ou produit.');
        redirect('index.php?page=prescriptions&sub=nouvelle');
        exit();
    }
    
    $success_count = 0;
    $error_count = 0;
    $codes = [];
    $first_success = true;

    // ✅ Vérifier s'il existe déjà un groupe pour ce patient aujourd'hui
    $groupe_existant = null;
    if ($type_presc === 'labo') {
        $stmt = $conn_services->prepare("
            SELECT idgroupe, code_groupe 
            FROM labo_groupes_echantillons 
            WHERE idpatient = :idpatient 
            AND DATE(date_creation) = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([':idpatient' => $idpatient]);
        $groupe_existant = $stmt->fetch();
    }
    
    foreach ($items as $index => $item) {
        // Vérifier si cet acte n'a pas déjà été traité
        static $processed_actes = [];

        if ($type_presc === 'labo' || $type_presc === 'imagerie') {
            $idacte = (int)($item['idacte'] ?? 0);

            // Éviter les doublons d'actes
            if (in_array($idacte, $processed_actes)) {
                error_log("Acte $idacte déjà traité dans cette soumission, ignoré");
                continue;
            }
            $processed_actes[] = $idacte;

            if ($idacte <= 0) {
                $error_count++;
                error_log("Item $index: idacte manquant ou invalide");
                continue;
            }
            
            // ✅ NOUVEAU : Récupérer la planification pour imagerie
            $date_planification = null;
            $heure_planification = null;
            if ($type_presc === 'imagerie') {
                $date_planification = !empty($item['date_planification']) ? sanitizeInput($item['date_planification']) : null;
                $heure_planification = !empty($item['heure_planification']) ? sanitizeInput($item['heure_planification']) : null;
                
                // Validation : si l'un est rempli, l'autre doit l'être aussi
                if (($date_planification && !$heure_planification) || (!$date_planification && $heure_planification)) {
                    $error_count++;
                    error_log("Item $index: la date et l'heure doivent être toutes les deux remplies ou toutes les deux vides");
                    continue;
                }
            }

            // Préparer l'observation avec les champs spécifiques
            $observation_data = [
                'observation' => !empty($item['observation']) ? $item['observation'] : $observation_generale,
                'type_prelevement' => sanitizeInput($item['type_prelevement'] ?? 'sang_veineux'),
                'tube_type' => sanitizeInput($item['tube_type'] ?? 'vacutainer'),
                'couleur_tube' => sanitizeInput($item['couleur_tube'] ?? 'violet'),
                'volume_ml' => isset($item['volume_ml']) && $item['volume_ml'] !== '' ? (float)$item['volume_ml'] : null,
                'anticoagulant' => !empty($item['anticoagulant']) ? sanitizeInput($item['anticoagulant']) : null,
                'site_prelevement' => !empty($item['site_prelevement']) ? sanitizeInput($item['site_prelevement']) : null,
                'conditions_particulieres' => !empty($item['conditions_particulieres']) ? sanitizeInput($item['conditions_particulieres']) : null,
            ];
            
            $result = createActePrescription(
                $conn_base, $conn_services,
                $idpatient, $idsous_sejour, $idacte,
                $observation_data, $urgence,
                $user_id, $type_presc,
                $date_planification,
                $heure_planification
            );
            
        } elseif ($type_presc === 'pharmacie') {
            $idprodpharma = (int)($item['idprodpharma'] ?? 0);
            $quantite     = (int)($item['quantite'] ?? 1);
            $posologie    = trim($item['posologie'] ?? '');
            $observation_item = trim($item['observation'] ?? '');
            
            if ($idprodpharma <= 0 || $quantite <= 0) {
                $error_count++;
                error_log("Item $index: idprodpharma ou quantite invalide");
                continue;
            }
            
            $result = createPharmaPrescription(
                $conn_base, $conn_services,
                $idpatient, $idsous_sejour, $idprodpharma,
                $quantite, $posologie, $observation_item,
                $urgence, $user_id
            );
        }
        
        if (isset($result) && $result['success']) {
            $success_count++;
            // ✅ IMPORTANT : Ne pas dupliquer les codes de groupe
            if (!empty($result['code']) && !in_array($result['code'], $codes)) {
                $codes[] = $result['code'];
            }
            
            // Notification pour chaque item (seulement pour le premier pour éviter les spams)
            if ($first_success) {
                if ($type_presc === 'labo' || $type_presc === 'imagerie') {
                    $groupe = ($type_presc === 'labo') ? 'techniciens_labo' : 'manipulateurs_imagerie';
                    createNotification($conn_services, [
                        'type'     => 'prescription_entrant',
                        'titre'    => 'Nouvelles prescriptions ' . $type_presc,
                        'message'  => $success_count . ' prescription(s) créée(s) depuis Services par ' . htmlspecialchars($user_prenom . ' ' . $user_nom),
                        'service'  => $type_presc,
                        'groupe_destinataire' => $groupe,
                        'priorite' => $urgence ? 'haute' : 'normale',
                    ]);
                } elseif ($type_presc === 'pharmacie') {
                    createNotification($conn_services, [
                        'type'     => 'prescription_entrant',
                        'titre'    => 'Nouvelles prescriptions pharma',
                        'message'  => $success_count . ' prescription(s) créée(s) depuis Services par ' . htmlspecialchars($user_prenom . ' ' . $user_nom),
                        'service'  => 'pharmacie',
                        'groupe_destinataire' => 'pharmaciens',
                        'priorite' => $urgence ? 'haute' : 'normale',
                    ]);
                }
                $first_success = false;
            }
        } else {
            $error_count++;
            if (isset($result)) {
                error_log("Erreur item $index: " . ($result['message'] ?? 'inconnue'));
            }
        }
        
        unset($result); // Réinitialiser pour le prochain item
    }
    
    // Message de résultat
    if ($success_count > 0) {
        $message = "$success_count prescription(s) créée(s) avec succès.";
        if (!empty($codes)) {
            $message .= " Codes: " . implode(', ', $codes);
        }
        setFlash('success', $message);
    }
    if ($error_count > 0) {
        setFlash('error', "$error_count prescription(s) ont échoué.");
    }
    
    redirect('index.php?page=prescriptions');
    exit();
}

// =============================================
// CSRF token
// =============================================
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

// Générer un token unique pour cette soumission
$submission_token = uniqid('presc_', true);

// =============================================
// HISTORIQUE DES PRESCRIPTIONS RECENTES - FILTRÉES PAR SERVICE
// =============================================

// Prescriptions labo/imagerie (via actes_presc source='csk_services')
$presc_labo = [];
if ($has_labo || $has_imagerie || $is_admin) {
    try {
        // Construire la clause WHERE pour filtrer par catégorie d'actes
        $where_conditions = ["ap.source_prescription = 'csk_services'"];
        
        if (!$is_admin) {
            // Filtrer selon les services accessibles
            $categories_autorisees = [];
            if ($has_labo) $categories_autorisees = array_merge($categories_autorisees, CAT_LABO);
            if ($has_imagerie) $categories_autorisees = array_merge($categories_autorisees, CAT_IMAGERIE);
            
            if (!empty($categories_autorisees)) {
                $placeholders = implode(',', $categories_autorisees);
                $where_conditions[] = "a.idcategorie_acte IN ($placeholders)";
            }
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $stmt = $conn_base->prepare("
            SELECT ap.idactes_presc, ap.date_prescription, ap.urgent as urgence, ap.observation,
                   a.libelle as acte_nom, a.code as acte_code,
                   a.idcategorie_acte,
                   ca.nom as categorie_nom,
                   p.nom as patient_nom, p.prenom as patient_prenom, p.numero_dossier
            FROM actes_presc ap
            JOIN acte a ON ap.idacte = a.idacte
            JOIN categorie_acte ca ON a.idcategorie_acte = ca.idcategorie_acte
            JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
            JOIN sejour sj ON ss.idsejour = sj.idsejour
            JOIN patient p ON sj.idpatient = p.idpatient
            WHERE $where_clause
            ORDER BY ap.date_prescription DESC
            LIMIT 20
        ");
        $stmt->execute();
        $presc_labo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("[Prescriptions] Erreur chargement actes: " . $e->getMessage());
        $presc_labo = [];
    }
}

// Prescriptions pharma
$presc_pharma = [];
if ($has_pharmacie || $is_admin) {
    try {
        $stmt = $conn_base->prepare("
            SELECT pp.idpharma_presc, pp.date_prescription, pp.urgent as urgence, pp.quantite,
                   pp.posologie, pp.observation,
                   pr.libelle as produit_nom, pr.code as code_produit,
                   p.nom as patient_nom, p.prenom as patient_prenom, p.numero_dossier
            FROM pharma_presc pp
            JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
            JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
            JOIN sejour sj ON ss.idsejour = sj.idsejour
            JOIN patient p ON sj.idpatient = p.idpatient
            WHERE pp.source_prescription = 'csk_services'
            ORDER BY pp.date_prescription DESC
            LIMIT 20
        ");
        $stmt->execute();
        $presc_pharma = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("[Prescriptions] Erreur chargement pharma: " . $e->getMessage());
        $presc_pharma = [];
    }
}
?>

<?php if ($sub === 'nouvelle'): ?>
<!-- =========================================== -->
<!-- FORMULAIRE NOUVELLE PRESCRIPTION            -->
<!-- =========================================== -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-prescription2 me-2"></i>Nouvelle Prescription</h4>
    <a href="index.php?page=prescriptions" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Retour a la liste
    </a>
</div>

<!-- Etape 1 : Choix du type -->
<div class="card mb-4">
    <div class="card-header"><strong>1. Type de prescription</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <?php if (in_array('labo', $types_autorises)): ?>
            <div class="col-md-4">
                <a href="index.php?page=prescriptions&sub=nouvelle&type=labo" 
                   class="card text-center text-decoration-none h-100 <?= $type_selected === 'labo' ? 'border-primary border-2' : '' ?>"
                   style="cursor:pointer;">
                    <div class="card-body">
                        <i class="bi bi-droplet-fill fs-1 text-primary"></i>
                        <h5 class="mt-2">Laboratoire</h5>
                        <small class="text-muted">Analyses, biologie</small>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (in_array('imagerie', $types_autorises)): ?>
            <div class="col-md-4">
                <a href="index.php?page=prescriptions&sub=nouvelle&type=imagerie" 
                   class="card text-center text-decoration-none h-100 <?= $type_selected === 'imagerie' ? 'border-info border-2' : '' ?>"
                   style="cursor:pointer;">
                    <div class="card-body">
                        <i class="bi bi-image-fill fs-1 text-info"></i>
                        <h5 class="mt-2">Imagerie</h5>
                        <small class="text-muted">Radio, echo, scanner</small>
                    </div>
                </a>
            </div>
            <?php endif; ?>
            
            <?php if (in_array('pharmacie', $types_autorises)): ?>
            <div class="col-md-4">
                <a href="index.php?page=prescriptions&sub=nouvelle&type=pharmacie" 
                   class="card text-center text-decoration-none h-100 <?= $type_selected === 'pharmacie' ? 'border-success border-2' : '' ?>"
                   style="cursor:pointer;">
                    <div class="card-body">
                        <i class="bi bi-capsule fs-1 text-success"></i>
                        <h5 class="mt-2">Pharmacie</h5>
                        <small class="text-muted">Medicaments</small>
                    </div>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($type_selected)): ?>
<form method="POST" action="index.php?page=prescriptions" id="prescriptionForm">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="submission_token" value="<?= $submission_token ?>">
    <input type="hidden" name="type_prescription" value="<?= htmlspecialchars($type_selected) ?>">
    
    <!-- Etape 2 : Patient -->
    <div class="card mb-4">
        <div class="card-header"><strong>2. Patient</strong></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Rechercher un patient</label>
                <input type="text" class="form-control" id="searchPatient" 
                       placeholder="Nom, prenom ou N dossier..." autocomplete="off">
                <div id="patientResults" class="list-group mt-1" style="position:absolute; z-index:1050; max-height:300px; overflow-y:auto;"></div>
            </div>
            
            <div id="selectedPatientInfo" style="display:none;" class="alert alert-info mb-3">
                <input type="hidden" name="idpatient" id="idpatient" value="">
                <strong id="patientDisplayName"></strong>
                <span id="patientDossier" class="badge bg-secondary ms-2"></span>
            </div>
            
            <div id="sejourSection" style="display:none;" class="mb-3">
                <label class="form-label">Sejour actif</label>
                <select name="idsous_sejour" id="idsous_sejour" class="form-select" required>
                    <option value="">-- Selectionnez le sejour --</option>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Etape 3 : Contenu prescription -->
    <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>3. <?= $type_selected === 'pharmacie' ? 'Produits' : 'Actes' ?></strong>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">
            <i class="bi bi-plus-circle"></i> Ajouter un <?= $type_selected === 'pharmacie' ? 'produit' : 'acte' ?>
        </button>
    </div>
    <div class="card-body" id="itemsContainer">
        <!-- Premier item (obligatoire) -->
        <div class="item-row border rounded p-3 mb-3 position-relative" data-index="0">
            <button type="button" class="btn-close position-absolute top-0 end-0 mt-2 me-2" style="display:none;" onclick="removeItem(this)"></button>
            
            <?php if ($type_selected === 'labo' || $type_selected === 'imagerie'): ?>
                <?php $actes = getActesByCategorie($conn_base, $type_selected); ?>
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">Acte prescrit *</label>
                        <select name="items[0][idacte]" class="form-select" required>
                            <option value="">-- Sélectionnez l'acte --</option>
                            <?php
                            $current_cat = '';
                            foreach ($actes as $a):
                                if ($a['categorie_nom'] !== $current_cat):
                                    if ($current_cat !== '') echo '</optgroup>';
                                    $current_cat = $a['categorie_nom'];
                                    echo '<optgroup label="' . htmlspecialchars($current_cat) . '">';
                                endif;
                            ?>
                                <option value="<?= $a['idacte'] ?>">
                                    <?= htmlspecialchars($a['code'] . ' - ' . $a['libelle']) ?>
                                    (<?= number_format($a['prix_vente'] ?? 0, 0, ',', '.') ?> FC)
                                </option>
                            <?php endforeach; ?>
                            <?php if ($current_cat !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                </div>
                
                <!-- ✅ NOUVEAU : Section planification pour imagerie -->
                <?php if ($type_selected === 'imagerie'): ?>
                <div class="row g-3 mt-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-calendar me-1"></i> Date de l'examen
                        </label>
                        <input type="date" 
                            name="items[0][date_planification]" 
                            class="form-control"
                            min="<?= date('Y-m-d') ?>"
                            value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        <small class="text-muted">Laissez vide pour planifier plus tard</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-clock me-1"></i> Heure de l'examen
                        </label>
                        <select name="items[0][heure_planification]" class="form-select">
                            <option value="">-- À définir --</option>
                            <option value="08:00">08:00</option>
                            <option value="08:30">08:30</option>
                            <option value="09:00">09:00</option>
                            <option value="09:30">09:30</option>
                            <option value="10:00">10:00</option>
                            <option value="10:30">10:30</option>
                            <option value="11:00">11:00</option>
                            <option value="11:30">11:30</option>
                            <option value="12:00">12:00</option>
                            <option value="12:30">12:30</option>
                            <option value="14:00">14:00</option>
                            <option value="14:30">14:30</option>
                            <option value="15:00">15:00</option>
                            <option value="15:30">15:30</option>
                            <option value="16:00">16:00</option>
                            <option value="16:30">16:30</option>
                            <option value="17:00">17:00</option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($type_selected === 'labo'): ?>
                <!-- Champs spécifiques LABO pour cet acte -->
                <div class="row g-3 mt-2">
                    <div class="col-md-3">
                        <label class="form-label">Type de prélèvement *</label>
                        <select name="items[0][type_prelevement]" class="form-select" required>
                            <option value="sang_veineux">Sang veineux</option>
                            <option value="sang_capillaire">Sang capillaire</option>
                            <option value="sang_arteriel">Sang artériel</option>
                            <option value="urine">Urine</option>
                            <option value="selles">Selles</option>
                            <option value="lcr">LCR</option>
                            <option value="expectoration">Expectoration</option>
                            <option value="liquide_pleural">Liquide pleural</option>
                            <option value="liquide_ascite">Liquide d'ascite</option>
                            <option value="pus">Pus</option>
                            <option value="ecouvillonnage">Écouvillonnage</option>
                            <option value="biopsie">Biopsie</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Type de tube *</label>
                        <select name="items[0][tube_type]" class="form-select" required>
                            <option value="vacutainer">Vacutainer</option>
                            <option value="edta">EDTA</option>
                            <option value="serum">Sérum sec</option>
                            <option value="citrate">Citrate</option>
                            <option value="heparine">Héparinate</option>
                            <option value="fluorure">Fluorure/Oxalate</option>
                            <option value="flacon_sterile">Flacon stérile</option>
                            <option value="pot_urine">Pot à urine</option>
                            <option value="pot_selles">Pot à selles</option>
                            <option value="ecouvillon">Écouvillon</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Couleur du tube *</label>
                        <select name="items[0][couleur_tube]" class="form-select" required>
                            <option value="violet">🟣 Violet (EDTA)</option>
                            <option value="rouge">🔴 Rouge (Sérum)</option>
                            <option value="bleu">🔵 Bleu (Citrate)</option>
                            <option value="vert">🟢 Vert (Héparine)</option>
                            <option value="gris">⚪ Gris (Fluorure)</option>
                            <option value="jaune">🟡 Jaune (Gel séparateur)</option>
                            <option value="noir">⚫ Noir (VS)</option>
                            <option value="rose">🌸 Rose (EDTA)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Site de prélèvement</label>
                        <select name="items[0][site_prelevement]" class="form-select">
                            <option value="">-- Non spécifié --</option>
                            <option value="bras_droit">Bras droit</option>
                            <option value="bras_gauche">Bras gauche</option>
                            <option value="main_droite">Main droite</option>
                            <option value="main_gauche">Main gauche</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php elseif ($type_selected === 'pharmacie'): ?>
                <!-- ✅ INPUT HIDDEN OBLIGATOIRE -->
            <input type="hidden" name="items[0][idprodpharma]" id="hidden-idprodpharma-0" value="">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Rechercher un produit *</label>
                    <!-- ✅ AJOUT CRITIQUE : data-index="0" -->
                    <input type="text" 
                        class="form-control search-produit" 
                        placeholder="Nom du produit ou code..." 
                        autocomplete="off"
                        data-index="0">
                    <div class="produit-results list-group mt-1" 
                        style="position:absolute; z-index:1050; max-height:200px; overflow-y:auto;">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Quantité *</label>
                    <input type="number" name="items[0][quantite]" class="form-control" min="1" value="1" required>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Posologie</label>
                    <input type="text" name="items[0][posologie]" class="form-control" 
                        placeholder="ex: 2x/jour">
                </div>
            </div>
            
            <!-- Infos produit sélectionné -->
            <div class="mt-2" id="produit-info-0" style="display:none;">
                <div class="alert alert-success py-1 px-2 mb-0">
                    <small>
                        <strong>Sélectionné :</strong> 
                        <span class="produit-nom"></span> — 
                        <span class="badge bg-success">Stock: <span class="produit-stock"></span></span>
                    </small>
                </div>
            </div>
            
            <!-- Observation spécifique (optionnel) -->
            <div class="mt-2">
                <label class="form-label text-muted small">Observation pour ce produit (optionnel)</label>
                <input type="text" name="items[0][observation]" class="form-control form-control-sm" 
                    placeholder="Ex: À prendre le soir">
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Etape 4 : Options -->
    <div class="card mb-4">
        <div class="card-header"><strong>4. Options</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Observation / Motif clinique</label>
                    <textarea name="observation" class="form-control" rows="3" 
                              placeholder="Indication clinique, contexte..."></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Urgence</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="urgence" value="1" id="urgenceSwitch">
                        <label class="form-check-label" for="urgenceSwitch">
                            <i class="bi bi-exclamation-triangle text-danger"></i> Marquer comme urgent
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Soumettre -->
    <div class="text-end">
        <a href="index.php?page=prescriptions" class="btn btn-outline-secondary me-2">Annuler</a>
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-check-circle me-1"></i>Creer la prescription
        </button>
    </div>
</form>

<!-- JavaScript : recherche patient + produit via AJAX -->
<script>
// =============================================
// GESTION DES ITEMS MULTIPLES - VERSION CORRIGÉE
// =============================================
let itemCount = 1;

document.getElementById('addItemBtn').addEventListener('click', function() {
    const container = document.getElementById('itemsContainer');
    const template = document.querySelector('.item-row:first-child').cloneNode(true);
    
    // Réinitialiser complètement le template
    template.removeAttribute('id');
    template.classList.remove('border-primary');
    
    // Mettre à jour les indices
    template.setAttribute('data-index', itemCount);
    
    // ✅ Mettre à jour TOUS les attributs name et id avec le bon index
    template.querySelectorAll('[name], [id]').forEach(input => {
        if (input.hasAttribute('name')) {
            const name = input.getAttribute('name');
            const newName = name.replace(/\[\d+\]/, '[' + itemCount + ']');
            input.setAttribute('name', newName);
        }
        
        if (input.hasAttribute('id') && input.id.includes('-0')) {
            const newId = input.id.replace(/-0$/, '-' + itemCount);
            input.setAttribute('id', newId);
        }
    });
    
    // Réinitialiser les valeurs
    template.querySelectorAll('input, select, textarea').forEach(input => {
        if (input.type === 'hidden' && input.name && input.name.includes('idprodpharma')) {
            input.value = '';  // Vider l'idprodpharma
        } else if (input.type !== 'button' && input.type !== 'hidden') {
            if (input.tagName === 'SELECT') {
                input.selectedIndex = 0;
            } else if (input.type === 'checkbox') {
                input.checked = false;
            } else {
                input.value = input.type === 'number' ? '1' : '';
            }
        }
    });
    
    // Afficher le bouton de suppression
    const closeBtn = template.querySelector('.btn-close');
    if (closeBtn) {
        closeBtn.style.display = 'block';
    }
    
    // Cacher les infos produit
    const produitInfo = template.querySelector('[id^="produit-info-"]');
    if (produitInfo) {
        produitInfo.style.display = 'none';
        produitInfo.id = 'produit-info-' + itemCount;
    }
    
    // Réinitialiser les résultats de recherche
    const resultsDivs = template.querySelectorAll('.produit-results');
    resultsDivs.forEach(div => div.innerHTML = '');
    
    container.appendChild(template);
    
    // ✅ Réinitialiser les écouteurs pour la recherche de produits (SANS redéclarer)
    <?php if ($type_selected === 'pharmacie'): ?>
    const searchInputElement = template.querySelector('.search-produit');
    if (searchInputElement) {
        initProduitSearch(searchInputElement);
        console.log('✓ Recherche produit initialisée pour item', itemCount);
    }
    <?php endif; ?>
    
    itemCount++;
});

function removeItem(btn) {
    if (document.querySelectorAll('.item-row').length > 1) {
        btn.closest('.item-row').remove();
        console.log('✓ Item supprimé');
    }
}

// =============================================
// RECHERCHE PATIENT
// =============================================
let searchTimer = null;
const searchInput = document.getElementById('searchPatient');
const resultsDiv  = document.getElementById('patientResults');

if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        
        if (q.length < 2) { 
            resultsDiv.innerHTML = ''; 
            return; 
        }
        
        resultsDiv.innerHTML = '<span class="list-group-item text-muted"><i class="bi bi-hourglass-split"></i> Recherche en cours...</span>';
        
        searchTimer = setTimeout(() => {
            fetch('api/prescriptions.php?action=search_patients&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    resultsDiv.innerHTML = '';
                    
                    if (data && data.length > 0) {
                        data.forEach(p => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action';
                            
                            let nomComplet = p.nom || '';
                            if (p.prenom) nomComplet += ' ' + p.prenom;
                            if (p.postnom) nomComplet += ' (' + p.postnom + ')';
                            
                            let infos = '';
                            if (p.numero_dossier) infos += ' <span class="badge bg-secondary">' + p.numero_dossier + '</span>';
                            if (p.date_naissance) infos += ' <span class="badge bg-info">' + p.date_naissance + '</span>';
                            if (p.sexe) infos += ' <span class="badge bg-secondary">' + (p.sexe === 'M' ? 'H' : 'F') + '</span>';
                            
                            item.innerHTML = '<div><strong>' + nomComplet + '</strong>' + infos + '</div>';
                            
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                selectPatient(p);
                            });
                            resultsDiv.appendChild(item);
                        });
                    } else {
                        resultsDiv.innerHTML = '<span class="list-group-item text-muted">Aucun patient trouvé pour "' + q + '"</span>';
                    }
                })
                .catch(err => {
                    console.error('❌ Erreur recherche patients:', err);
                    resultsDiv.innerHTML = '<span class="list-group-item text-danger">Erreur de recherche</span>';
                });
        }, 300);
    });
}

function selectPatient(p) {
    document.getElementById('idpatient').value = p.idpatient;
    
    const displayName = document.getElementById('patientDisplayName');
    const displayDossier = document.getElementById('patientDossier');
    
    let nomComplet = p.nom || '';
    if (p.prenom) nomComplet += ' ' + p.prenom;
    if (p.postnom) nomComplet += ' (' + p.postnom + ')';
    
    displayName.textContent = nomComplet;
    displayDossier.textContent = p.numero_dossier || '';
    
    document.getElementById('selectedPatientInfo').style.display = 'block';
    
    resultsDiv.innerHTML = '';
    searchInput.value = nomComplet;
    
    loadPatientSejours(p.idpatient);
    console.log('✓ Patient sélectionné:', p.idpatient);
}

function loadPatientSejours(idpatient) {
    const select = document.getElementById('idsous_sejour');
    select.innerHTML = '<option value="">Chargement des séjours...</option>';
    document.getElementById('sejourSection').style.display = 'block';
    
    fetch('api/prescriptions.php?action=get_sejours&idpatient=' + idpatient)
        .then(r => r.json())
        .then(data => {
            select.innerHTML = '<option value="">-- Sélectionnez le séjour --</option>';
            
            if (data.sejours && data.sejours.length > 0) {
                data.sejours.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.idsous_sejour || s.idsejour;
                    
                    let texte = (s.service_nom || 'Service inconnu');
                    if (s.date_entree) texte += ' - Entrée: ' + new Date(s.date_entree).toLocaleDateString('fr-FR');
                    if (s.motif_admission) texte += ' (' + s.motif_admission + ')';
                    
                    opt.textContent = texte;
                    select.appendChild(opt);
                });
                
                if (data.sejours.length === 1) {
                    select.value = data.sejours[0].idsous_sejour || data.sejours[0].idsejour;
                }
                console.log('✓ Séjours chargés:', data.sejours.length);
            } else {
                select.innerHTML = '<option value="">Aucun séjour actif trouvé</option>';
            }
        })
        .catch(err => {
            console.error('❌ Erreur chargement séjours:', err);
            select.innerHTML = '<option value="">Erreur de chargement</option>';
        });
}

// =============================================
// RECHERCHE PRODUIT PHARMA
// =============================================
<?php if ($type_selected === 'pharmacie'): ?>
function initProduitSearch(inputElement) {
    if (!inputElement) return;
    
    let timer = null;
    const itemRow = inputElement.closest('.item-row');
    const index = itemRow.getAttribute('data-index');
    const resultsContainer = itemRow.querySelector('.produit-results');
    const produitInfo = document.getElementById('produit-info-' + index);
    
    console.log('✓ Init recherche produit pour index:', index);
    
    inputElement.addEventListener('input', function() {
        clearTimeout(timer);
        const q = this.value.trim();
        
        if (q.length < 2) { 
            resultsContainer.innerHTML = ''; 
            return; 
        }
        
        resultsContainer.innerHTML = '<span class="list-group-item text-muted"><i class="bi bi-hourglass-split"></i> Recherche...</span>';
        
        timer = setTimeout(() => {
            fetch('api/prescriptions.php?action=search_produits&q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    resultsContainer.innerHTML = '';
                    
                    if (data && data.length > 0) {
                        data.forEach(pr => {
                            const item = document.createElement('a');
                            item.href = '#';
                            item.className = 'list-group-item list-group-item-action';
                            
                            const stockClass = (pr.stock_actuel <= (pr.seuil_alerte || 0)) ? 'bg-danger' : 'bg-success';
                            
                            let produitNom = pr.libelle || '';
                            if (pr.forme) produitNom += ' (' + pr.forme + ')';
                            
                            let infos = '';
                            if (pr.code_produit) infos += ' <span class="badge bg-secondary">' + pr.code_produit + '</span>';
                            if (pr.stock_actuel !== undefined) infos += ' <span class="badge ' + stockClass + '">Stock: ' + pr.stock_actuel + '</span>';
                            
                            item.innerHTML = '<div><strong>' + produitNom + '</strong>' + infos + '</div>';
                            
                            item.addEventListener('click', function(e) {
                                e.preventDefault();
                                selectProduit(pr, inputElement, index);
                            });
                            resultsContainer.appendChild(item);
                        });
                    } else {
                        resultsContainer.innerHTML = '<span class="list-group-item text-muted">Aucun produit trouvé</span>';
                    }
                })
                .catch(err => {
                    console.error('❌ Erreur recherche produits:', err);
                    resultsContainer.innerHTML = '<span class="list-group-item text-danger">Erreur</span>';
                });
        }, 300);
    });
}

function selectProduit(pr, inputElement, index) {
    // ✅ Utiliser l'ID exact
    const hiddenInput = document.getElementById('hidden-idprodpharma-' + index);
    const produitInfo = document.getElementById('produit-info-' + index);
    
    if (!hiddenInput) {
        console.error('❌ Input hidden manquant pour index', index);
        alert('Erreur : champ idprodpharma introuvable pour cet item');
        return;
    }
    
    // Remplir l'input hidden
    hiddenInput.value = pr.idprodpharma;
    console.log('✓ Produit sélectionné:', pr.idprodpharma, 'pour index', index);
    console.log('✓ Hidden input value:', hiddenInput.value);
    
    // Afficher les infos
    let produitNom = pr.libelle || '';
    if (pr.forme) produitNom += ' (' + pr.forme + ')';
    
    produitInfo.querySelector('.produit-nom').textContent = produitNom;
    produitInfo.querySelector('.produit-stock').textContent = pr.stock_actuel || 0;
    produitInfo.style.display = 'block';
    
    // Vider les résultats et mettre à jour le champ texte
    const itemRow = inputElement.closest('.item-row');
    itemRow.querySelector('.produit-results').innerHTML = '';
    inputElement.value = produitNom;
}

// ✅ Initialiser la recherche pour le premier item au chargement
document.addEventListener('DOMContentLoaded', function() {
    const firstSearch = document.querySelector('.search-produit[data-index="0"]');
    if (firstSearch) {
        initProduitSearch(firstSearch);
        console.log('✓ Recherche produit initialisée pour item 0');
    }
});
<?php endif; ?>

// Fermer les résultats si clic ailleurs
document.addEventListener('click', function(e) {
    if (!e.target.closest('#searchPatient, #patientResults')) {
        if (resultsDiv) resultsDiv.innerHTML = '';
    }
    
    // Fermer aussi les résultats de recherche produits
    document.querySelectorAll('.produit-results').forEach(div => {
        if (!e.target.closest('.search-produit, .produit-results')) {
            div.innerHTML = '';
        }
    });
});

// ✅ Debug au submit pour vérifier les données
document.getElementById('prescriptionForm')?.addEventListener('submit', function(e) {
    <?php if ($type_selected === 'pharmacie'): ?>
    // Vérifier que tous les items ont un idprodpharma
    const items = document.querySelectorAll('.item-row');
    let hasError = false;
    
    items.forEach((item, idx) => {
        const index = item.getAttribute('data-index');
        const hiddenInput = document.getElementById('hidden-idprodpharma-' + index);
        const value = hiddenInput ? hiddenInput.value : null;
        
        console.log('Item', index, '- idprodpharma:', value);
        
        if (!value || value === '' || value === '0') {
            console.error('❌ Item', index, 'n\'a pas d\'idprodpharma valide');
            hasError = true;
        }
    });
    
    if (hasError) {
        e.preventDefault();
        alert('Erreur : veuillez sélectionner un produit valide pour chaque ligne');
        return false;
    }
    <?php endif; ?>
    
    console.log('✓ Formulaire validé, soumission en cours...');
});
</script>

<?php endif; // Fin du if (!empty($type_selected)) ?>

<?php else: // Sinon ($sub !== 'nouvelle') ?>
<!-- =========================================== -->
<!-- LISTE DES PRESCRIPTIONS RECENTES            -->
<!-- =========================================== -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-prescription2 me-2"></i>Prescriptions</h4>
    <a href="index.php?page=prescriptions&sub=nouvelle" class="btn btn-primary">
        <i class="bi bi-plus-circle me-1"></i>Nouvelle prescription
    </a>
</div>

<!-- Onglets selon services accessibles -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <?php if ($has_labo || $has_imagerie || $is_admin): ?>
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tabLaboPresc">
            <i class="bi bi-droplet-fill me-1 text-primary"></i>Labo / Imagerie
            <span class="badge bg-primary ms-1"><?= count($presc_labo) ?></span>
        </a>
    </li>
    <?php endif; ?>
    
    <?php if ($has_pharmacie || $is_admin): ?>
    <li class="nav-item">
        <a class="nav-link <?= !$has_labo && !$has_imagerie ? 'active' : '' ?>" data-bs-toggle="tab" href="#tabPharmaPresc">
            <i class="bi bi-capsule me-1 text-success"></i>Pharmacie
            <span class="badge bg-success ms-1"><?= count($presc_pharma) ?></span>
        </a>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content">
    <!-- Onglet Labo/Imagerie -->
    <?php if ($has_labo || $has_imagerie || $is_admin): ?>
    <div class="tab-pane fade show active" id="tabLaboPresc">
        <?php if (empty($presc_labo)): ?>
            <div class="alert alert-light text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                Aucune prescription labo/imagerie creee depuis cette application.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Patient</th>
                            <th>Categorie</th>
                            <th>Acte</th>
                            <th>Observation</th>
                            <th>Urgence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($presc_labo as $pl): ?>
                        <tr>
                            <td><small><?= date('d/m/Y H:i', strtotime($pl['date_prescription'])) ?></small></td>
                            <td>
                                <strong><?= htmlspecialchars($pl['patient_nom'] . ' ' . $pl['patient_prenom']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($pl['numero_dossier'] ?? '') ?></small>
                            </td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($pl['categorie_nom'] ?? '') ?></span></td>
                            <td>
                                <span class="badge bg-info"><?= htmlspecialchars($pl['acte_code']) ?></span>
                                <?= htmlspecialchars($pl['acte_nom']) ?>
                            </td>
                            <td><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($pl['observation'] ?? '-', 0, 50, '...')) ?></small></td>
                            <td>
                                <?php if ($pl['urgence']): ?>
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Urgent</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted">Normal</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Onglet Pharmacie -->
    <?php if ($has_pharmacie || $is_admin): ?>
    <div class="tab-pane fade <?= !$has_labo && !$has_imagerie ? 'show active' : '' ?>" id="tabPharmaPresc">
        <?php if (empty($presc_pharma)): ?>
            <div class="alert alert-light text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                Aucune prescription pharma creee depuis cette application.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Patient</th>
                            <th>Produit</th>
                            <th>Qte</th>
                            <th>Posologie</th>
                            <th>Urgence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($presc_pharma as $pp): ?>
                        <tr>
                            <td><small><?= date('d/m/Y H:i', strtotime($pp['date_prescription'])) ?></small></td>
                            <td>
                                <strong><?= htmlspecialchars($pp['patient_nom'] . ' ' . $pp['patient_prenom']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($pp['numero_dossier'] ?? '') ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= htmlspecialchars($pp['code_produit']) ?></span>
                                <?= htmlspecialchars($pp['produit_nom']) ?>
                            </td>
                            <td><strong><?= (int)$pp['quantite'] ?></strong></td>
                            <td><small><?= htmlspecialchars($pp['posologie'] ?? '-') ?></small></td>
                            <td>
                                <?php if ($pp['urgence']): ?>
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Urgent</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted">Normal</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endif; // Fin du if ($sub === 'nouvelle') ?>