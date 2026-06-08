<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/PrescriptionMedicale.php'; // Ajouter cette inclusion
requireLogin();

$database = new Database();
$db = $database->getConnection();

$sejour_id = $_GET['sejour_id'] ?? null;
$sous_sejour_id = $_GET['sous_sejour_id'] ?? null;
$id_prescription = $_GET['id_prescription'] ?? null;
$type_prescription = $_GET['type'] ?? null;

// Si on a reçu seulement un sous-séjour → récupérer le séjour parent
if (!$sejour_id && $sous_sejour_id) {
    $query = "SELECT idsejour FROM sous_sejour WHERE idsous_sejour = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $sous_sejour_id);
    $stmt->execute();
    $row = $stmt->fetch();

    if ($row) {
        $sejour_id = $row['idsejour'];
    }
}

// Si on a une prescription spécifique, récupérer les infos
if ($id_prescription && $type_prescription) {
    if (in_array($type_prescription, ['laboratoire', 'imagerie', 'acte_medical'])) {
        $query = "SELECT ap.idsous_sejour, ss.idsejour, p.type_patient, p.idsociete
                  FROM actes_presc ap
                  JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                  JOIN sejour s ON ss.idsejour = s.idsejour
                  JOIN patient p ON s.idpatient = p.idpatient
                  WHERE ap.idactes_presc = :id";
    } else {
        $query = "SELECT pp.idsous_sejour, ss.idsejour, p.type_patient, p.idsociete
                  FROM pharma_presc pp
                  JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
                  JOIN sejour s ON ss.idsejour = s.idsejour
                  JOIN patient p ON s.idpatient = p.idpatient
                  WHERE pp.idpharma_presc = :id";
    }
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id_prescription);
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result) {
        $sous_sejour_id = $result['idsous_sejour'];
        $sejour_id = $result['idsejour'];
    }
}

// Récupérer les informations du séjour et du patient
if ($sejour_id) {
    $query = "SELECT s.*, p.*, c.nom as categorie_nom, soc.nom as societe_nom
              FROM sejour s
              JOIN patient p ON s.idpatient = p.idpatient
              LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
              LEFT JOIN societe soc ON p.idsociete = soc.idsociete
              WHERE s.idsejour = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $sejour_id);
    $stmt->execute();
    $sejourData = $stmt->fetch();
    
    if (!$sejourData) {
        redirect('facturation-prive.php');
    }
    
    // Récupérer le sous-séjour actif
    if (!$sous_sejour_id) {
        $query_ss = "SELECT idsous_sejour FROM sous_sejour 
                     WHERE idsejour = :idsejour AND statut = 'en_cours' 
                     ORDER BY date_entree DESC LIMIT 1";
        $stmt_ss = $db->prepare($query_ss);
        $stmt_ss->bindParam(':idsejour', $sejour_id);
        $stmt_ss->execute();
        $ss = $stmt_ss->fetch();
        $sous_sejour_id = $ss['idsous_sejour'];
    }
}

// Initialiser la classe PrescriptionMedicale
$prescriptionManager = new PrescriptionMedicale($db);

// Récupérer les actes non validés
$query_actes = "SELECT ap.*, a.libelle as acte_libelle, a.code as acte_code,
                       u.nom as prescripteur_nom, u.prenom as prescripteur_prenom,
                       sp.nom as specialite_nom
                FROM actes_presc ap
                JOIN acte a ON ap.idacte = a.idacte
                LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                LEFT JOIN specialite sp ON ap.idspecialite = sp.idspecialite
                WHERE ap.idsous_sejour = :idsous_sejour
                AND ap.statut_validation = 'rien'
                ORDER BY ap.date_prescription DESC";

$stmt_actes = $db->prepare($query_actes);
$stmt_actes->bindParam(':idsous_sejour', $sous_sejour_id);
$stmt_actes->execute();
$actes = $stmt_actes->fetchAll();

