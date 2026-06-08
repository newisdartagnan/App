<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Structure des billets
$billets_fc = [50000, 20000, 10000, 5000, 1000, 500, 200, 100, 50];
$billets_usd = [100, 50, 20, 10, 5, 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $total_fc = 0;
    $total_usd = 0;
    $details = [];
    
    // Calcul FC
    foreach ($billets_fc as $billet) {
        $qty = intval($_POST['fc_' . $billet] ?? 0);
        if ($qty > 0) {
            $montant = $billet * $qty;
            $total_fc += $montant;
            $details['fc'][] = [
                'denomination' => $billet,
                'quantite' => $qty,
                'montant' => $montant
            ];
        }
    }
    
    // Calcul USD
    foreach ($billets_usd as $billet) {
        $qty = intval($_POST['usd_' . $billet] ?? 0);
        if ($qty > 0) {
            $montant = $billet * $qty;
            $total_usd += $montant;
            $details['usd'][] = [
                'denomination' => $billet,
                'quantite' => $qty,
                'montant' => $montant
            ];
        }
    }
    
    $observation = sanitizeInput($_POST['observation'] ?? '');
    
    // Enregistrer le billetage
    try {
        $query = "INSERT INTO billetage_caisse 
                  (idutilisateur, date_billetage, total_fc, total_usd, details, observation)
                  VALUES 
                  (:idutilisateur, NOW(), :total_fc, :total_usd, :details, :observation)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':idutilisateur', $_SESSION['user_id']);
        $stmt->bindParam(':total_fc', $total_fc);
        $stmt->bindParam(':total_usd', $total_usd);
        $stmt->bindParam(':details', json_encode($details));
        $stmt->bindParam(':observation', $observation);
        
        if ($stmt->execute()) {
            $success = "Billetage enregistré avec succès ! Total: " . formatMoney($total_fc) . " / " . formatMoney($total_usd, 'USD');
        }
    } catch (Exception $e) {
        $error = "Erreur lors de l'enregistrement : " . $e->getMessage();
    }
}

$pageTitle = "Billetage - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-coins"></i> Billetage de la Caisse</h1>
    <a href="caisse.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour à la caisse
    </a>
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

<form method="POST">
    <div class="billetage-container">
        <!-- Billetage FC -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-money-bill"></i> Francs Congolais (FC)</h3>
            </div>
            <div class="card-body">
                <table class="billetage-table">
                    <thead>
                        <tr>
                            <th>Dénomination</th>
                            <th width="150">Quantité</th>
                            <th>Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($billets_fc as $billet): ?>
                        <tr>
                            <td><strong><?php echo number_format($billet, 0, ',', ' '); ?> FC</strong></td>
                            <td>
                                <input type="number" 
                                       name="fc_<?php echo $billet; ?>" 
                                       class="form-control billetage-input"
                                       data-devise="fc"
                                       data-valeur="<?php echo $billet; ?>"
                                       min="0" 
                                       value="0"
                                       onchange="calculateBilletage()">
                            </td>
                            <td class="montant-billet" id="fc_montant_<?php echo $billet; ?>">0 FC</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: var(--light); font-weight: bold;">
                            <td colspan="2">TOTAL FC:</td>
                            <td id="total_fc">0 FC</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Billetage USD -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-dollar-sign"></i> Dollars Américains (USD)</h3>
            </div>
            <div class="card-body">
                <table class="billetage-table">
                    <thead>
                        <tr>
                            <th>Dénomination</th>
                            <th width="150">Quantité</th>
                            <th>Montant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($billets_usd as $billet): ?>
                        <tr>
                            <td><strong><?php echo $billet; ?> USD</strong></td>
                            <td>
                                <input type="number" 
                                       name="usd_<?php echo $billet; ?>" 
                                       class="form-control billetage-input"
                                       data-devise="usd"
                                       data-valeur="<?php echo $billet; ?>"
                                       min="0" 
                                       value="0"
                                       onchange="calculateBilletage()">
                            </td>
                            <td class="montant-billet" id="usd_montant_<?php echo $billet; ?>">0 USD</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: var(--light); font-weight: bold;">
                            <td colspan="2">TOTAL USD:</td>
                            <td id="total_usd">0 USD</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    
    <div class="card mt-2">
        <div class="card-body">
            <div class="form-group">
                <label for="observation">Observation</label>
                <textarea id="observation" name="observation" class="form-control" rows="3"
                          placeholder="Notes ou remarques sur le billetage..."></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save"></i> Enregistrer le Billetage
                </button>
                <button type="reset" class="btn btn-secondary" onclick="calculateBilletage()">
                    <i class="fas fa-redo"></i> Réinitialiser
                </button>
            </div>
        </div>
    </div>
</form>

<style>
.billetage-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.billetage-table {
    width: 100%;
}

.billetage-table td {
    padding: 10px;
}

.billetage-input {
    text-align: center;
    font-weight: bold;
}

.montant-billet {
    font-weight: bold;
    color: var(--primary);
}
</style>

<script>
function calculateBilletage() {
    let totalFC = 0;
    let totalUSD = 0;
    
    // Calculer FC
    document.querySelectorAll('[data-devise="fc"]').forEach(input => {
        const qty = parseInt(input.value) || 0;
        const valeur = parseInt(input.dataset.valeur);
        const montant = qty * valeur;
        
        totalFC += montant;
        document.getElementById('fc_montant_' + valeur).textContent = 
            formatMoney(montant);
    });
    
    // Calculer USD
    document.querySelectorAll('[data-devise="usd"]').forEach(input => {
        const qty = parseInt(input.value) || 0;
        const valeur = parseInt(input.dataset.valeur);
        const montant = qty * valeur;
        
        totalUSD += montant;
        document.getElementById('usd_montant_' + valeur).textContent = 
            formatMoney(montant, 'USD');
    });
    
    document.getElementById('total_fc').textContent = formatMoney(totalFC);
    document.getElementById('total_usd').textContent = formatMoney(totalUSD, 'USD');
}

function formatMoney(amount, currency = 'FC') {
    return new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount) + ' ' + currency;
}

// Initialiser
calculateBilletage();
</script>

<?php include '../views/includes/footer.php'; ?>