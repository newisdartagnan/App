<?php
/**
 * Module Pharmacie - Traitement des réquisitions (Dépôt central)
 * Permet de servir ou refuser les réquisitions en attente
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

$idrequisition = $_GET['id'] ?? null;

if (!$idrequisition) {
    redirect('index.php?page=pharmacie&action=depot-central');
}

// =============================================
// INFOS RÉQUISITION
// =============================================
$query = "SELECT r.*, o.nom as officine_nom, o.idsite,
                 u.nom as user_nom, u.prenom as user_prenom
          FROM requisition r
          JOIN officine o ON r.idofficine = o.idofficine
          JOIN utilisateur u ON r.idutilisateur = u.idutilisateur
          WHERE r.idrequisition = :idreq AND r.statut = 'en_attente'";
$stmt = $conn_base->prepare($query);
$stmt->execute([':idreq' => $idrequisition]);
$requisition = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requisition) {
    $_SESSION['flash_error'] = "Réquisition introuvable ou déjà traitée.";
    redirect('index.php?page=pharmacie&action=depot-central');
}

// =============================================
// LIGNES DE LA RÉQUISITION
// =============================================
$query_lignes = "SELECT l.*, 
                        p.libelle, p.code, p.prix_achat, p.prix_vente_externe,
                        p.type_produit, p.idvoie_prod, p.idunite,
                        v.nom as voie, un.nom as unite, f.nom as famille,
                        COALESCE((SELECT SUM(quantite) FROM stockpharma WHERE idprodpharma = p.idprodpharma), 0) as stock_total
                 FROM lignesrecquisition l
                 JOIN prodpharma p ON l.idprodpharma = p.idprodpharma
                 LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
                 LEFT JOIN voie_prod v ON p.idvoie_prod = v.idvoie_prod
                 LEFT JOIN unite un ON p.idunite = un.idunite
                 WHERE l.idrequisition = :idreq
                 ORDER BY p.libelle";
$stmt_lignes = $conn_base->prepare($query_lignes);
$stmt_lignes->execute([':idreq' => $idrequisition]);
$lignes = $stmt_lignes->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// TRAITEMENT DU FORMULAIRE
// =============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn_base->beginTransaction();
        
        if (isset($_POST['servir'])) {
            $quantites_servies = $_POST['quantite_servie'] ?? [];
            $observation = trim($_POST['observation'] ?? '');
            
            foreach ($lignes as $ligne) {
                $idprod = $ligne['idprodpharma'];
                $quantite_servie = (int)($quantites_servies[$idprod] ?? 0);
                
                if ($quantite_servie > 0) {
                    // Vérifier le stock disponible
                    $query_stock = "SELECT quantite FROM stockpharma WHERE idprodpharma = :idprod";
                    $stmt_stock = $conn_base->prepare($query_stock);
                    $stmt_stock->execute([':idprod' => $idprod]);
                    $stock = $stmt_stock->fetchColumn();
                    
                    if ($stock < $quantite_servie) {
                        throw new Exception("Stock insuffisant pour {$ligne['libelle']}. Disponible: $stock");
                    }
                    
                    // Mettre à jour la ligne de réquisition
                    $query_update = "UPDATE lignesrecquisition SET quantite_servie = :qte_servie
                                    WHERE idrequisition = :idreq AND idprodpharma = :idprod";
                    $stmt_update = $conn_base->prepare($query_update);
                    $stmt_update->execute([
                        ':qte_servie' => $quantite_servie,
                        ':idreq' => $idrequisition,
                        ':idprod' => $idprod
                    ]);
                    
                    // Mettre à jour le stock de l'officine destinataire
                    $query_check = "SELECT quantite FROM stockpharma WHERE idprodpharma = :idprod AND idofficine = :idofficine";
                    $stmt_check = $conn_base->prepare($query_check);
                    $stmt_check->execute([
                        ':idprod' => $idprod,
                        ':idofficine' => $requisition['idofficine']
                    ]);
                    $stock_existant = $stmt_check->fetchColumn();
                    
                    if ($stock_existant === false) {
                        $query_insert = "INSERT INTO stockpharma (idprodpharma, idofficine, quantite, date_derniere_maj)
                                        VALUES (:idprod, :idofficine, :qte, NOW())";
                        $stmt_insert = $conn_base->prepare($query_insert);
                        $stmt_insert->execute([
                            ':idprod' => $idprod,
                            ':idofficine' => $requisition['idofficine'],
                            ':qte' => $quantite_servie
                        ]);
                    } else {
                        $query_update_off = "UPDATE stockpharma SET quantite = quantite + :qte, date_derniere_maj = NOW()
                                           WHERE idprodpharma = :idprod AND idofficine = :idofficine";
                        $stmt_update_off = $conn_base->prepare($query_update_off);
                        $stmt_update_off->execute([
                            ':qte' => $quantite_servie,
                            ':idprod' => $idprod,
                            ':idofficine' => $requisition['idofficine']
                        ]);
                    }
                    
                    // Déduire du stock du dépôt central (idofficine = 1)
                    $query_deduct = "UPDATE stockpharma SET quantite = quantite - :qte, date_derniere_maj = NOW()
                                    WHERE idprodpharma = :idprod AND idofficine = 1";
                    $stmt_deduct = $conn_base->prepare($query_deduct);
                    $stmt_deduct->execute([
                        ':qte' => $quantite_servie,
                        ':idprod' => $idprod
                    ]);
                }
            }
            
            // Mettre à jour le statut de la réquisition
            $query_requisition = "UPDATE requisition SET statut = 'servi', date_traitement = NOW(),
                                 traiteur = :traiteur, observation = :observation
                                 WHERE idrequisition = :idreq";
            $stmt_requisition = $conn_base->prepare($query_requisition);
            $stmt_requisition->execute([
                ':traiteur' => $_SESSION['user_id'],
                ':observation' => $observation ?: null,
                ':idreq' => $idrequisition
            ]);
            
            $conn_base->commit();
            $success = "Réquisition servie avec succès !";
            
        } elseif (isset($_POST['refuser'])) {
            $observation = trim($_POST['observation'] ?? 'Réquisition refusée');
            
            $query_refuse = "UPDATE requisition SET statut = 'refuse', date_traitement = NOW(),
                            traiteur = :traiteur, observation = :observation
                            WHERE idrequisition = :idreq";
            $stmt_refuse = $conn_base->prepare($query_refuse);
            $stmt_refuse->execute([
                ':traiteur' => $_SESSION['user_id'],
                ':observation' => $observation,
                ':idreq' => $idrequisition
            ]);
            
            $conn_base->commit();
            $success = "Réquisition refusée.";
        }
        
    } catch (Exception $e) {
        $conn_base->rollBack();
        $error = "Erreur : " . $e->getMessage();
    }
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-truck me-2" style="color: #198754;"></i>Traitement de réquisition - Dépôt Central</h4>
    <a href="index.php?page=pharmacie&action=depot-central" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> <?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle"></i> <?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- ========================================= -->
<!-- EN-TÊTE RÉQUISITION -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">N° Réquisition</small><strong class="fs-5"><?= htmlspecialchars($requisition['numero_requisition']) ?></strong></div></div>
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">Officine</small><strong><?= htmlspecialchars($requisition['officine_nom']) ?></strong></div></div>
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">Date demande</small><strong><?= formatDateTime($requisition['date_requisition']) ?></strong></div></div>
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">Demandé par</small><strong><?= htmlspecialchars($requisition['user_prenom'] . ' ' . $requisition['user_nom']) ?></strong></div></div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES RAPIDES -->
<!-- ========================================= -->
<?php
$total_produits = count($lignes);
$total_demande = array_sum(array_column($lignes, 'quantite_demandee'));
$valeur_totale = array_sum(array_map(function($l) { return $l['quantite_demandee'] * $l['prix_achat']; }, $lignes));
$stock_insuffisant = count(array_filter($lignes, function($l) { return $l['stock_total'] < $l['quantite_demandee']; }));
?>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1fae5;"><i class="bi bi-capsule" style="color: #198754;"></i></div><div class="stat-value"><?= $total_produits ?></div><div class="stat-small">Produits</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #dbeafe;"><i class="bi bi-box" style="color: #2563eb;"></i></div><div class="stat-value"><?= $total_demande ?></div><div class="stat-small">Unités demandées</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #fef3c7;"><i class="bi bi-currency-dollar" style="color: #f59e0b;"></i></div><div class="stat-value"><?= formatMoney($valeur_totale) ?></div><div class="stat-small">Valeur totale</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: <?= $stock_insuffisant > 0 ? '#fee2e2' : '#d1fae5' ?>;"><i class="bi bi-exclamation-triangle" style="color: <?= $stock_insuffisant > 0 ? '#dc2626' : '#198754' ?>;"></i></div><div class="stat-value"><?= $stock_insuffisant ?></div><div class="stat-small">Stock insuffisant</div></div></div></div>
</div>

<!-- ========================================= -->
<!-- FORMULAIRE DE TRAITEMENT -->
<!-- ========================================= -->
<form method="POST" id="traitementForm">
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Produits à servir</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr>
                        <th>Produit</th><th>Code</th><th class="text-center">Demandé</th><th class="text-center">Stock central</th>
                        <th class="text-center">À servir</th><th class="text-center">Prix unit.</th><th class="text-center">Valeur</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($lignes as $l): 
                            $stock_suffisant = $l['stock_total'] >= $l['quantite_demandee'];
                            $valeur = $l['quantite_demandee'] * $l['prix_achat'];
                        ?>
                        <tr class="<?= !$stock_suffisant ? 'table-warning' : '' ?>">
                            <td><strong><?= htmlspecialchars($l['libelle']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($l['voie'] ?? '') ?></small></td>
                            <td><code><?= htmlspecialchars($l['code']) ?></code></td>
                            <td class="text-center fw-bold"><?= $l['quantite_demandee'] ?> <?= $l['unite'] ?></td>
                            <td class="text-center"><span class="badge <?= $stock_suffisant ? 'bg-success' : 'bg-danger' ?>"><?= $l['stock_total'] ?></span></td>
                            <td class="text-center"><input type="number" name="quantite_servie[<?= $l['idprodpharma'] ?>]" class="form-control form-control-sm text-center" style="width: 100px;" min="0" max="<?= min($l['stock_total'], $l['quantite_demandee']) ?>" value="<?= $stock_suffisant ? $l['quantite_demandee'] : 0 ?>" onchange="updateTotal(this, <?= $l['prix_achat'] ?>)"></td>
                            <td class="text-center"><?= formatMoney($l['prix_achat']) ?></td>
                            <td class="text-center fw-bold valeur-ligne" data-prix="<?= $l['prix_achat'] ?>"><?= formatMoney($valeur) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light"><tr><td colspan="6" class="text-end fw-bold">TOTAL À SERVIR:</td><td class="text-center fw-bold" id="totalServi"><?= formatMoney($valeur_totale) ?></td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8"><div class="mb-3"><label class="form-label">Observation</label><textarea name="observation" class="form-control" rows="2" placeholder="Commentaire sur le traitement de cette réquisition..."></textarea></div></div>
        <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">
            <button type="submit" name="refuser" class="btn btn-danger" onclick="return confirm('Confirmer le refus de cette réquisition ?')"><i class="bi bi-x-circle"></i> Refuser</button>
            <button type="submit" name="servir" class="btn btn-success btn-lg" onclick="return confirm('Confirmer la livraison de ces produits ?')"><i class="bi bi-check-circle"></i> Servir la réquisition</button>
        </div>
    </div>
</form>

<script>
function updateTotal(input, prix) {
    const row = input.closest('tr');
    const quantite = parseInt(input.value) || 0;
    const nouvelleValeur = quantite * prix;
    row.querySelector('.valeur-ligne').textContent = formatMoney(nouvelleValeur);
    let total = 0;
    document.querySelectorAll('.valeur-ligne').forEach(cell => {
        total += parseFloat(cell.textContent.replace(/[^\d,-]/g, '').replace(',', '.')) || 0;
    });
    document.getElementById('totalServi').textContent = formatMoney(total);
}
function formatMoney(value) { return value.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' FC'; }
</script>