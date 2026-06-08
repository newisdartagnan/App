<?php
/**
 * Module Pharmacie - Gestion des réquisitions
 * Création et suivi des demandes de réapprovisionnement
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
// TRAITEMENT CRÉATION RÉQUISITION
// =============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creer_requisition'])) {
    $produits = $_POST['produits'] ?? [];
    $quantites = $_POST['quantites'] ?? [];
    
    if (empty($produits)) {
        $error = "Veuillez sélectionner au moins un produit.";
    } else {
        try {
            $conn_base->beginTransaction();
            
            // Générer le numéro de réquisition
            $query_num = "SELECT MAX(CAST(SUBSTRING(numero_requisition, 4) AS UNSIGNED)) as last_num 
                          FROM requisition WHERE numero_requisition LIKE 'REQ%'";
            $stmt_num = $conn_base->query($query_num);
            $row_num = $stmt_num->fetch();
            $lastNumber = $row_num['last_num'] ?? 0;
            $numero_requisition = 'REQ' . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
            
            // Créer la réquisition
            $query = "INSERT INTO requisition (idofficine, numero_requisition, idutilisateur, date_requisition) 
                      VALUES (:idofficine, :numero, :iduser, NOW())";
            $stmt = $conn_base->prepare($query);
            $stmt->execute([
                ':idofficine' => $idofficine,
                ':numero' => $numero_requisition,
                ':iduser' => $_SESSION['user_id']
            ]);
            
            $idrequisition = $conn_base->lastInsertId();
            
            // Ajouter les lignes
            $query_ligne = "INSERT INTO lignesrecquisition (idrequisition, idprodpharma, quantite_demandee) 
                            VALUES (:idreq, :idprod, :qte)";
            $stmt_ligne = $conn_base->prepare($query_ligne);
            
            foreach ($produits as $index => $idprod) {
                if (!empty($idprod) && !empty($quantites[$index]) && $quantites[$index] > 0) {
                    $stmt_ligne->execute([
                        ':idreq' => $idrequisition,
                        ':idprod' => $idprod,
                        ':qte' => $quantites[$index]
                    ]);
                }
            }
            
            $conn_base->commit();
            $success = "Réquisition créée avec succès ! N°: $numero_requisition";
            
        } catch (Exception $e) {
            $conn_base->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// =============================================
// PRODUITS DISPONIBLES
// =============================================
$query_prod = "SELECT p.*, f.nom as famille, v.nom as voie, u.nom as unite 
               FROM prodpharma p 
               LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
               LEFT JOIN voie_prod v ON p.idvoie_prod = v.idvoie_prod
               LEFT JOIN unite u ON p.idunite = u.idunite
               WHERE p.actif = 1 ORDER BY p.libelle";
$stmt_prod = $conn_base->query($query_prod);
$produits = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// HISTORIQUE DES RÉQUISITIONS
// =============================================
$historique = [];

try {
    $query_hist = "SELECT r.*, u.nom as user_nom, u.prenom as user_prenom,
                          COUNT(l.idprodpharma) as nb_produits,
                          SUM(l.quantite_demandee) as total_quantite
                   FROM requisition r
                   JOIN utilisateur u ON r.idutilisateur = u.idutilisateur
                   LEFT JOIN lignesrecquisition l ON r.idrequisition = l.idrequisition
                   WHERE r.idofficine = :idofficine
                   GROUP BY r.idrequisition
                   ORDER BY r.date_requisition DESC
                   LIMIT 20";
    $stmt_hist = $conn_base->prepare($query_hist);
    $stmt_hist->bindParam(':idofficine', $idofficine);
    $stmt_hist->execute();
    $historique = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[Pharmacie] Erreur historique requisition: " . $e->getMessage());
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-file-import me-2" style="color: #198754;"></i>Réquisition - <?= htmlspecialchars($officine['nom']) ?></h4>
    <a href="index.php?page=pharmacie&action=stock-officine&idofficine=<?= $idofficine ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour au stock</a>
</div>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> <?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle"></i> <?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- ========================================= -->
<!-- FORMULAIRE NOUVELLE RÉQUISITION -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Nouvelle réquisition</h6></div>
    <div class="card-body">
        <form method="POST" id="requisitionForm">
            <div id="produitsContainer">
                <div class="produit-ligne mb-3 p-3 bg-light rounded">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Produit *</label>
                            <select name="produits[]" class="form-select produit-select" required>
                                <option value="">-- Sélectionner un produit --</option>
                                <?php foreach ($produits as $p): ?>
                                <option value="<?= $p['idprodpharma'] ?>"><?= htmlspecialchars($p['libelle']) ?> (<?= htmlspecialchars($p['code']) ?>) - <?= htmlspecialchars($p['famille'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label">Quantité *</label><input type="number" name="quantites[]" class="form-control" min="1" required></div>
                        <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-danger" onclick="removeLigne(this)"><i class="bi bi-trash"></i></button></div>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-outline-primary" onclick="addLigne()"><i class="bi bi-plus"></i> Ajouter un produit</button>
                <button type="submit" name="creer_requisition" class="btn btn-primary"><i class="bi bi-send"></i> Envoyer la réquisition</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================= -->
<!-- HISTORIQUE DES RÉQUISITIONS -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historique des réquisitions</h6></div>
    <div class="card-body p-0">
        <?php if (empty($historique)): ?>
            <div class="text-center text-muted py-4"><i class="bi bi-archive fs-1 d-block mb-2"></i><p class="mb-0">Aucune réquisition pour le moment</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr>
                        <th>N° Réquisition</th><th>Date</th><th>Demandé par</th><th class="text-center">Nb produits</th>
                        <th class="text-center">Quantité totale</th><th>Statut</th><th>Date traitement</th><th class="text-center">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($historique as $req): ?>
                        <tr>
                            <td><code><strong><?= htmlspecialchars($req['numero_requisition']) ?></strong></code></td>
                            <td><?= formatDateTime($req['date_requisition']) ?></td>
                            <td><?= htmlspecialchars($req['user_prenom'] . ' ' . $req['user_nom']) ?></td>
                            <td class="text-center"><span class="badge bg-primary"><?= $req['nb_produits'] ?? 0 ?></span></td>
                            <td class="text-center"><?= $req['total_quantite'] ?? 0 ?></td>
                            <td><?php $badge = match($req['statut'] ?? 'en_attente') { 'en_attente' => 'bg-warning text-dark', 'servi' => 'bg-success', 'refuse' => 'bg-danger', default => 'bg-secondary' }; ?>
                                <span class="badge <?= $badge ?>"><?= match($req['statut'] ?? 'en_attente') { 'en_attente' => 'En attente', 'servi' => 'Servie', 'refuse' => 'Refusée', default => $req['statut'] ?? 'Inconnu' } ?></span>
                            </td>
                            <td><?= $req['date_traitement'] ? formatDateTime($req['date_traitement']) : '-' ?></td>
                            <td class="text-center"><a href="index.php?page=pharmacie&action=detail-requisition&id=<?= $req['idrequisition'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Détail</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
let ligneCount = 1;
function addLigne() {
    const container = document.getElementById('produitsContainer');
    const template = container.firstElementChild.cloneNode(true);
    template.querySelectorAll('select, input').forEach(input => { if (input.type !== 'button') input.value = ''; });
    container.appendChild(template);
    ligneCount++;
}
function removeLigne(btn) {
    const container = document.getElementById('produitsContainer');
    if (container.children.length > 1) btn.closest('.produit-ligne').remove();
    else alert('Au moins une ligne doit être conservée.');
}
document.getElementById('requisitionForm').addEventListener('submit', function(e) {
    const selects = document.querySelectorAll('select[name="produits[]"]');
    const inputs = document.querySelectorAll('input[name="quantites[]"]');
    let valid = true;
    for (let i = 0; i < selects.length; i++) {
        if (!selects[i].value) { alert('Veuillez sélectionner un produit pour chaque ligne.'); valid = false; break; }
        if (!inputs[i].value || inputs[i].value <= 0) { alert('Veuillez saisir une quantité valide pour chaque ligne.'); valid = false; break; }
    }
    if (!valid) e.preventDefault();
});
</script>