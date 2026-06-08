<?php
/**
 * Module Pharmacie - Dépôt Central
 * Gestion des entrées en stock au niveau du dépôt central
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

// =============================================
// RÉCUPÉRATION DES DONNÉES
// =============================================
$produits = $conn_base->query("SELECT * FROM prodpharma WHERE actif = 1 ORDER BY libelle")->fetchAll(PDO::FETCH_ASSOC);
$fournisseurs = $conn_base->query("SELECT * FROM fournisseur WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// RÉCUPÉRATION DES RÉQUISITIONS EN ATTENTE
// =============================================
$requisitions = [];
try {
    $query_req = "SELECT r.*, o.nom as officine_nom,
                         COUNT(l.idprodpharma) as nb_produits,
                         SUM(l.quantite_demandee) as total_quantite
                  FROM requisition r
                  JOIN officine o ON r.idofficine = o.idofficine
                  LEFT JOIN lignesrecquisition l ON r.idrequisition = l.idrequisition
                  WHERE r.statut = 'en_attente'
                  GROUP BY r.idrequisition
                  ORDER BY r.date_requisition ASC
                  LIMIT 10";
    $requisitions = $conn_base->query($query_req)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("[Pharmacie] Erreur requisitions: " . $e->getMessage()); }

// =============================================
// TRAITEMENT NOUVELLE ENTRÉE
// =============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_entree'])) {
    try {
        $conn_base->beginTransaction();
        
        $required = ['idprodpharma', 'idfournisseur', 'quantite', 'prix_achat'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) throw new Exception("Le champ '$field' est obligatoire.");
        }
        
        $idprodpharma = (int)$_POST['idprodpharma'];
        $idfournisseur = (int)$_POST['idfournisseur'];
        $quantite = (int)$_POST['quantite'];
        $prix_achat = (float)$_POST['prix_achat'];
        $prix_vente = (float)($_POST['prix_vente'] ?? 0);
        $num_lot = trim($_POST['num_lot'] ?? '');
        $date_expiration = $_POST['date_expiration'] ?? null;
        $observation = trim($_POST['observation'] ?? '');
        
        if ($quantite <= 0) throw new Exception("La quantité doit être supérieure à 0.");
        
        // Enregistrer l'entrée
        $query_entree = "INSERT INTO pharma_entrees 
                        (idprodpharma, idfournisseur, quantite, prix_achat, num_lot, date_expiration, observation, idutilisateur)
                        VALUES (:idprod, :idfourn, :qte, :prix, :lot, :date_exp, :obs, :user)";
        $stmt_entree = $conn_base->prepare($query_entree);
        $stmt_entree->execute([
            ':idprod' => $idprodpharma,
            ':idfourn' => $idfournisseur,
            ':qte' => $quantite,
            ':prix' => $prix_achat,
            ':lot' => $num_lot ?: null,
            ':date_exp' => $date_expiration ?: null,
            ':obs' => $observation ?: null,
            ':user' => $_SESSION['user_id']
        ]);
        
        // Mettre à jour le prix d'achat
        if ($prix_achat > 0) {
            $conn_base->prepare("UPDATE prodpharma SET prix_achat = :prix WHERE idprodpharma = :idprod")
                ->execute([':prix' => $prix_achat, ':idprod' => $idprodpharma]);
        }
        
        // Mettre à jour le prix de vente
        if ($prix_vente > 0) {
            $conn_base->prepare("UPDATE prodpharma SET prix_vente_externe = :prix WHERE idprodpharma = :idprod")
                ->execute([':prix' => $prix_vente, ':idprod' => $idprodpharma]);
        }
        
        $conn_base->commit();
        $success = "Entrée en stock enregistrée avec succès !";
        header("Location: depot-central.php?success=1");
        exit();
        
    } catch (Exception $e) {
        $conn_base->rollBack();
        $error = "Erreur : " . $e->getMessage();
    }
}

// =============================================
// HISTORIQUE DES ENTRÉES
// =============================================
$entrees = [];
try {
    $query_entrees = "SELECT e.*, p.libelle as produit_libelle, p.code as produit_code,
                             f.nom as fournisseur_nom,
                             u.nom as user_nom, u.prenom as user_prenom
                      FROM pharma_entrees e
                      JOIN prodpharma p ON e.idprodpharma = p.idprodpharma
                      LEFT JOIN fournisseur f ON e.idfournisseur = f.idfournisseur
                      LEFT JOIN utilisateur u ON e.idutilisateur = u.idutilisateur
                      ORDER BY e.date_entree DESC
                      LIMIT 30";
    $entrees = $conn_base->query($query_entrees)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("[Pharmacie] Erreur entrees: " . $e->getMessage()); }
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-building me-2" style="color: #198754;"></i>Dépôt Central - Gestion des entrées</h4>
    <a href="index.php?page=pharmacie&action=dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> <?= $success ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle"></i> <?= $error ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- ========================================= -->
<!-- RÉQUISITIONS EN ATTENTE -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-truck me-2"></i>Réquisitions en attente</h6>
        <a href="index.php?page=pharmacie&action=requisition&idofficine=1" class="btn btn-sm btn-outline-primary">Voir toutes</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($requisitions)): ?>
            <div class="text-center text-muted py-4"><i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i><p class="mb-0">Aucune réquisition en attente</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0"><thead class="table-light"><tr><th>N° Réquisition</th><th>Officine</th><th>Date</th><th class="text-center">Produits</th><th class="text-center">Quantité</th><th class="text-center">Actions</th></tr></thead><tbody>
                <?php foreach ($requisitions as $req): ?>
                <tr><td><code><strong><?= htmlspecialchars($req['numero_requisition']) ?></strong></code></td><td><?= htmlspecialchars($req['officine_nom']) ?></td><td><?= formatDateTime($req['date_requisition']) ?></td><td class="text-center"><?= $req['nb_produits'] ?></td><td class="text-center"><?= $req['total_quantite'] ?></td>
                <td class="text-center"><a href="index.php?page=pharmacie&action=traiter-requisition&id=<?= $req['idrequisition'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-truck"></i> Traiter</a></td>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================= -->
<!-- FORMULAIRE NOUVELLE ENTRÉE -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Nouvelle entrée en stock</h6></div>
    <div class="card-body">
        <form method="POST" id="entreeForm">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Produit *</label><select name="idprodpharma" class="form-select" required><option value="">-- Sélectionner un produit --</option><?php foreach ($produits as $p): ?><option value="<?= $p['idprodpharma'] ?>"><?= htmlspecialchars($p['libelle']) ?> (<?= htmlspecialchars($p['code']) ?>)</option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Fournisseur *</label><select name="idfournisseur" class="form-select" required><option value="">-- Sélectionner un fournisseur --</option><?php foreach ($fournisseurs as $f): ?><option value="<?= $f['idfournisseur'] ?>"><?= htmlspecialchars($f['nom']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Quantité *</label><input type="number" name="quantite" class="form-control" min="1" required></div>
                <div class="col-md-3"><label class="form-label">Prix d'achat (FC) *</label><input type="number" name="prix_achat" class="form-control" step="0.01" min="0" required></div>
                <div class="col-md-3"><label class="form-label">Prix de vente (FC)</label><input type="number" name="prix_vente" class="form-control" step="0.01" min="0"></div>
                <div class="col-md-3"><label class="form-label">N° de lot</label><input type="text" name="num_lot" class="form-control" placeholder="Optionnel"></div>
                <div class="col-md-3"><label class="form-label">Date d'expiration</label><input type="date" name="date_expiration" class="form-control" min="<?= date('Y-m-d') ?>"></div>
                <div class="col-md-9"><label class="form-label">Observation</label><input type="text" name="observation" class="form-control" placeholder="Informations complémentaires..."></div>
            </div>
            <div class="mt-3"><button type="submit" name="ajouter_entree" class="btn btn-success"><i class="bi bi-save"></i> Enregistrer l'entrée</button></div>
        </form>
    </div>
</div>

<!-- ========================================= -->
<!-- HISTORIQUE DES ENTRÉES -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historique des entrées</h6>
        <div><input type="text" id="searchEntree" class="form-control form-control-sm" placeholder="Rechercher..." style="width: 200px;"></div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($entrees)): ?>
            <div class="text-center text-muted py-4"><i class="bi bi-archive fs-1 d-block mb-2"></i><p class="mb-0">Aucune entrée enregistrée</p></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-hover mb-0" id="entreesTable"><thead class="table-light"><tr><th>Date</th><th>Produit</th><th>Code</th><th>Fournisseur</th><th class="text-center">Quantité</th><th class="text-center">Prix achat</th><th class="text-center">Valeur</th><th>Lot</th><th>Expiration</th><th>Saisi par</th></tr></thead><tbody>
                <?php foreach ($entrees as $e): $valeur = $e['quantite'] * $e['prix_achat']; $expiree = $e['date_expiration'] && strtotime($e['date_expiration']) < time(); ?>
                <tr><td><?= formatDateTime($e['date_entree']) ?></td><td><strong><?= htmlspecialchars($e['produit_libelle']) ?></strong></td><td><code><?= htmlspecialchars($e['produit_code']) ?></code></td><td><?= htmlspecialchars($e['fournisseur_nom'] ?? '-') ?></td><td class="text-center fw-bold"><?= $e['quantite'] ?></td><td class="text-center"><?= formatMoney($e['prix_achat']) ?></td><td class="text-center fw-bold"><?= formatMoney($valeur) ?></td><td><?= htmlspecialchars($e['num_lot'] ?? '-') ?></td><td><span class="<?= $expiree ? 'text-danger' : '' ?>"><?= $e['date_expiration'] ? formatDate($e['date_expiration']) : '-' ?><?php if ($expiree): ?> <i class="bi bi-exclamation-triangle-fill text-danger"></i><?php endif; ?></span></td><td><?= htmlspecialchars($e['user_prenom'] . ' ' . $e['user_nom']) ?></td>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('searchEntree').addEventListener('keyup', function() {
    const search = this.value.toLowerCase();
    document.querySelectorAll('#entreesTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
    });
});
document.getElementById('entreeForm').addEventListener('submit', function(e) {
    const quantite = parseInt(document.querySelector('input[name="quantite"]').value);
    const prixAchat = parseFloat(document.querySelector('input[name="prix_achat"]').value);
    if (quantite <= 0) { alert('La quantité doit être supérieure à 0.'); e.preventDefault(); }
    if (prixAchat <= 0) { alert('Le prix d\'achat doit être supérieur à 0.'); e.preventDefault(); }
});
</script>