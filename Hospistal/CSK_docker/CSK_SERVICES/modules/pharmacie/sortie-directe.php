<?php
/**
 * Module Pharmacie - Sortie directe de stock
 * Permet de faire des sorties de stock sans prescription (dons, transferts, etc.)
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

$idofficine = $_GET['idofficine'] ?? null;

if (!$idofficine) {
    redirect('index.php?page=pharmacie&action=officine');
}

// =============================================
// INFOS OFFICINE
// =============================================
$query_off = "SELECT * FROM officine WHERE idofficine = ?";
$stmt_off = $conn_base->prepare($query_off);
$stmt_off->execute([$idofficine]);
$officine = $stmt_off->fetch(PDO::FETCH_ASSOC);

// =============================================
// RÉCUPÉRATION DES DESTINATIONS
// =============================================
$query_dest = "SELECT * FROM destinationsprod ORDER BY libelle";
$stmt_dest = $conn_base->query($query_dest);
$destinations = $stmt_dest->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// RÉCUPÉRATION DES PRODUITS EN STOCK
// =============================================
$query_prod = "SELECT p.*, sp.quantite as stock_disponible,
                      f.nom as famille
               FROM prodpharma p
               JOIN stockpharma sp ON p.idprodpharma = sp.idprodpharma
               LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
               WHERE sp.idofficine = :idofficine
               AND p.actif = 1
               AND sp.quantite > 0
               ORDER BY p.libelle";
$stmt_prod = $conn_base->prepare($query_prod);
$stmt_prod->bindParam(':idofficine', $idofficine);
$stmt_prod->execute();
$produits = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// TRAITEMENT DE LA SORTIE DIRECTE
// =============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['effectuer_sortie'])) {
    $idprodpharma = $_POST['produit'] ?? null;
    $quantite = (int)($_POST['quantite'] ?? 0);
    $iddestination = $_POST['iddestination'] ?? null;
    $type_sortie = $_POST['type_sortie'] ?? 'directe';
    $observation = trim($_POST['observation'] ?? '');
    
    if (!$idprodpharma || $quantite <= 0) {
        $error = "Veuillez sélectionner un produit et saisir une quantité valide.";
    } elseif (!$iddestination) {
        $error = "Veuillez sélectionner une destination.";
    } else {
        try {
            $conn_base->beginTransaction();
            
            // Vérifier le stock
            $query_stock = "SELECT quantite FROM stockpharma 
                           WHERE idprodpharma = :idprod AND idofficine = :idofficine";
            $stmt_stock = $conn_base->prepare($query_stock);
            $stmt_stock->execute([
                ':idprod' => $idprodpharma,
                ':idofficine' => $idofficine
            ]);
            $stock = $stmt_stock->fetch(PDO::FETCH_ASSOC);
            
            if (!$stock || $stock['quantite'] < $quantite) {
                throw new Exception("Stock insuffisant ! Disponible: " . ($stock['quantite'] ?? 0));
            }
            
            // Mettre à jour le stock
            $query_update = "UPDATE stockpharma 
                            SET quantite = quantite - :quantite,
                                date_derniere_maj = NOW()
                            WHERE idprodpharma = :idprod 
                            AND idofficine = :idofficine";
            $stmt_update = $conn_base->prepare($query_update);
            $stmt_update->execute([
                ':quantite' => $quantite,
                ':idprod' => $idprodpharma,
                ':idofficine' => $idofficine
            ]);
            
            // Enregistrer la sortie directe dans sorties_stock
            $type_sortie = substr($type_sortie, 0, 20);
            
            $query_sortie = "INSERT INTO sorties_stock 
                            (idprodpharma, idofficine, quantite, type_sortie, 
                             iddestination, idutilisateur, observation)
                            VALUES 
                            (:idprod, :idofficine, :quantite, :type_sortie,
                             :iddestination, :user, :observation)";
            $stmt_sortie = $conn_base->prepare($query_sortie);
            $stmt_sortie->execute([
                ':idprod' => $idprodpharma,
                ':idofficine' => $idofficine,
                ':quantite' => $quantite,
                ':type_sortie' => $type_sortie,
                ':iddestination' => $iddestination,
                ':user' => $_SESSION['user_id'],
                ':observation' => $observation ?: null
            ]);
            
            $conn_base->commit();
            $success = "Sortie directe effectuée avec succès !";
            
        } catch (Exception $e) {
            $conn_base->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// =============================================
// HISTORIQUE DES SORTIES DIRECTES
// =============================================
$query_hist = "SELECT s.*, 
                      p.libelle as produit_libelle, p.code as produit_code,
                      d.libelle as destination_libelle,
                      u.nom as user_nom, u.prenom as user_prenom
               FROM sorties_stock s
               JOIN prodpharma p ON s.idprodpharma = p.idprodpharma
               LEFT JOIN destinationsprod d ON s.iddestination = d.iddestination
               JOIN utilisateur u ON s.idutilisateur = u.idutilisateur
               WHERE s.idofficine = :idofficine
               ORDER BY s.date_sortie DESC
               LIMIT 20";
$stmt_hist = $conn_base->prepare($query_hist);
$stmt_hist->bindParam(':idofficine', $idofficine);
$stmt_hist->execute();
$historique = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-box-arrow-right me-2" style="color: #f59e0b;"></i>Sortie directe - <?= htmlspecialchars($officine['nom']) ?></h4>
    <a href="index.php?page=pharmacie&action=stock-officine&idofficine=<?= $idofficine ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour au stock</a>
</div>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> <?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle"></i> <?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- ========================================= -->
<!-- FORMULAIRE DE SORTIE DIRECTE -->
<!-- ========================================= -->
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm"><div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Nouvelle sortie directe</h6></div>
        <div class="card-body">
            <form method="POST" id="sortieForm">
                <div class="mb-3"><label class="form-label">Produit *</label>
                    <select name="produit" id="produit" class="form-select" required>
                        <option value="">-- Sélectionner un produit --</option>
                        <?php foreach ($produits as $p): ?>
                        <option value="<?= $p['idprodpharma'] ?>" data-stock="<?= $p['stock_disponible'] ?>" data-prix="<?= $p['prix_vente_externe'] ?? 0 ?>">
                            <?= htmlspecialchars($p['libelle']) ?> (<?= htmlspecialchars($p['code']) ?>) - Stock: <?= $p['stock_disponible'] ?> - <?= htmlspecialchars($p['famille'] ?? '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row"><div class="col-md-6"><div class="mb-3"><label class="form-label">Quantité *</label><input type="number" name="quantite" id="quantite" class="form-control" min="1" required><small class="text-muted" id="stockMax"></small></div></div>
                <div class="col-md-6"><div class="mb-3"><label class="form-label">Valeur approximative</label><input type="text" id="valeur" class="form-control" readonly></div></div></div>
                <div class="mb-3"><label class="form-label">Type de sortie</label><select name="type_sortie" class="form-select"><option value="directe">Sortie directe</option><option value="perte">Perte / Casse</option><option value="don">Don</option><option value="transfert">Transfert</option></select></div>
                <div class="mb-3"><label class="form-label">Destination *</label><select name="iddestination" class="form-select" required><option value="">-- Sélectionner une destination --</option><?php foreach ($destinations as $d): ?><option value="<?= $d['iddestination'] ?>"><?= htmlspecialchars($d['libelle']) ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label">Observation</label><textarea name="observation" class="form-control" rows="3" placeholder="Informations complémentaires..."></textarea></div>
                <button type="submit" name="effectuer_sortie" class="btn btn-warning btn-lg"><i class="bi bi-check-circle"></i> Effectuer la sortie</button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm"><div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Informations</h6></div>
        <div class="card-body"><div class="alert alert-info"><i class="bi bi-lightbulb"></i> <strong>Qu'est-ce qu'une sortie directe ?</strong><p class="mb-0 mt-2">Les sorties directes sont utilisées pour les mouvements de stock qui ne sont pas liés à une prescription médicale :</p><ul class="mt-2 mb-0"><?php foreach ($destinations as $d): ?><li><?= htmlspecialchars($d['libelle']) ?></li><?php endforeach; ?></ul></div><div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <strong>Attention !</strong><p class="mb-0 mt-2">Cette action est irréversible et déduira immédiatement les quantités du stock.</p></div></div></div>
    </div>
</div>

<!-- ========================================= -->
<!-- HISTORIQUE DES SORTIES -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historique des sorties directes</h6></div>
    <div class="card-body p-0">
        <?php if (empty($historique)): ?>
            <div class="text-center text-muted py-4"><i class="bi bi-archive fs-1 d-block mb-2"></i><p class="mb-0">Aucune sortie directe enregistrée</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Date</th><th>Produit</th><th class="text-center">Quantité</th><th>Destination</th><th>Type</th><th>Effectué par</th></tr></thead><tbody>
                <?php foreach ($historique as $s): ?>
                <tr><td><?= formatDateTime($s['date_sortie']) ?></td><td><strong><?= htmlspecialchars($s['produit_libelle']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($s['produit_code']) ?></small></td><td class="text-center fw-bold"><?= $s['quantite'] ?></td><td><span class="badge bg-info"><?= htmlspecialchars($s['destination_libelle'] ?? '-') ?></span></td><td><span class="badge bg-secondary"><?= htmlspecialchars($s['type_sortie'] ?? 'directe') ?></span></td><td><?= htmlspecialchars($s['user_prenom'] . ' ' . $s['user_nom']) ?></td></tr>
                <?php if (!empty($s['observation'])): ?><tr class="table-light"><td colspan="6" class="small text-muted"><i class="bi bi-chat"></i> <?= htmlspecialchars($s['observation']) ?></td></tr><?php endif; ?>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('produit').addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const stock = selected.getAttribute('data-stock');
    const prix = selected.getAttribute('data-prix');
    if (stock) { document.getElementById('quantite').max = stock; document.getElementById('quantite').placeholder = `Max: ${stock}`; document.getElementById('stockMax').textContent = `Stock maximum: ${stock}`; }
    updateValeur();
});
document.getElementById('quantite').addEventListener('input', updateValeur);
function updateValeur() {
    const produit = document.getElementById('produit');
    const quantite = document.getElementById('quantite').value;
    const prix = produit.options[produit.selectedIndex]?.getAttribute('data-prix');
    if (quantite && prix) { document.getElementById('valeur').value = formatMoney(parseFloat(quantite) * parseFloat(prix)); }
    else { document.getElementById('valeur').value = ''; }
}
function formatMoney(value) { if (isNaN(value)) return '-'; return value.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' FC'; }
document.getElementById('sortieForm').addEventListener('submit', function(e) {
    const produit = document.getElementById('produit').value;
    const quantite = parseInt(document.getElementById('quantite').value);
    const destination = document.querySelector('select[name="iddestination"]').value;
    const stockMax = parseInt(document.getElementById('produit').options[document.getElementById('produit').selectedIndex]?.getAttribute('data-stock'));
    if (!produit) { alert('Veuillez sélectionner un produit.'); e.preventDefault(); return; }
    if (!destination) { alert('Veuillez sélectionner une destination.'); e.preventDefault(); return; }
    if (quantite > stockMax) { alert(`La quantité ne peut pas dépasser le stock disponible (${stockMax}).`); e.preventDefault(); return; }
    if (!confirm('Confirmer la sortie directe ? Cette action est irréversible.')) { e.preventDefault(); }
});
</script>