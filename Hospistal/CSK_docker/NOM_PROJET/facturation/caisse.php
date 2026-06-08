<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$date_debut = $_GET['date_debut'] ?? date('Y-m-d');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

// RÃ©cupÃ©rer les transactions de l'utilisateur
$query = "SELECT ct.*, 
                 p.numero_dossier,
                 p.nom as patient_nom,
                 p.prenom as patient_prenom,
                 ctt.libelle as type_transaction
          FROM caisse_transact ct
          JOIN patient p ON ct.idpatient = p.idpatient
          JOIN caisse_typetransact ctt ON ct.idcaisse_typetransact = ctt.idcaisse_typetransact
          WHERE ct.idutilisateur = :idutilisateur
          AND DATE(ct.date_transaction) BETWEEN :date_debut AND :date_fin
          ORDER BY ct.date_transaction DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':idutilisateur', $_SESSION['user_id']);
$stmt->bindParam(':date_debut', $date_debut);
$stmt->bindParam(':date_fin', $date_fin);
$stmt->execute();
$transactions = $stmt->fetchAll();

// Calculer les totaux
$total_entrees_fc = 0;
$total_entrees_usd = 0;
$total_sorties_fc = 0;
$total_sorties_usd = 0;

foreach ($transactions as $t) {
    $query_type = "SELECT type_mouvement FROM caisse_typetransact WHERE idcaisse_typetransact = :id";
    $stmt_type = $db->prepare($query_type);
    $stmt_type->bindParam(':id', $t['idcaisse_typetransact']);
    $stmt_type->execute();
    $type = $stmt_type->fetch();
    
    if ($type['type_mouvement'] === 'entree') {
        $total_entrees_fc += $t['montant_fc'];
        $total_entrees_usd += $t['montant_usd'];
    } else {
        $total_sorties_fc += $t['montant_fc'];
        $total_sorties_usd += $t['montant_usd'];
    }
}

$solde_fc = $total_entrees_fc - $total_sorties_fc;
$solde_usd = $total_entrees_usd - $total_sorties_usd;

$pageTitle = "Ma Caisse - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> Mon Ã‰tat de Caisse</h1>
    <div class="header-actions">
        <button onclick="printCaisse()" class="btn btn-secondary">
            <i class="fas fa-print"></i> Imprimer
        </button>
        <a href="billetage.php" class="btn btn-primary">
            <i class="fas fa-coins"></i> Billetage
        </a>
    </div>
</div>

<!-- Filtres -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="date_debut">Date dÃ©but</label>
                    <input type="date" id="date_debut" name="date_debut" class="form-control" 
                           value="<?php echo $date_debut; ?>">
                </div>
                
                <div class="form-group">
                    <label for="date_fin">Date fin</label>
                    <input type="date" id="date_fin" name="date_fin" class="form-control" 
                           value="<?php echo $date_fin; ?>">
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrer
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- RÃ©sumÃ© de caisse -->
<div class="caisse-summary">
    <div class="summary-card">
        <div class="summary-icon" style="background: var(--secondary);">
            <i class="fas fa-arrow-down"></i>
        </div>
        <div class="summary-content">
            <div class="summary-label">EntrÃ©es</div>
            <div class="summary-amount"><?php echo formatMoney($total_entrees_fc); ?></div>
            <small><?php echo formatMoney($total_entrees_usd, 'USD'); ?></small>
        </div>
    </div>
    
    <div class="summary-card">
        <div class="summary-icon" style="background: var(--danger);">
            <i class="fas fa-arrow-up"></i>
        </div>
        <div class="summary-content">
            <div class="summary-label">Sorties</div>
            <div class="summary-amount"><?php echo formatMoney($total_sorties_fc); ?></div>
            <small><?php echo formatMoney($total_sorties_usd, 'USD'); ?></small>
        </div>
    </div>
    
    <div class="summary-card">
        <div class="summary-icon" style="background: var(--primary);">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="summary-content">
            <div class="summary-label">Solde</div>
            <div class="summary-amount"><?php echo formatMoney($solde_fc); ?></div>
            <small><?php echo formatMoney($solde_usd, 'USD'); ?></small>
        </div>
    </div>
</div>

<!-- Liste des transactions -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">DÃ©tails des Transactions</h3>
    </div>
    <div class="card-body">
        <?php if (empty($transactions)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Aucune transaction pour cette pÃ©riode.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date/Heure</th>
                            <th>Patient</th>
                            <th>NÂ° Dossier</th>
                            <th>Type</th>
                            <th>Mode</th>
                            <th>Montant FC</th>
                            <th>Montant USD</th>
                            <th>RÃ©fÃ©rence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td><?php echo formatDateTime($trans['date_transaction']); ?></td>
                            <td><?php echo htmlspecialchars($trans['patient_prenom'] . ' ' . $trans['patient_nom']); ?></td>
                            <td><?php echo htmlspecialchars($trans['numero_dossier']); ?></td>
                            <td><?php echo htmlspecialchars($trans['type_transaction']); ?></td>
                            <td>
                                <span class="badge badge-primary">
                                    <?php echo strtoupper($trans['mode_paiement']); ?>
                                </span>
                            </td>
                            <td><?php echo formatMoney($trans['montant_fc']); ?></td>
                            <td><?php echo formatMoney($trans['montant_usd'], 'USD'); ?></td>
                            <td><small><?php echo htmlspecialchars($trans['reference_paiement'] ?? '-'); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.caisse-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.summary-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 25px;
    display: flex;
    gap: 20px;
    align-items: center;
}

.summary-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: white;
}

.summary-content {
    flex: 1;
}

.summary-label {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 5px;
}

.summary-amount {
    font-size: 24px;
    font-weight: bold;
    color: var(--dark);
}

.header-actions {
    display: flex;
    gap: 10px;
}
</style>

<script>
function printCaisse() {
    window.print();
}
</script>

<?php include '../views/includes/footer.php'; ?>