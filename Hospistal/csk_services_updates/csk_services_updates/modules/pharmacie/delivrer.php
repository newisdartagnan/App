<?php
/**
 * Module Pharmacie - Délivrance d'une prescription
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';
require_once __DIR__ . '/../../includes/notifications_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();
$conn_services = $db->getServicesConnection();

$idpharma_presc = $_GET['id'] ?? null;
$idofficine = $_GET['idofficine'] ?? null;

if (!$idpharma_presc || !$idofficine) {
    redirect('index.php?page=pharmacie&action=officine');
}

// =============================================
// INFOS PRESCRIPTION
// =============================================
$query = "SELECT 
            pp.*, 
            pr.libelle as produit_libelle, 
            pr.code as produit_code,
            pr.type_produit, 
            pr.forme,
            pr.prix_achat, 
            pr.prix_vente_externe,
            pr.idvoie_prod,
            pr.idunite,
            v.nom as voie_nom,
            u.nom as unite_nom,
            p.idpatient,
            p.nom as patient_nom, 
            p.prenom as patient_prenom, 
            p.numero_dossier,
            p.date_naissance, 
            p.sexe, 
            p.type_patient,
            soc.nom as societe_nom,
            cat.nom as categorie_nom,
            pres.nom as prescripteur_nom, 
            pres.prenom as prescripteur_prenom,
            pres.idutilisateur as id_prescripteur,
            s.idsejour, 
            s.type_sejour,
            ss.idsous_sejour,
            sp.quantite as stock_disponible
          FROM pharma_presc pp
          JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
          LEFT JOIN voie_prod v ON pr.idvoie_prod = v.idvoie_prod
          LEFT JOIN unite u ON pr.idunite = u.idunite
          JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
          JOIN sejour s ON ss.idsejour = s.idsejour
          JOIN patient p ON s.idpatient = p.idpatient
          LEFT JOIN societe soc ON p.idsociete = soc.idsociete
          LEFT JOIN categorie cat ON p.idcategorie = cat.idcategorie
          LEFT JOIN utilisateur pres ON pp.prescripteur = pres.idutilisateur
          LEFT JOIN stockpharma sp ON pr.idprodpharma = sp.idprodpharma AND sp.idofficine = :idofficine
          WHERE pp.idpharma_presc = :idpharma";

$stmt = $conn_base->prepare($query);
$stmt->execute([
    ':idpharma' => $idpharma_presc,
    ':idofficine' => $idofficine
]);
$prescription = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prescription) {
    redirect('index.php?page=pharmacie&action=officine');
}

// =============================================
// VÉRIFICATION STOCK
// =============================================
$stock_suffisant = ($prescription['stock_disponible'] ?? 0) >= $prescription['quantite'];

// =============================================
// TRAITEMENT DE LA DÉLIVRANCE
// =============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delivrer'])) {
    try {
        $conn_base->beginTransaction();
        $conn_services->beginTransaction();
        
        // 1. Vérifier le stock
        if (!$stock_suffisant) {
            throw new Exception("Stock insuffisant ! Disponible: {$prescription['stock_disponible']}, Demandé: {$prescription['quantite']}");
        }
        
        // 2. Mettre à jour le stock
        $query_stock = "UPDATE stockpharma 
                        SET quantite = quantite - :quantite,
                            date_derniere_maj = NOW()
                        WHERE idprodpharma = :idprod 
                        AND idofficine = :idofficine
                        AND quantite >= :quantite";
        $stmt_stock = $conn_base->prepare($query_stock);
        $stmt_stock->execute([
            ':quantite' => $prescription['quantite'],
            ':idprod' => $prescription['idprodpharma'],
            ':idofficine' => $idofficine
        ]);
        
        if ($stmt_stock->rowCount() === 0) {
            throw new Exception("Erreur lors de la mise à jour du stock");
        }
        
        // 3. Mettre à jour la prescription
        $query_update = "UPDATE pharma_presc 
                        SET statut_execution = 'acheve',
                            date_execution = NOW(),
                            date_delivrance = NOW(),
                            executeur = :executeur,
                            delivre_par = :executeur
                        WHERE idpharma_presc = :id";
        $stmt_update = $conn_base->prepare($query_update);
        $stmt_update->execute([
            ':executeur' => $_SESSION['user_id'],
            ':id' => $idpharma_presc
        ]);
        
        // 4. Créer ou mettre à jour l'entrée dans pharmacie_preparations
        // ✅ CORRECTION : Récupérer code_preparation avec l'idpreparation
        $stmt_check = $conn_services->prepare("
            SELECT idpreparation, code_preparation 
            FROM pharmacie_preparations 
            WHERE idpharma_presc = ?
        ");
        $stmt_check->execute([$idpharma_presc]);
        $existing_prep = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_prep) {
            // Mettre à jour la préparation existante
            $query_update_prep = "UPDATE pharmacie_preparations 
                                 SET statut = 'delivree',
                                     delivreur = :delivreur,
                                     date_delivrance = NOW(),
                                     quantite_preparee = :quantite,
                                     updated_at = NOW()
                                 WHERE idpreparation = :idprep";
            $stmt_update_prep = $conn_services->prepare($query_update_prep);
            $stmt_update_prep->execute([
                ':delivreur' => $_SESSION['user_id'],
                ':quantite' => $prescription['quantite'],
                ':idprep' => $existing_prep['idpreparation']
            ]);
            
            $idprep = $existing_prep['idpreparation'];
            $code_preparation = $existing_prep['code_preparation'];
        } else {
            // Créer une nouvelle préparation
            $code_preparation = 'DEL-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // ✅ VERSION CORRIGÉE - INSERT avec placeholders nommés
            $query_prep = "INSERT INTO pharmacie_preparations 
                           (code_preparation, idpharma_presc, idpatient, idsejour, idsous_sejour,
                            idprodpharma, posologie, quantite_preparee, unite_preparation,
                            forme_galenique, voie_administration, 
                            statut, urgence, delivreur, date_delivrance, created_at)
                           VALUES 
                           (:code, :idpharma, :idpatient, :idsejour, :idsous_sejour,
                            :idprod, :posologie, :quantite, :unite,
                            :forme, :voie,
                            'delivree', :urgence, :delivreur, NOW(), NOW())";
            
            $stmt_prep = $conn_services->prepare($query_prep);
            
            // ✅ Préparer les valeurs avec gestion des NULL
            $params_prep = [
                ':code' => $code_preparation,
                ':idpharma' => $idpharma_presc,
                ':idpatient' => $prescription['idpatient'],
                ':idsejour' => $prescription['idsejour'],
                ':idsous_sejour' => $prescription['idsous_sejour'],
                ':idprod' => $prescription['idprodpharma'],
                ':posologie' => $prescription['posologie'] ?: null,
                ':quantite' => $prescription['quantite'],
                ':unite' => $prescription['unite_nom'] ?: null,
                ':forme' => $prescription['forme'] ?: null,
                ':voie' => $prescription['voie_nom'] ?: null,
                ':urgence' => $prescription['urgent'] ? 1 : 0,
                ':delivreur' => $_SESSION['user_id']
            ];
            
            $stmt_prep->execute($params_prep);
            $idprep = $conn_services->lastInsertId();
        }
        
        // 5. Historique du workflow
        $query_histo = "INSERT INTO pharmacie_workflow_history 
                        (idpreparation, action, ancien_statut, nouveau_statut, idutilisateur, observation, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt_histo = $conn_services->prepare($query_histo);
        $stmt_histo->execute([
            $idprep,
            'Délivrance effectuée',
            'attente',
            'delivree',
            $_SESSION['user_id'],
            "Délivrance de {$prescription['quantite']} unités depuis l'officine #$idofficine"
        ]);
        
        // 6. NOTIFICATION AU PRESCRIPTEUR
        if (!empty($prescription['id_prescripteur'])) {
            $titre = "Prescription délivrée - Patient: " . $prescription['patient_prenom'] . " " . $prescription['patient_nom'];
            $message = "Le médicament " . $prescription['produit_libelle'] . " (" . $prescription['quantite'] . " " . ($prescription['unite_nom'] ?? 'unité(s)') . ") a été délivré au patient.";
            
            createNotification($conn_services, [
                'type' => 'prescription_delivree',
                'titre' => $titre,
                'message' => $message,
                'service' => 'pharmacie',
                'id_destinataire' => $prescription['id_prescripteur'],
                'lien' => 'index.php?page=prescriptions&id=' . $idpharma_presc,
                'priorite' => $prescription['urgent'] ? 'haute' : 'normale',
                'code_reference' => $code_preparation
            ]);
        }
        
        $conn_base->commit();
        $conn_services->commit();
        
        $success = "Délivrance effectuée avec succès ! Une notification a été envoyée au prescripteur.";
        
        header("refresh:2;url=index.php?page=pharmacie&action=traiter-prescription&sejour_id={$prescription['idsejour']}&idofficine={$idofficine}");
        
    } catch (Exception $e) {
        $conn_base->rollBack();
        $conn_services->rollBack();
        $error = "Erreur : " . $e->getMessage();
        error_log("[Pharmacie][Delivrance] Erreur: " . $e->getMessage());
    }
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-hand-thumbs-up me-2" style="color: #198754;"></i>
        Délivrance de médicament
    </h4>
    <a href="index.php?page=pharmacie&action=traiter-prescription&sejour_id=<?= $prescription['idsejour'] ?>&idofficine=<?= $idofficine ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Retour
    </a>
</div>

<!-- ========================================= -->
<!-- MESSAGES -->
<!-- ========================================= -->
<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle"></i> <?= $success ?>
        <br><small>Redirection automatique dans 2 secondes...</small>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($prescription['urgent']): ?>
<div class="alert alert-warning urgent-alert">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong>PRESCRIPTION URGENTE</strong> - À traiter en priorité
</div>
<?php endif; ?>

<!-- ========================================= -->
<!-- CARTE DE DÉLIVRANCE -->
<!-- ========================================= -->
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-prescription2 me-2"></i>Détails de la prescription</h6>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="detail-card">
                            <div class="detail-icon" style="background: #d1fae5;">
                                <i class="bi bi-person" style="color: #198754;"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Patient</div>
                                <div class="detail-value"><?= htmlspecialchars($prescription['patient_prenom'] . ' ' . $prescription['patient_nom']) ?></div>
                                <div class="detail-meta">N° <?= htmlspecialchars($prescription['numero_dossier']) ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="detail-card">
                            <div class="detail-icon" style="background: #dbeafe;">
                                <i class="bi bi-capsule" style="color: #2563eb;"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Produit</div>
                                <div class="detail-value"><?= htmlspecialchars($prescription['produit_libelle']) ?></div>
                                <div class="detail-meta"><?= htmlspecialchars($prescription['produit_code']) ?> | <?= htmlspecialchars($prescription['voie_nom'] ?? '') ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="detail-card">
                            <div class="detail-icon" style="background: #fee2e2;">
                                <i class="bi bi-hash" style="color: #dc2626;"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Quantité prescrite</div>
                                <div class="detail-value"><?= $prescription['quantite'] ?> <?= $prescription['unite_nom'] ?? '' ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="detail-card">
                            <div class="detail-icon" style="background: #fef3c7;">
                                <i class="bi bi-box" style="color: #f59e0b;"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Stock disponible</div>
                                <div class="detail-value <?= $stock_suffisant ? 'text-success' : 'text-danger' ?>">
                                    <?= $prescription['stock_disponible'] ?? 0 ?> <?= $prescription['unite_nom'] ?? '' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="detail-card">
                            <div class="detail-icon" style="background: #d1e7dd;">
                                <i class="bi bi-currency-dollar" style="color: #198754;"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Montant</div>
                                <div class="detail-value"><?= formatMoney($prescription['montant_total']) ?></div>
                                <div class="detail-meta"><?= formatMoney($prescription['prix_unitaire']) ?> / unité</div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($prescription['societe_nom'])): ?>
                    <div class="col-md-6">
                        <div class="detail-card">
                            <div class="detail-icon" style="background: #cff4fc;">
                                <i class="bi bi-building" style="color: #0dcaf0;"></i>
                            </div>
                            <div class="detail-content">
                                <div class="detail-label">Société</div>
                                <div class="detail-value"><?= htmlspecialchars($prescription['societe_nom']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <hr class="my-4">
                
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Posologie:</strong></p>
                        <div class="bg-light p-3 rounded">
                            <?= nl2br(htmlspecialchars($prescription['posologie'] ?? 'Non spécifiée')) ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Prescripteur:</strong> Dr. <?= htmlspecialchars($prescription['prescripteur_prenom'] . ' ' . $prescription['prescripteur_nom']) ?></p>
                        <p><strong>Date prescription:</strong> <?= formatDateTime($prescription['date_prescription']) ?></p>
                        <p><strong>Voie d'administration:</strong> <?= htmlspecialchars($prescription['voie_nom'] ?? 'Non spécifiée') ?></p>
                        <?php if (!empty($prescription['observation'])): ?>
                        <p><strong>Observation:</strong><br><?= nl2br(htmlspecialchars($prescription['observation'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-check-circle me-2"></i>Confirmation de délivrance</h6>
            </div>
            <div class="card-body">
                <?php if ($stock_suffisant): ?>
                    <form method="POST" onsubmit="return confirm('Confirmer la délivrance de ce médicament ?\
\
Cette action est irréversible et déduira <?= $prescription['quantite'] ?> unité(s) du stock.')">
                        <div class="alert alert-success">
                            <i class="bi bi-info-circle"></i>
                            <strong>Stock suffisant</strong><br>
                            <small>Vous allez délivrer <?= $prescription['quantite'] ?> <?= $prescription['unite_nom'] ?? '' ?> à <?= htmlspecialchars($prescription['patient_prenom'] . ' ' . $prescription['patient_nom']) ?></small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Observation (optionnelle)</label>
                            <textarea name="observation" class="form-control" rows="2" placeholder="Notes concernant la délivrance..."></textarea>
                        </div>
                        
                        <button type="submit" name="delivrer" class="btn btn-success w-100 btn-lg">
                            <i class="bi bi-check-circle"></i> Confirmer la délivrance
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Stock insuffisant !</strong>
                        <p class="mb-0 mt-2">Stock disponible: <?= $prescription['stock_disponible'] ?? 0 ?> <?= $prescription['unite_nom'] ?? '' ?></p>
                        <p class="mb-0">Quantité demandée: <?= $prescription['quantite'] ?> <?= $prescription['unite_nom'] ?? '' ?></p>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <a href="index.php?page=pharmacie&action=requisition&idofficine=<?= $idofficine ?>" class="btn btn-warning">
                            <i class="bi bi-file-import"></i> Faire une réquisition
                        </a>
                        <a href="index.php?page=pharmacie&action=traiter-prescription&sejour_id=<?= $prescription['idsejour'] ?>&idofficine=<?= $idofficine ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Retour
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Informations patient</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th>Âge</th>
                        <td><?= calculateAge($prescription['date_naissance']) ?> ans</td>
                    </tr>
                    <tr>
                        <th>Sexe</th>
                        <td><?= $prescription['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></td>
                    </tr>
                    <tr>
                        <th>Type</th>
                        <td>
                            <span class="badge <?= $prescription['type_patient'] === 'prive' ? 'bg-warning' : 'bg-success' ?>">
                                <?= $prescription['type_patient'] === 'prive' ? 'Privé' : 'Conventionné' ?>
                            </span>
                        </td>
                    </tr>
                    <?php if (!empty($prescription['societe_nom'])): ?>
                    <tr>
                        <th>Société</th>
                        <td><?= htmlspecialchars($prescription['societe_nom']) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Séjour</th>
                        <td><?= htmlspecialchars($prescription['type_sejour'] ?? '-') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.urgent-alert {
    animation: pulse 2s infinite;
    font-size: 1rem;
    font-weight: bold;
    margin-bottom: 20px;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

.detail-card {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: #f8fafc;
    border-radius: 8px;
}

.detail-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.detail-content {
    flex: 1;
}

.detail-label {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 2px;
}

.detail-value {
    font-size: 1.2rem;
    font-weight: 700;
    line-height: 1.2;
}

.detail-meta {
    font-size: 0.75rem;
    color: #6c757d;
}
</style>
",
      "path": "/mnt/user-data/outputs/delivrer_FINAL.php"
    },
    "message": "Version définitivement corrigée du fichier delivrer.php",
    "integration_name": null,
    "integration_icon_url": null,
    "icon_name": "file",
    "context": null,
    "display_content": {
      "type": "json_block",
      "json_block": "{"language": "php", "code": "<?php\
/**\
 * Module Pharmacie - Délivrance d'une prescription\
 */\
