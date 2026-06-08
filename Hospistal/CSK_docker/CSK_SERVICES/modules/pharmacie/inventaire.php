<?php
/**
 * Module Pharmacie - Inventaire des stocks
 * Ajustement et comptage physique des stocks
 * CORRIGÉ - Suppression des colonnes inexistantes
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

// =============================================
// RÉCUPÉRATION DES OFFICINES
// =============================================
$query_off = "SELECT * FROM officine WHERE idsite = :idsite AND actif = 1 ORDER BY nom";
$stmt_off = $conn_base->prepare($query_off);
$stmt_off->bindParam(':idsite', $_SESSION['site_id']);
$stmt_off->execute();
$officines = $stmt_off->fetchAll(PDO::FETCH_ASSOC);

$idofficine = $_GET['idofficine'] ?? ($officines[0]['idofficine'] ?? null);

// =============================================
// RÉCUPÉRATION DES PRODUITS AVEC LEUR STOCK - CORRIGÉ
// =============================================
$produits = [];
if ($idofficine) {
    $query = "SELECT 
        p.idprodpharma, 
        p.libelle, 
        p.code, 
        p.type_produit,
        p.forme,
        p.idvoie_prod, 
        v.nom as voie, 
        p.idunite, 
        u.nom as unite,
        p.seuil_alerte, 
        p.seuil_reappro, 
        f.nom as famille,
        COALESCE(sp.quantite, 0) as stock_theorique,
        sp.emplacement
      FROM prodpharma p
      LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
      LEFT JOIN voie_prod v ON p.idvoie_prod = v.idvoie_prod
      LEFT JOIN unite u ON p.idunite = u.idunite
      LEFT JOIN stockpharma sp ON p.idprodpharma = sp.idprodpharma AND sp.idofficine = :idofficine
      WHERE p.actif = 1
      ORDER BY p.libelle";
    $stmt = $conn_base->prepare($query);
    $stmt->bindParam(':idofficine', $idofficine);
    $stmt->execute();
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// =============================================
// TRAITEMENT DE L'AJUSTEMENT
// =============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajuster_stock'])) {
    $idprodpharma = $_POST['idprodpharma'] ?? null;
    $nouveau_stock = (int)($_POST['nouveau_stock'] ?? 0);
    $motif = trim($_POST['motif'] ?? '');
    $observation = trim($_POST['observation'] ?? '');
    
    if (!$idprodpharma || $nouveau_stock < 0) {
        $error = "Données invalides.";
    } elseif (empty($motif)) {
        $error = "Veuillez préciser le motif de l'ajustement.";
    } else {
        try {
            $conn_base->beginTransaction();
            
            // Récupérer l'ancien stock
            $query_old = "SELECT quantite FROM stockpharma 
                          WHERE idprodpharma = :idprod AND idofficine = :idofficine";
            $stmt_old = $conn_base->prepare($query_old);
            $stmt_old->execute([
                ':idprod' => $idprodpharma,
                ':idofficine' => $idofficine
            ]);
            $old = $stmt_old->fetch(PDO::FETCH_ASSOC);
            $ancien_stock = $old['quantite'] ?? 0;
            
            // Mettre à jour ou créer le stock
            if ($old) {
                $query_update = "UPDATE stockpharma 
                                SET quantite = :nouveau,
                                    date_derniere_maj = NOW()
                                WHERE idprodpharma = :idprod 
                                AND idofficine = :idofficine";
                $stmt_update = $conn_base->prepare($query_update);
                $stmt_update->execute([
                    ':nouveau' => $nouveau_stock,
                    ':idprod' => $idprodpharma,
                    ':idofficine' => $idofficine
                ]);
            } else {
                $query_insert = "INSERT INTO stockpharma 
                                (idprodpharma, idofficine, quantite, date_derniere_maj)
                                VALUES (:idprod, :idofficine, :nouveau, NOW())";
                $stmt_insert = $conn_base->prepare($query_insert);
                $stmt_insert->execute([
                    ':idprod' => $idprodpharma,
                    ':idofficine' => $idofficine,
                    ':nouveau' => $nouveau_stock
                ]);
            }
            
            // Enregistrer l'ajustement
            $query_ajust = "INSERT INTO inventaire_ajustements 
                           (idprodpharma, idofficine, ancien_stock, nouveau_stock, motif, observation, idutilisateur)
                           VALUES (:idprod, :idofficine, :ancien, :nouveau, :motif, :obs, :user)";
            $stmt_ajust = $conn_base->prepare($query_ajust);
            $stmt_ajust->execute([
                ':idprod' => $idprodpharma,
                ':idofficine' => $idofficine,
                ':ancien' => $ancien_stock,
                ':nouveau' => $nouveau_stock,
                ':motif' => $motif,
                ':obs' => $observation ?: null,
                ':user' => $_SESSION['user_id']
            ]);
            
            $conn_base->commit();
            $success = "Stock ajusté avec succès !";
            
        } catch (Exception $e) {
            $conn_base->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// =============================================
// HISTORIQUE DES AJUSTEMENTS
// =============================================
$ajustements = [];
if ($idofficine) {
    $query_hist = "SELECT a.*, p.libelle as produit_libelle, p.code as produit_code,
                          u.nom as user_nom, u.prenom as user_prenom
                   FROM inventaire_ajustements a
                   JOIN prodpharma p ON a.idprodpharma = p.idprodpharma
                   JOIN utilisateur u ON a.idutilisateur = u.idutilisateur
                   WHERE a.idofficine = :idofficine
                   ORDER BY a.date_ajustement DESC
                   LIMIT 30";
    $stmt_hist = $conn_base->prepare($query_hist);
    $stmt_hist->bindParam(':idofficine', $idofficine);
    $stmt_hist->execute();
    $ajustements = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clipboard-data me-2" style="color: #198754;"></i>Inventaire et ajustement des stocks</h4>
    <a href="index.php?page=pharmacie&action=dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2"></i> Dashboard</a>
</div>

<!-- ========================================= -->
<!-- SÉLECTION OFFICINE -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="pharmacie">
            <input type="hidden" name="action" value="inventaire">
            <div class="col-md-4"><label class="form-label">Officine</label><select name="idofficine" class="form-select" onchange="this.form.submit()"><?php foreach ($officines as $off): ?><option value="<?= $off['idofficine'] ?>" <?= $off['idofficine'] == $idofficine ? 'selected' : '' ?>><?= htmlspecialchars($off['nom']) ?></option><?php endforeach; ?></select></div>
        </form>
    </div>
</div>

<?php if ($idofficine): ?>
<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> <?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle"></i> <?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- ========================================= -->
<!-- LISTE DES PRODUITS À INVENTORIER -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Produits à inventorier</h6>
        <div><input type="text" id="searchProduit" class="form-control form-control-sm" placeholder="Rechercher..." style="width: 250px;"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="inventaireTable">
                <thead class="table-light"><tr>
                    <th>Produit</th><th>Code</th><th>Type</th><th>Famille</th><th>Voie</th>
                    <th class="text-center">Stock théorique</th><th class="text-center">Seuil alerte</th><th>Emplacement</th><th class="text-center">Actions</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($produits as $p): 
                        $alerte_class = $p['stock_theorique'] <= $p['seuil_alerte'] ? 'table-warning' : '';
                        if ($p['stock_theorique'] == 0) $alerte_class = 'table-danger';
                    ?>
                    <tr class="<?= $alerte_class ?>">
                        <td><strong><?= htmlspecialchars($p['libelle']) ?></strong></td>
                        <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                        <td><?= getPharmaTypeProduitBadge($p['type_produit']) ?></td>
                        <td><?= htmlspecialchars($p['famille'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($p['voie'] ?? '-') ?></td>
                        <td class="text-center fw-bold"><?= $p['stock_theorique'] ?> <?= $p['unite'] ?? '' ?></td>
                        <td class="text-center"><?= $p['seuil_alerte'] ?></td>
                        <td><?= htmlspecialchars($p['emplacement'] ?? '-') ?></td>
                        <td class="text-center"><button class="btn btn-sm btn-warning" onclick="ouvrirModalAjustement(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="bi bi-pencil-square"></i> Ajuster</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- HISTORIQUE DES AJUSTEMENTS -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historique des ajustements</h6></div>
    <div class="card-body p-0">
        <?php if (empty($ajustements)): ?>
            <div class="text-center text-muted py-4"><i class="bi bi-archive fs-1 d-block mb-2"></i><p class="mb-0">Aucun ajustement enregistré</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>Date</th><th>Produit</th><th class="text-center">Ancien stock</th><th class="text-center">Nouveau stock</th><th class="text-center">Écart</th><th>Motif</th><th>Effectué par</th></tr></thead><tbody>
                <?php foreach ($ajustements as $a): $ecart = $a['nouveau_stock'] - $a['ancien_stock']; $ecart_class = $ecart > 0 ? 'text-success' : ($ecart < 0 ? 'text-danger' : ''); ?>
                <tr><td><?= formatDateTime($a['date_ajustement']) ?></td>
                    <td><strong><?= htmlspecialchars($a['produit_libelle']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($a['produit_code']) ?></small></td>
                    <td class="text-center"><?= $a['ancien_stock'] ?></td>
                    <td class="text-center"><?= $a['nouveau_stock'] ?></td>
                    <td class="text-center <?= $ecart_class ?>"><?= $ecart > 0 ? '+' : '' ?><?= $ecart ?></td>
                    <td><span class="badge bg-info"><?= htmlspecialchars($a['motif']) ?></span></td>
                    <td><?= htmlspecialchars($a['user_prenom'] . ' ' . $a['user_nom']) ?></td>
                </tr>
                <?php if (!empty($a['observation'])): ?><tr class="table-light"><td colspan="7" class="small text-muted"><i class="bi bi-chat"></i> <?= htmlspecialchars($a['observation']) ?></td></tr><?php endif; ?>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================= -->
<!-- MODAL D'AJUSTEMENT -->
<!-- ========================================= -->
<div class="modal fade" id="ajustementModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil-square"></i> Ajustement de stock</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><div class="modal-body"><input type="hidden" name="idprodpharma" id="modal_idprodpharma">
    <div class="mb-3"><label class="form-label">Produit</label><input type="text" class="form-control" id="modal_produit" readonly></div>
    <div class="row"><div class="col-md-6"><div class="mb-3"><label class="form-label">Stock théorique actuel</label><input type="text" class="form-control" id="modal_stock_actuel" readonly></div></div>
    <div class="col-md-6"><div class="mb-3"><label class="form-label">Nouveau stock *</label><input type="number" name="nouveau_stock" id="modal_nouveau_stock" class="form-control" min="0" required></div></div></div>
    <div class="mb-3"><label class="form-label">Motif de l'ajustement *</label><select name="motif" class="form-select" required><option value="">-- Sélectionner --</option><option value="inventaire_physique">Inventaire physique</option><option value="correction_erreur">Correction d'erreur</option><option value="reception_commande">Réception de commande</option><option value="retour_patient">Retour patient</option><option value="perte_constatee">Perte constatée</option><option value="autre">Autre</option></select></div>
    <div class="mb-3"><label class="form-label">Observation</label><textarea name="observation" class="form-control" rows="3" placeholder="Informations complémentaires..."></textarea></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="ajuster_stock" class="btn btn-warning"><i class="bi bi-check-circle"></i> Valider l'ajustement</button></div></form>
    </div></div>
</div>

<script>
document.getElementById('searchProduit').addEventListener('keyup', function() {
    const search = this.value.toLowerCase();
    document.querySelectorAll('#inventaireTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
    });
});
function ouvrirModalAjustement(produit) {
    document.getElementById('modal_idprodpharma').value = produit.idprodpharma;
    document.getElementById('modal_produit').value = produit.libelle + ' (' + produit.code + ')';
    document.getElementById('modal_stock_actuel').value = produit.stock_theorique + ' ' + (produit.unite || '');
    document.getElementById('modal_nouveau_stock').value = produit.stock_theorique;
    new bootstrap.Modal(document.getElementById('ajustementModal')).show();
}
</script>
<?php endif; ?>