// Récupérer les prescriptions pharmacie non validées
$query_pharma = "SELECT pp.*, pr.libelle as produit_libelle, pr.code as produit_code,
                        u.nom as prescripteur_nom, u.prenom as prescripteur_prenom
                 FROM pharma_presc pp
                 JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
                 LEFT JOIN utilisateur u ON pp.prescripteur = u.idutilisateur
                 WHERE pp.idsous_sejour = :idsous_sejour
                 AND pp.statut_validation = 'rien'
                 ORDER BY pp.date_prescription DESC";

$stmt_pharma = $db->prepare($query_pharma);
$stmt_pharma->bindParam(':idsous_sejour', $sous_sejour_id);
$stmt_pharma->execute();
$pharma_presc = $stmt_pharma->fetchAll();

// Récupérer les actes déjà validés
$query_valides = "SELECT ap.*, a.libelle as acte_libelle, a.code as acte_code,
                         u.nom as valideur_nom
                  FROM actes_presc ap
                  JOIN acte a ON ap.idacte = a.idacte
                  LEFT JOIN utilisateur u ON ap.valideur = u.idutilisateur
                  WHERE ap.idsous_sejour = :idsous_sejour
                  AND ap.statut_validation = 'valide'
                  ORDER BY ap.date_validation DESC";

$stmt_valides = $db->prepare($query_valides);
$stmt_valides->bindParam(':idsous_sejour', $sous_sejour_id);
$stmt_valides->execute();
$actes_valides = $stmt_valides->fetchAll();

// Traitement de la validation
$success = '';
$error = '';