\
require_once __DIR__ . '/../../includes/pharmacie_helpers.php';\
require_once __DIR__ . '/../../includes/notifications_helpers.php';\
\
$db = new Database();\
$conn_base = $db->getBaseConnection();\
$conn_services = $db->getServicesConnection();\
\
$idpharma_presc = $_GET['id'] ?? null;\
$idofficine = $_GET['idofficine'] ?? null;\
\
if (!$idpharma_presc || !$idofficine) {\
    redirect('index.php?page=pharmacie&action=officine');\
}\
\
// =============================================\
// INFOS PRESCRIPTION\
// =============================================\
$query = \"SELECT \
            pp.*, \
            pr.libelle as produit_libelle, \
            pr.code as produit_code,\
            pr.type_produit, \
            pr.forme,\
            pr.prix_achat, \
            pr.prix_vente_externe,\
            pr.idvoie_prod,\
            pr.idunite,\
            v.nom as voie_nom,\
            u.nom as unite_nom,\
            p.idpatient,\
            p.nom as patient_nom, \
            p.prenom as patient_prenom, \
            p.numero_dossier,\
            p.date_naissance, \
            p.sexe, \
            p.type_patient,\
            soc.nom as societe_nom,\
            cat.nom as categorie_nom,\
            pres.nom as prescripteur_nom, \
            pres.prenom as prescripteur_prenom,\
            pres.idutilisateur as id_prescripteur,\
            s.idsejour, \
            s.type_sejour,\
            ss.idsous_sejour,\
            sp.quantite as stock_disponible\
          FROM pharma_presc pp\
          JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma\
          LEFT JOIN voie_prod v ON pr.idvoie_prod = v.idvoie_prod\
          LEFT JOIN unite u ON pr.idunite = u.idunite\
          JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour\
          JOIN sejour s ON ss.idsejour = s.idsejour\
          JOIN patient p ON s.idpatient = p.idpatient\
          LEFT JOIN societe soc ON p.idsociete = soc.idsociete\
          LEFT JOIN categorie cat ON p.idcategorie = cat.idcategorie\
          LEFT JOIN utilisateur pres ON pp.prescripteur = pres.idutilisateur\
          LEFT JOIN stockpharma sp ON pr.idprodpharma = sp.idprodpharma AND sp.idofficine = :idofficine\
          WHERE pp.idpharma_presc = :idpharma\";\
\
$stmt = $conn_base->prepare($query);\
$stmt->execute([\
    ':idpharma' => $idpharma_presc,\
    ':idofficine' => $idofficine\
]);\
$prescription = $stmt->fetch(PDO::FETCH_ASSOC);\
\
if (!$prescription) {\
    redirect('index.php?page=pharmacie&action=officine');\
}\
\
// =============================================\
// VÉRIFICATION STOCK\
// =============================================\
$stock_suffisant = ($prescription['stock_disponible'] ?? 0) >= $prescription['quantite'];\
\
// =============================================\
// TRAITEMENT DE LA DÉLIVRANCE\
// =============================================\
$success = '';\
$error = '';\
\
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delivrer'])) {\
    try {\
        $conn_base->beginTransaction();\
        $conn_services->beginTransaction();\
        \
        // 1. Vérifier le stock\
        if (!$stock_suffisant) {\
            throw new Exception(\"Stock insuffisant ! Disponible: {$prescription['stock_disponible']}, Demandé: {$prescription['quantite']}\");\
        }\
        \
        // 2. Mettre à jour le stock\
        $query_stock = \"UPDATE stockpharma \
                        SET quantite = quantite - :quantite,\
                            date_derniere_maj = NOW()\
                        WHERE idprodpharma = :idprod \
                        AND idofficine = :idofficine\
                        AND quantite >= :quantite\";\
        $stmt_stock = $conn_base->prepare($query_stock);\
        $stmt_stock->execute([\
            ':quantite' => $prescription['quantite'],\
            ':idprod' => $prescription['idprodpharma'],\
            ':idofficine' => $idofficine\
        ]);\
        \
        if ($stmt_stock->rowCount() === 0) {\
            throw new Exception(\"Erreur lors de la mise à jour du stock\");\
        }\
        \
        // 3. Mettre à jour la prescription\
        $query_update = \"UPDATE pharma_presc \
                        SET statut_execution = 'acheve',\
                            date_execution = NOW(),\
                            date_delivrance = NOW(),\
                            executeur = :executeur,\
                            delivre_par = :executeur\
                        WHERE idpharma_presc = :id\";\
        $stmt_update = $conn_base->prepare($query_update);\
        $stmt_update->execute([\
            ':executeur' => $_SESSION['user_id'],\
            ':id' => $idpharma_presc\
        ]);\
        \
        // 4. Créer ou mettre à jour l'entrée dans pharmacie_preparations\
        // ✅ CORRECTION : Récupérer code_preparation avec l'idpreparation\
        $stmt_check = $conn_services->prepare(\"\
            SELECT idpreparation, code_preparation \
            FROM pharmacie_preparations \
            WHERE idpharma_presc = ?\
        \");\
        $stmt_check->execute([$idpharma_presc]);\
        $existing_prep = $stmt_check->fetch(PDO::FETCH_ASSOC);\
        \
        if ($existing_prep) {\
            // Mettre à jour la préparation existante\
            $query_update_prep = \"UPDATE pharmacie_preparations \
                                 SET statut = 'delivree',\
                                     delivreur = :delivreur,\
                                     date_delivrance = NOW(),\
                                     quantite_preparee = :quantite,\
                                     updated_at = NOW()\
                                 WHERE idpreparation = :idprep\";\
            $stmt_update_prep = $conn_services->prepare($query_update_prep);\
            $stmt_update_prep->execute([\
                ':delivreur' => $_SESSION['user_id'],\
                ':quantite' => $prescription['quantite'],\
                ':idprep' => $existing_prep['idpreparation']\
            ]);\
            \
            $idprep = $existing_prep['idpreparation'];\
            $code_preparation = $existing_prep['code_preparation'];\
        } else {\
            // Créer une nouvelle préparation\
            $code_preparation = 'DEL-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);\
            \
            // ✅ VERSION CORRIGÉE - INSERT avec placeholders nommés\
            $query_prep = \"INSERT INTO pharmacie_preparations \
                           (code_preparation, idpharma_presc, idpatient, idsejour, idsous_sejour,\
                            idprodpharma, posologie, quantite_preparee, unite_preparation,\
                            forme_galenique, voie_administration, \
                            statut, urgence, delivreur, date_delivrance, created_at)\
                           VALUES \
                           (:code, :idpharma, :idpatient, :idsejour, :idsous_sejour,\
                            :idprod, :posologie, :quantite, :unite,\
                            :forme, :voie,\
                            'delivree', :urgence, :delivreur, NOW(), NOW())\";\
            \
            $stmt_prep = $conn_services->prepare($query_prep);\
            \
            // ✅ Préparer les valeurs avec gestion des NULL\
            $params_prep = [\
                ':code' => $code_preparation,\
                ':idpharma' => $idpharma_presc,\
                ':idpatient' => $prescription['idpatient'],\
                ':idsejour' => $prescription['idsejour'],\
                ':idsous_sejour' => $prescription['idsous_sejour'],\
                ':idprod' => $prescription['idprodpharma'],\
                ':posologie' => $prescription['posologie'] ?: null,\
                ':quantite' => $prescription['quantite'],\
                ':unite' => $prescription['unite_nom'] ?: null,\
                ':forme' => $prescription['forme'] ?: null,\
                ':voie' => $prescription['voie_nom'] ?: null,\
                ':urgence' => $prescription['urgent'] ? 1 : 0,\
                ':delivreur' => $_SESSION['user_id']\
            ];\
            \
            $stmt_prep->execute($params_prep);\
            $idprep = $conn_services->lastInsertId();\
        }\
        \
        // 5. Historique du workflow\
        $query_histo = \"INSERT INTO pharmacie_workflow_history \
                        (idpreparation, action, ancien_statut, nouveau_statut, idutilisateur, observation, created_at)\
                        VALUES (?, ?, ?, ?, ?, ?, NOW())\";\
        $stmt_histo = $conn_services->prepare($query_histo);\
        $stmt_histo->execute([\
            $idprep,\
            'Délivrance effectuée',\
            'attente',\
            'delivree',\
            $_SESSION['user_id'],\
            \"Délivrance de {$prescription['quantite']} unités depuis l'officine #$idofficine\"\
        ]);\
        \
        // 6. NOTIFICATION AU PRESCRIPTEUR\
        if (!empty($prescription['id_prescripteur'])) {\
            $titre = \"Prescription délivrée - Patient: \" . $prescription['patient_prenom'] . \" \" . $prescription['patient_nom'];\
            $message = \"Le médicament \" . $prescription['produit_libelle'] . \" (\" . $prescription['quantite'] . \" \" . ($prescription['unite_nom'] ?? 'unité(s)') . \") a été délivré au patient.\";\
            \
            createNotification($conn_services, [\
                'type' => 'prescription_delivree',\
                'titre' => $titre,\
                'message' => $message,\
                'service' => 'pharmacie',\
                'id_destinataire' => $prescription['id_prescripteur'],\
                'lien' => 'index.php?page=prescriptions&id=' . $idpharma_presc,\
                'priorite' => $prescription['urgent'] ? 'haute' : 'normale',\
                'code_reference' => $code_preparation\
            ]);\
        }\
        \
        $conn_base->commit();\
        $conn_services->commit();\
        \
        $success = \"Délivrance effectuée avec succès ! Une notification a été envoyée au prescripteur.\";\
        \
        header(\"refresh:2;url=index.php?page=pharmacie&action=traiter-prescription&sejour_id={$prescription['idsejour']}&idofficine={$idofficine}\");\
        \
    } catch (Exception $e) {\
        $conn_base->rollBack();\
        $conn_services->rollBack();\
        $error = \"Erreur : \" . $e->getMessage();\
        error_log(\"[Pharmacie][Delivrance] Erreur: \" . $e->getMessage());\
    }\
}\
?>\
\
<!-- ========================================= -->\
<!-- EN-TÊTE -->\
<!-- ========================================= -->\
<div class=\"d-flex justify-content-between align-items-center mb-4\">\
    <h4 class=\"mb-0\">\
        <i class=\"bi bi-hand-thumbs-up me-2\" style=\"color: #198754;\"></i>\
        Délivrance de médicament\
    </h4>\
    <a href=\"index.php?page=pharmacie&action=traiter-prescription&sejour_id=<?= $prescription['idsejour'] ?>&idofficine=<?= $idofficine ?>\" class=\"btn btn-outline-secondary btn-sm\">\
        <i class=\"bi bi-arrow-left\"></i> Retour\
    </a>\
</div>\
\
<!-- ========================================= -->\
<!-- MESSAGES -->\
<!-- ========================================= -->\
<?php if ($success): ?>\
    <div class=\"alert alert-success alert-dismissible fade show\">\
        <i class=\"bi bi-check-circle\"></i> <?= $success ?>\
        <br><small>Redirection automatique dans 2 secondes...</small>\
        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>\
    </div>\
<?php endif; ?>\
\
<?php if ($error): ?>\
    <div class=\"alert alert-danger alert-dismissible fade show\">\
        <i class=\"bi bi-exclamation-triangle\"></i> <?= $error ?>\
        <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>\
    </div>\
<?php endif; ?>\
\
<?php if ($prescription['urgent']): ?>\
<div class=\"alert alert-warning urgent-alert\">\
    <i class=\"bi bi-exclamation-triangle-fill\"></i>\
    <strong>PRESCRIPTION URGENTE</strong> - À traiter en priorité\
</div>\
<?php endif; ?>\
\
<!-- ========================================= -->\
<!-- CARTE DE DÉLIVRANCE -->\
<!-- ========================================= -->\
<div class=\"row g-4\">\
    <div class=\"col-lg-8\">\
        <div class=\"card border-0 shadow-sm\">\
            <div class=\"card-header bg-white\">\
                <h6 class=\"mb-0\"><i class=\"bi bi-prescription2 me-2\"></i>Détails de la prescription</h6>\
            </div>\
            <div class=\"card-body\">\
                <div class=\"row g-4\">\
                    <div class=\"col-md-6\">\
                        <div class=\"detail-card\">\
                            <div class=\"detail-icon\" style=\"background: #d1fae5;\">\
                                <i class=\"bi bi-person\" style=\"color: #198754;\"></i>\
                            </div>\
                            <div class=\"detail-content\">\
                                <div class=\"detail-label\">Patient</div>\
                                <div class=\"detail-value\"><?= htmlspecialchars($prescription['patient_prenom'] . ' ' . $prescription['patient_nom']) ?></div>\
                                <div class=\"detail-meta\">N° <?= htmlspecialchars($prescription['numero_dossier']) ?></div>\
                            </div>\
                        </div>\
                    </div>\
                    \
                    <div class=\"col-md-6\">\
                        <div class=\"detail-card\">\
                            <div class=\"detail-icon\" style=\"background: #dbeafe;\">\
                                <i class=\"bi bi-capsule\" style=\"color: #2563eb;\"></i>\
                            </div>\
                            <div class=\"detail-content\">\
                                <div class=\"detail-label\">Produit</div>\
                                <div class=\"detail-value\"><?= htmlspecialchars($prescription['produit_libelle']) ?></div>\
                                <div class=\"detail-meta\"><?= htmlspecialchars($prescription['produit_code']) ?> | <?= htmlspecialchars($prescription['voie_nom'] ?? '') ?></div>\
                            </div>\
                        </div>\
                    </div>\
                    \
                    <div class=\"col-md-4\">\
                        <div class=\"detail-card\">\
                            <div class=\"detail-icon\" style=\"background: #fee2e2;\">\
                                <i class=\"bi bi-hash\" style=\"color: #dc2626;\"></i>\
                            </div>\
                            <div class=\"detail-content\">\
                                <div class=\"detail-label\">Quantité prescrite</div>\
                                <div class=\"detail-value\"><?= $prescription['quantite'] ?> <?= $prescription['unite_nom'] ?? '' ?></div>\
                            </div>\
                        </div>\
                    </div>\
                    \
                    <div class=\"col-md-4\">\
                        <div class=\"detail-card\">\
                            <div class=\"detail-icon\" style=\"background: #fef3c7;\">\
                                <i class=\"bi bi-box\" style=\"color: #f59e0b;\"></i>\
                            </div>\
                            <div class=\"detail-content\">\
                                <div class=\"detail-label\">Stock disponible</div>\
                                <div class=\"detail-value <?= $stock_suffisant ? 'text-success' : 'text-danger' ?>\">\
                                    <?= $prescription['stock_disponible'] ?? 0 ?> <?= $prescription['unite_nom'] ?? '' ?>\
                                </div>\
                            </div>\
                        </div>\
                    </div>\
                    \
                    <div class=\"col-md-4\">\
                        <div class=\"detail-card\">\
                            <div class=\"detail-icon\" style=\"background: #d1e7dd;\">\
                                <i class=\"bi bi-currency-dollar\" style=\"color: #198754;\"></i>\
                            </div>\
                            <div class=\"detail-content\">\
                                <div class=\"detail-label\">Montant</div>\
                                <div class=\"detail-value\"><?= formatMoney($prescription['montant_total']) ?></div>\
                                <div class=\"detail-meta\"><?= formatMoney($prescription['prix_unitaire']) ?> / unité</div>\
                            </div>\
                        </div>\
                    </div>\
                    \
                    <?php if (!empty($prescription['societe_nom'])): ?>\
                    <div class=\"col-md-6\">\
                        <div class=\"detail-card\">\
                            <div class=\"detail-icon\" style=\"background: #cff4fc;\">\
                                <i class=\"bi bi-building\" style=\"color: #0dcaf0;\"></i>\
                            </div>\
                            <div class=\"detail-content\">\
                                <div class=\"detail-label\">Société</div>\
                                <div class=\"detail-value\"><?= htmlspecialchars($prescription['societe_nom']) ?></div>\
                            </div>\
                        </div>\
                    </div>\
                    <?php endif; ?>\
                </div>\
                \
                <hr class=\"my-4\">\
                \
                <div class=\"row\">\
                    <div class=\"col-md-6\">\
                        <p><strong>Posologie:</strong></p>\
                        <div class=\"bg-light p-3 rounded\">\
                            <?= nl2br(htmlspecialchars($prescription['posologie'] ?? 'Non spécifiée')) ?>\
                        </div>\
                    </div>\
                    <div class=\"col-md-6\">\
                        <p><strong>Prescripteur:</strong> Dr. <?= htmlspecialchars($prescription['prescripteur_prenom'] . ' ' . $prescription['prescripteur_nom']) ?></p>\
                        <p><strong>Date prescription:</strong> <?= formatDateTime($prescription['date_prescription']) ?></p>\
                        <p><strong>Voie d'administration:</strong> <?= htmlspecialchars($prescription['voie_nom'] ?? 'Non spécifiée') ?></p>\
                        <?php if (!empty($prescription['observation'])): ?>\
                        <p><strong>Observation:</strong><br><?= nl2br(htmlspecialchars($prescription['observation'])) ?></p>\
                        <?php endif; ?>\
                    </div>\
                </div>\
            </div>\
        </div>\
    </div>\
    \
    <div class=\"col-lg-4\">\
        <div class=\"card border-0 shadow-sm\">\
            <div class=\"card-header bg-white\">\
                <h6 class=\"mb-0\"><i class=\"bi bi-check-circle me-2\"></i>Confirmation de délivrance</h6>\
            </div>\
            <div class=\"card-body\">\
                <?php if ($stock_suffisant): ?>\
                    <form method=\"POST\" onsubmit=\"return confirm('Confirmer la délivrance de ce médicament ?\\