// Traitement de la validation FINANCIÈRE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['valider'])) {
    $actes_selectionnes = $_POST['actes'] ?? [];
    $pharma_selectionnes = $_POST['pharma_presc'] ?? [];
    $type_prescriptions = $_POST['type_prescription'] ?? [];
    $mode_paiement = $_POST['mode_paiement'] ?? 'rien';
    $montant_percu = floatval($_POST['montant_percu'] ?? 0);
    $montant_reduction = floatval($_POST['montant_reduction'] ?? 0);
    $type_reduction = $_POST['type_reduction'] ?? '';
    
    if (empty($actes_selectionnes) && empty($pharma_selectionnes)) {
        $error = "Veuillez sélectionner au moins un acte ou prescription à valider.";
    } else {
        try {
            $db->beginTransaction();
            
            $montant_total = 0;
            $prescription_ids = [];
            
            // Valider les actes médicaux
            foreach ($actes_selectionnes as $index => $id_prescription) {
                $type = $type_prescriptions[$index] ?? 'acte_medical';
                
                // Validation financière
                $prescriptionManager->validerPrescriptionFinancierement(
                    $id_prescription,
                    $type,
                    $mode_paiement,
                    0, // Le montant est déjà dans la prescription
                    $_SESSION['user_id']
                );
                
                // Calculer le montant total
                $query_montant = "SELECT montant_total FROM actes_presc WHERE idactes_presc = :id";
                $stmt_montant = $db->prepare($query_montant);
                $stmt_montant->bindParam(':id', $id_prescription);
                $stmt_montant->execute();
                $row = $stmt_montant->fetch();
                $montant_total += $row['montant_total'];
                
                $prescription_ids[] = ['id' => $id_prescription, 'type' => $type];
            }
            
            // Valider les prescriptions pharmacie
            foreach ($pharma_selectionnes as $id_pharma) {
                $prescriptionManager->validerPrescriptionFinancierement(
                    $id_pharma,
                    'pharmacie',
                    $mode_paiement,
                    0,
                    $_SESSION['user_id']
                );
                
                $query_montant = "SELECT montant_total FROM pharma_presc WHERE idpharma_presc = :id";
                $stmt_montant = $db->prepare($query_montant);
                $stmt_montant->bindParam(':id', $id_pharma);
                $stmt_montant->execute();
                $row = $stmt_montant->fetch();
                $montant_total += $row['montant_total'];
                
                $prescription_ids[] = ['id' => $id_pharma, 'type' => 'pharmacie'];
            }
            
            // Créer la transaction pour les paiements cash/cc
            if (in_array($mode_paiement, ['cash', 'cc'])) {
                $montant_net = $montant_total - $montant_reduction;
                
                $query_transact = "INSERT INTO caisse_transact 
                    (idpatient, idcaisse_typetransact, montant_fc, devise, 
                     mode_paiement, idutilisateur, reference_paiement, observation)
                    VALUES 
                    (:idpatient, 1, :montant, 'FC', :mode_paiement, :idutilisateur, 
                     :reference, :observation)";
                
                $reference = 'VAL-' . $sejour_id . '-' . date('Ymd-His');
                $observation = $montant_reduction > 0 ? "Réduction {$type_reduction}: " . formatMoney($montant_reduction) : '';
                
                $stmt_transact = $db->prepare($query_transact);
                $stmt_transact->bindParam(':idpatient', $sejourData['idpatient']);
                $stmt_transact->bindParam(':montant', $montant_net);
                $stmt_transact->bindParam(':mode_paiement', $mode_paiement);
                $stmt_transact->bindParam(':idutilisateur', $_SESSION['user_id']);
                $stmt_transact->bindParam(':reference', $reference);
                $stmt_transact->bindParam(':observation', $observation);
                $stmt_transact->execute();
            }
            
            // Pour les conventionnés, créer une facture société
            if ($sejourData['type_patient'] === 'conventionne' && $sejourData['idsociete']) {
                // Récupérer le taux de couverture de la société
                $query_taux = "SELECT taux_couverture FROM societe_tarif 
                              WHERE idsociete = :idsociete 
                              LIMIT 1";
                $stmt_taux = $db->prepare($query_taux);
                $stmt_taux->bindParam(':idsociete', $sejourData['idsociete']);
                $stmt_taux->execute();
                $taux = $stmt_taux->fetch();
                $taux_couverture = $taux['taux_couverture'] ?? 80;
                
                // Calculer les parts
                $part_societe = $montant_total * $taux_couverture / 100;
                $part_patient = $montant_total - $part_societe;
                
                $query_facture = "INSERT INTO facture_societe 
                    (idsociete, idsejour, montant_total, part_societe, part_patient, 
                     taux_couverture, statut, date_facturation)
                    VALUES 
                    (:idsociete, :idsejour, :montant_total, :part_societe, :part_patient,
                     :taux_couverture, 'en_attente', NOW())";
                
                $stmt_facture = $db->prepare($query_facture);
                $stmt_facture->execute([
                    ':idsociete' => $sejourData['idsociete'],
                    ':idsejour' => $sejour_id,
                    ':montant_total' => $montant_total,
                    ':part_societe' => $part_societe,
                    ':part_patient' => $part_patient,
                    ':taux_couverture' => $taux_couverture
                ]);
            }
            
            $db->commit();
            
            $total_items = count($actes_selectionnes) + count($pharma_selectionnes);
            $change = $montant_percu - ($montant_total - $montant_reduction);
            
            $success = $total_items . " prescription(s) validée(s) financièrement !<br>";
            $success .= "Montant total: <strong>" . formatMoney($montant_total) . "</strong><br>";
            
            if ($montant_reduction > 0) {
                $success .= "Réduction appliquée: <strong>" . formatMoney($montant_reduction) . "</strong><br>";
            }
            
            if ($change > 0 && $mode_paiement === 'cash') {
                $success .= "Monnaie à rendre: <strong>" . formatMoney($change) . "</strong>";
            }
            
            // Recharger les données
            $stmt_actes->execute();
            $actes = $stmt_actes->fetchAll();
            
            $stmt_pharma->execute();
            $pharma_presc = $stmt_pharma->fetchAll();
            
            $stmt_valides->execute();
            $actes_valides = $stmt_valides->fetchAll();
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la validation : " . $e->getMessage();
        }
    }
}

$pageTitle = "Validation et Paiement - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> Validation et Paiement</h1>
    <a href="facturation-prive.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour à la liste
    </a>
</div>

<!-- Informations patient -->
<div class="patient-info-card">
    <div class="patient-info-header">
        <h3><?php echo htmlspecialchars($sejourData['nom'] . ' ' . $sejourData['prenom']); ?></h3>
        <span class="badge <?php echo $sejourData['type_patient'] === 'prive' ? 'badge-warning' : 'badge-success'; ?>">
            <?php echo $sejourData['type_patient'] === 'prive' ? 'Privé' : 'Conventionné'; ?>
        </span>
    </div>
    <div class="patient-info-details">
        <div class="info-item">
            <strong>N° Dossier:</strong> <?php echo htmlspecialchars($sejourData['numero_dossier']); ?>
        </div>
        <div class="info-item">
            <strong>Date naissance:</strong> <?php echo formatDate($sejourData['date_naissance']); ?>
        </div>
        <div class="info-item">
            <strong>Catégorie:</strong> <?php echo htmlspecialchars($sejourData['categorie_nom'] ?? '-'); ?>
        </div>
        <?php if ($sejourData['type_patient'] === 'conventionne'): ?>
        <div class="info-item">
            <strong>Société:</strong> <?php echo htmlspecialchars($sejourData['societe_nom'] ?? '-'); ?>
        </div>
        <?php endif; ?>
        <div class="info-item">
            <strong>Sous-séjour:</strong> #<?php echo $sous_sejour_id; ?>
        </div>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
<?php endif; ?>