\\
Cette action est irréversible et déduira <?= $prescription['quantite'] ?> unité(s) du stock.')\">\
                        <div class=\"alert alert-success\">\
                            <i class=\"bi bi-info-circle\"></i>\
                            <strong>Stock suffisant</strong><br>\
                            <small>Vous allez délivrer <?= $prescription['quantite'] ?> <?= $prescription['unite_nom'] ?? '' ?> à <?= htmlspecialchars($prescription['patient_prenom'] . ' ' . $prescription['patient_nom']) ?></small>\
                        </div>\
                        \
                        <div class=\"mb-3\">\
                            <label class=\"form-label\">Observation (optionnelle)</label>\
                            <textarea name=\"observation\" class=\"form-control\" rows=\"2\" placeholder=\"Notes concernant la délivrance...\"></textarea>\
                        </div>\
                        \
                        <button type=\"submit\" name=\"delivrer\" class=\"btn btn-success w-100 btn-lg\">\
                            <i class=\"bi bi-check-circle\"></i> Confirmer la délivrance\
                        </button>\
                    </form>\
                <?php else: ?>\
                    <div class=\"alert alert-danger\">\
                        <i class=\"bi bi-exclamation-triangle\"></i>\
                        <strong>Stock insuffisant !</strong>\
                        <p class=\"mb-0 mt-2\">Stock disponible: <?= $prescription['stock_disponible'] ?? 0 ?> <?= $prescription['unite_nom'] ?? '' ?></p>\
                        <p class=\"mb-0\">Quantité demandée: <?= $prescription['quantite'] ?> <?= $prescription['unite_nom'] ?? '' ?></p>\
                    </div>\
                    \
                    <div class=\"d-grid gap-2\">\
                        <a href=\"index.php?page=pharmacie&action=requisition&idofficine=<?= $idofficine ?>\" class=\"btn btn-warning\">\
                            <i class=\"bi bi-file-import\"></i> Faire une réquisition\
                        </a>\
                        <a href=\"index.php?page=pharmacie&action=traiter-prescription&sejour_id=<?= $prescription['idsejour'] ?>&idofficine=<?= $idofficine ?>\" class=\"btn btn-outline-secondary\">\
                            <i class=\"bi bi-arrow-left\"></i> Retour\
                        </a>\
                    </div>\
                <?php endif; ?>\
            </div>\
        </div>\
        \
        <div class=\"card border-0 shadow-sm mt-3\">\
            <div class=\"card-header bg-white\">\
                <h6 class=\"mb-0\"><i class=\"bi bi-info-circle me-2\"></i>Informations patient</h6>\
            </div>\
            <div class=\"card-body\">\
                <table class=\"table table-sm\">\
                    <tr>\
                        <th>Âge</th>\
                        <td><?= calculateAge($prescription['date_naissance']) ?> ans</td>\
                    </tr>\
                    <tr>\
                        <th>Sexe</th>\
                        <td><?= $prescription['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></td>\
                    </tr>\
                    <tr>\
                        <th>Type</th>\
                        <td>\
                            <span class=\"badge <?= $prescription['type_patient'] === 'prive' ? 'bg-warning' : 'bg-success' ?>\">\
                                <?= $prescription['type_patient'] === 'prive' ? 'Privé' : 'Conventionné' ?>\
                            </span>\
                        </td>\
                    </tr>\
                    <?php if (!empty($prescription['societe_nom'])): ?>\
                    <tr>\
                        <th>Société</th>\
                        <td><?= htmlspecialchars($prescription['societe_nom']) ?></td>\
                    </tr>\
                    <?php endif; ?>\
                    <tr>\
                        <th>Séjour</th>\
                        <td><?= htmlspecialchars($prescription['type_sejour'] ?? '-') ?></td>\
                    </tr>\
                </table>\
            </div>\
        </div>\
    </div>\
</div>\
\
<style>\
.urgent-alert {\
    animation: pulse 2s infinite;\
    font-size: 1rem;\
    font-weight: bold;\
    margin-bottom: 20px;\
}\
\
@keyframes pulse {\
    0%, 100% { opacity: 1; }\
    50% { opacity: 0.8; }\
}\
\
.detail-card {\
    display: flex;\
    align-items: center;\
    gap: 15px;\
    padding: 15px;\
    background: #f8fafc;\
    border-radius: 8px;\
}\
\
.detail-icon {\
    width: 48px;\
    height: 48px;\
    border-radius: 12px;\
    display: flex;\
    align-items: center;\
    justify-content: center;\
    font-size: 1.5rem;\
}\
\
.detail-content {\
    flex: 1;\
}\
\
.detail-label {\
    font-size: 0.8rem;\
    color: #6c757d;\
    margin-bottom: 2px;\
}\
\
.detail-value {\
    font-size: 1.2rem;\
    font-weight: 700;\
    line-height: 1.2;\
}\
\
.detail-meta {\
    font-size: 0.75rem;\
    color: #6c757d;\
}\
</style>\
", "filename": "/mnt/user-data/outputs/delivrer_FINAL.php"}"
    },
    "approval_options": null,
    "approval_key": null,
    "is_mcp_app": null
  },
  {
    "start_timestamp": null,
    "stop_timestamp": null,
    "flags": null,
    "type": "tool_result",
    "tool_use_id": "toolu_01KLFwGtC1ewHKE757ZtETqs",
    "name": "create_file",
    "content": [
      {
        "type": "text",
        "text": "File created successfully: /mnt/user-data/outputs/delivrer_FINAL.php",
        "uuid": "5f6450f2-e9eb-4afc-9da1-939aeff433a2"
      }
    ],
    "is_error": false,
    "structured_content": null,
    "meta": null,
    "message": null,
    "integration_name": null,
    "integration_icon_url": null,
    "icon_name": "file",
    "display_content": {
      "type": "text",
      "text": "File created successfully: /mnt/user-data/outputs/delivrer_FINAL.php"
    }
  },
  {
    "start_timestamp": null,
    "stop_timestamp": null,
    "flags": null,
    "type": "token_budget