<!-- Actes à valider -->
<?php if (!empty($actes) || !empty($pharma_presc)): ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-list-check"></i> Actes et Prescriptions à Valider</h3>
    </div>
    <div class="card-body">
        <form method="POST" id="validationForm">
            
            <!-- Actes médicaux -->
            <?php if (!empty($actes)): ?>
            <div class="section-title">
                <h4><i class="fas fa-stethoscope"></i> Actes Médicaux</h4>
            </div>
            <div class="table-container">
                <table id="actesTable">
                    <thead>
                        <tr>
                            <th width="50">
                                <input type="checkbox" id="selectAllActes" onchange="toggleSelectAll('acte')">
                            </th>
                            <th>Date</th>
                            <th>Acte</th>
                            <th>Spécialité</th>
                            <th>Prescripteur</th>
                            <th>Qté</th>
                            <th>Prix Unit.</th>
                            <th>Total</th>
                            <th>Urgent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($actes as $acte): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="actes[]" value="<?php echo $acte['idactes_presc']; ?>" 
                                       class="acte-checkbox item-checkbox" onchange="calculateTotal()">
                                <input type="hidden" name="type_prescription[]" value="acte_medical">
                            </td>
                            <td><?php echo formatDateTime($acte['date_prescription']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($acte['acte_libelle']); ?></strong><br>
                                <small><?php echo htmlspecialchars($acte['acte_code']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($acte['specialite_nom'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($acte['prescripteur_prenom'] . ' ' . $acte['prescripteur_nom']); ?></td>
                            <td><?php echo $acte['quantite']; ?></td>
                            <td><?php echo formatMoney($acte['prix_unitaire']); ?></td>
                            <td class="montant-item" data-montant="<?php echo $acte['montant_total']; ?>">
                                <strong><?php echo formatMoney($acte['montant_total']); ?></strong>
                            </td>
                            <td>
                                <?php if ($acte['urgent']): ?>
                                    <span class="badge badge-danger">URGENT</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Prescriptions Pharmacie -->
            <?php if (!empty($pharma_presc)): ?>
            <div class="section-title mt-2">
                <h4><i class="fas fa-pills"></i> Prescriptions Pharmacie</h4>
            </div>
            <div class="table-container">
                <table id="pharmaTable">
                    <thead>
                        <tr>
                            <th width="50">
                                <input type="checkbox" id="selectAllPharma" onchange="toggleSelectAll('pharma')">
                            </th>
                            <th>Date</th>
                            <th>Produit</th>
                            <th>Prescripteur</th>
                            <th>Qté</th>
                            <th>Posologie</th>
                            <th>Prix Unit.</th>
                            <th>Total</th>
                            <th>Urgent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pharma_presc as $pharma): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="pharma_presc[]" value="<?php echo $pharma['idpharma_presc']; ?>" 
                                       class="pharma-checkbox item-checkbox" onchange="calculateTotal()">
                            </td>
                            <td><?php echo formatDateTime($pharma['date_prescription']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($pharma['produit_libelle']); ?></strong><br>
                                <small><?php echo htmlspecialchars($pharma['produit_code']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($pharma['prescripteur_prenom'] . ' ' . $pharma['prescripteur_nom']); ?></td>
                            <td><?php echo $pharma['quantite']; ?></td>
                            <td><small><?php echo htmlspecialchars($pharma['posologie'] ?? '-'); ?></small></td>
                            <td><?php echo formatMoney($pharma['prix_unitaire']); ?></td>
                            <td class="montant-item" data-montant="<?php echo $pharma['montant_total']; ?>">
                                <strong><?php echo formatMoney($pharma['montant_total']); ?></strong>
                            </td>
                            <td>
                                <?php if ($pharma['urgent']): ?>
                                    <span class="badge badge-danger">URGENT</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Résumé -->
            <div class="summary-card mt-2">
                <div class="summary-content">
                    <div class="summary-row">
                        <span>Sous-total:</span>
                        <strong id="montantSousTotal">0,00 FC</strong>
                    </div>
                    <?php if ($sejourData['type_patient'] === 'conventionne'): ?>
                    <div class="summary-row">
                        <span>Part Société:</span>
                        <strong class="text-success" id="partSociete">0,00 FC</strong>
                    </div>
                    <div class="summary-row">
                        <span>Part Patient:</span>
                        <strong class="text-warning" id="partPatient">0,00 FC</strong>
                    </div>
                    <?php endif; ?>
                    <div class="summary-row total-row">
                        <span>MONTANT TOTAL À PAYER:</span>
                        <strong id="montantTotal">0,00 FC</strong>
                    </div>
                </div>
            </div>
            
            <!-- Validation et paiement -->
            <div class="validation-panel mt-2">
                <h4>Mode de Paiement</h4>
                <div class="payment-modes">
                    <label class="payment-mode">
                        <input type="radio" name="mode_paiement" value="cash" required>
                        <div class="mode-content">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Cash</span>
                        </div>
                    </label>
                    
                    <label class="payment-mode">
                        <input type="radio" name="mode_paiement" value="cc">
                        <div class="mode-content">
                            <i class="fas fa-credit-card"></i>
                            <span>Carte Crédit</span>
                        </div>
                    </label>
                    
                    <label class="payment-mode">
                        <input type="radio" name="mode_paiement" value="virement">
                        <div class="mode-content">
                            <i class="fas fa-university"></i>
                            <span>Virement</span>
                        </div>
                    </label>
                    
                    <label class="payment-mode">
                        <input type="radio" name="mode_paiement" value="cheque">
                        <div class="mode-content">
                            <i class="fas fa-money-check"></i>
                            <span>Chèque</span>
                        </div>
                    </label>
                    
                    <?php if ($sejourData['type_patient'] === 'conventionne'): ?>
                    <label class="payment-mode">
                        <input type="radio" name="mode_paiement" value="facture_societe">
                        <div class="mode-content">
                            <i class="fas fa-file-invoice"></i>
                            <span>Facture Société</span>
                        </div>
                    </label>
                    <?php endif; ?>
                </div>
                
                <div id="cashDetails" class="payment-details mt-2" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="montant_percu">Montant Perçu (FC)</label>
                            <input type="number" id="montant_percu" name="montant_percu" class="form-control" 
                                   step="0.01" onchange="calculateChange()">
                        </div>
                        
                        <div class="form-group">
                            <label>Monnaie à Rendre</label>
                            <div class="change-display" id="changeDisplay">0,00 FC</div>
                        </div>
                    </div>
                </div>
                
                <div id="reductionSection" class="payment-details mt-2" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="type_reduction">Type de réduction</label>
                            <select id="type_reduction" name="type_reduction" class="form-control">
                                <option value="">Aucune réduction</option>
                                <option value="pourcentage">Pourcentage</option>
                                <option value="montant_fixe">Montant fixe</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="montant_reduction">Montant réduction (FC)</label>
                            <input type="number" id="montant_reduction" name="montant_reduction" class="form-control" 
                                   step="0.01" onchange="calculateTotal()">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions mt-2">
                    <button type="submit" name="valider" class="btn btn-primary btn-lg">
                        <i class="fas fa-check"></i> Valider et Encaisser
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="printReceipt()">
                        <i class="fas fa-print"></i> Imprimer Facture
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> Aucun acte ou prescription en attente de validation.
</div>
<?php endif; ?>

<!-- Actes déjà validés -->
<?php if (!empty($actes_valides)): ?>
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-check-double"></i> Actes Déjà Validés</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date Validation</th>
                        <th>Acte</th>
                        <th>Montant</th>
                        <th>Mode Paiement</th>
                        <th>Validé par</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actes_valides as $av): ?>
                    <tr>
                        <td><?php echo formatDateTime($av['date_validation']); ?></td>
                        <td><?php echo htmlspecialchars($av['acte_libelle']); ?></td>
                        <td><?php echo formatMoney($av['montant_total']); ?></td>
                        <td>
                            <span class="badge badge-success">
                                <?php echo strtoupper($av['mode_paiement']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($av['valideur_nom'] ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.patient-info-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 20px;
    margin-bottom: 20px;
}

.patient-info-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border);
}

.patient-info-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.info-item {
    font-size: 14px;
}

.info-item strong {
    color: var(--primary);
}

.section-title {
    padding: 10px 15px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 15px;
    border-left: 4px solid var(--primary);
}

.summary-card {
    background: #f8fafc;
    border-radius: 8px;
    padding: 20px;
    border: 1px solid var(--border);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e2e8f0;
}

.total-row {
    font-size: 18px;
    border-bottom: none;
    border-top: 2px solid var(--primary);
    margin-top: 10px;
    padding-top: 15px;
}

.payment-modes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.payment-mode {
    cursor: pointer;
}

.payment-mode input[type="radio"] {
    display: none;
}

.mode-content {
    border: 2px solid var(--border);
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    transition: all 0.2s;
}

.mode-content i {
    font-size: 32px;
    color: var(--primary);
    margin-bottom: 10px;
    display: block;
}

.payment-mode input[type="radio"]:checked + .mode-content {
    border-color: var(--primary);
    background: var(--light);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.change-display {
    background: var(--light);
    border: 2px solid var(--border);
    border-radius: 6px;
    padding: 12px;
    font-size: 18px;
    font-weight: bold;
    color: var(--primary);
    text-align: center;
}

.payment-details {
    background: #f8fafc;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid var(--border);
}
</style>

<script>
let totalSousTotal = 0;
let montantReduction = 0;
let tauxCouverture = <?php echo $sejourData['type_patient'] === 'conventionne' ? 80 : 0; ?>;
let estConventionne = <?php echo $sejourData['type_patient'] === 'conventionne' ? 'true' : 'false'; ?>;

function toggleSelectAll(type) {
    const selector = type === 'acte' ? '.acte-checkbox' : '.pharma-checkbox';
    const checkbox = type === 'acte' ? document.getElementById('selectAllActes') : document.getElementById('selectAllPharma');
    
    document.querySelectorAll(selector).forEach(cb => {
        cb.checked = checkbox.checked;
    });
    calculateTotal();
}

function calculateTotal() {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    totalSousTotal = 0;
    
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        const montantCell = row.querySelector('.montant-item');
        const montant = parseFloat(montantCell.dataset.montant);
        totalSousTotal += montant;
    });
    
    // Gérer la réduction
    const typeReduction = document.getElementById('type_reduction').value;
    const montantReductionInput = parseFloat(document.getElementById('montant_reduction').value) || 0;
    
    if (typeReduction === 'pourcentage' && montantReductionInput > 0) {
        montantReduction = totalSousTotal * montantReductionInput / 100;
    } else {
        montantReduction = montantReductionInput;
    }
    
    const totalApresReduction = Math.max(0, totalSousTotal - montantReduction);
    
    // Mettre à jour les affichages
    document.getElementById('montantSousTotal').textContent = formatMoney(totalSousTotal);
    document.getElementById('montantTotal').textContent = formatMoney(totalApresReduction);
    
    if (estConventionne) {
        const partSociete = totalApresReduction * tauxCouverture / 100;
        const partPatient = totalApresReduction - partSociete;
        
        document.getElementById('partSociete').textContent = formatMoney(partSociete);
        document.getElementById('partPatient').textContent = formatMoney(partPatient);
    }
    
    calculateChange();
}

function calculateChange() {
    const montantPercu = parseFloat(document.getElementById('montant_percu').value) || 0;
    const totalApresReduction = parseFloat(document.getElementById('montantTotal').textContent.replace(/[^\d,]/g, '').replace(',', '.'));
    
    const change = montantPercu - totalApresReduction;
    const changeDisplay = document.getElementById('changeDisplay');
    
    if (change >= 0) {
        changeDisplay.textContent = formatMoney(change);
        changeDisplay.style.color = 'var(--secondary)';
    } else {
        changeDisplay.textContent = formatMoney(Math.abs(change)) + ' (Insuffisant)';
        changeDisplay.style.color = 'var(--danger)';
    }
}

function printReceipt() {
    window.print();
}

// Gérer l'affichage des détails selon le mode de paiement
document.querySelectorAll('input[name="mode_paiement"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const cashDetails = document.getElementById('cashDetails');
        const reductionSection = document.getElementById('reductionSection');
        
        if (this.value === 'cash') {
            cashDetails.style.display = 'block';
            reductionSection.style.display = 'block';
        } else {
            cashDetails.style.display = 'none';
            reductionSection.style.display = 'block';
        }
        
        calculateChange();
    });
});

// Gérer la réduction
document.getElementById('type_reduction').addEventListener('change', calculateTotal);
document.getElementById('montant_reduction').addEventListener('input', calculateTotal);

// Validation avant soumission
document.getElementById('validationForm').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('.item-checkbox:checked');
    
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Veuillez sélectionner au moins un acte ou prescription à valider.');
        return false;
    }
    
    const modePaiement = document.querySelector('input[name="mode_paiement"]:checked');
    if (!modePaiement) {
        e.preventDefault();
        alert('Veuillez sélectionner un mode de paiement.');
        return false;
    }
    
    const totalApresReduction = parseFloat(document.getElementById('montantTotal').textContent.replace(/[^\d,]/g, '').replace(',', '.'));
    
    if (modePaiement.value === 'cash') {
        const montantPercu = parseFloat(document.getElementById('montant_percu').value) || 0;
        if (montantPercu < totalApresReduction) {
            e.preventDefault();
            alert('Le montant perçu est insuffisant.');
            return false;
        }
    }
    
    return confirm('Confirmer la validation de ' + checkboxes.length + ' élément(s) pour un montant de ' + formatMoney(totalApresReduction) + ' ?');
});

// Initialiser
calculateTotal();
</script>

<?php include '../views/includes/footer.php'; ?>
