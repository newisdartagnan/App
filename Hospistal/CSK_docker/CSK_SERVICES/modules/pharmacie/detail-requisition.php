<?php
/**
 * Module Pharmacie - Détail d'une réquisition
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

$idrequisition = $_GET['id'] ?? null;

if (!$idrequisition) {
    redirect('index.php?page=pharmacie&action=requisition');
}

// =============================================
// INFOS RÉQUISITION
// =============================================
$query = "SELECT r.*, o.nom as officine_nom, o.idsite,
                 u.nom as user_nom, u.prenom as user_prenom,
                 ur.nom as traiteur_nom, ur.prenom as traiteur_prenom
          FROM requisition r
          JOIN officine o ON r.idofficine = o.idofficine
          JOIN utilisateur u ON r.idutilisateur = u.idutilisateur
          LEFT JOIN utilisateur ur ON r.traiteur = ur.idutilisateur
          WHERE r.idrequisition = :id";
$stmt = $conn_base->prepare($query);
$stmt->bindParam(':id', $idrequisition);
$stmt->execute();
$requisition = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$requisition) {
    redirect('index.php?page=pharmacie&action=requisition');
}

// =============================================
// LIGNES DE LA RÉQUISITION
// =============================================
$query_lignes = "SELECT l.*, p.libelle, p.code, p.prix_achat, p.prix_vente_externe,
                        p.type_produit, p.idvoie_prod, p.idunite,
                        v.nom as voie, u.nom as unite, f.nom as famille
                 FROM lignesrecquisition l
                 JOIN prodpharma p ON l.idprodpharma = p.idprodpharma
                 LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
                 LEFT JOIN voie_prod v ON p.idvoie_prod = v.idvoie_prod
                 LEFT JOIN unite u ON p.idunite = u.idunite
                 WHERE l.idrequisition = :id
                 ORDER BY p.libelle";
$stmt_lignes = $conn_base->prepare($query_lignes);
$stmt_lignes->bindParam(':id', $idrequisition);
$stmt_lignes->execute();
$lignes = $stmt_lignes->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// CALCULS
// =============================================
$total_produits = count($lignes);
$total_quantite = array_sum(array_column($lignes, 'quantite_demandee'));
$valeur_totale = 0;
foreach ($lignes as $l) {
    $valeur_totale += $l['quantite_demandee'] * $l['prix_achat'];
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-file-text me-2" style="color: #198754;"></i>Détail réquisition <?= htmlspecialchars($requisition['numero_requisition']) ?></h4>
    <div class="btn-group">
        <a href="index.php?page=pharmacie&action=requisition&idofficine=<?= $requisition['idofficine'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour</a>
        <?php if ($requisition['statut'] === 'en_attente'): ?>
        <button class="btn btn-warning btn-sm" onclick="printRequisition()"><i class="bi bi-printer"></i> Imprimer</button>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================= -->
<!-- EN-TÊTE RÉQUISITION -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">Officine</small><strong><?= htmlspecialchars($requisition['officine_nom']) ?></strong></div></div>
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">Date</small><strong><?= formatDateTime($requisition['date_requisition']) ?></strong></div></div>
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">Demandé par</small><strong><?= htmlspecialchars($requisition['user_prenom'] . ' ' . $requisition['user_nom']) ?></strong></div></div>
            <div class="col-md-3"><div class="mb-3"><small class="text-muted d-block">Statut</small><?php $badge = match($requisition['statut']) { 'en_attente' => 'bg-warning text-dark', 'servi' => 'bg-success', 'refuse' => 'bg-danger', default => 'bg-secondary' }; ?>
                <span class="badge <?= $badge ?>" style="font-size: 0.9rem;"><?= match($requisition['statut']) { 'en_attente' => 'En attente', 'servi' => 'Servie', 'refuse' => 'Refusée', default => $requisition['statut'] } ?></span>
            </div></div>
        </div>
        <?php if ($requisition['statut'] !== 'en_attente' && $requisition['traiteur']): ?>
        <div class="row mt-2 pt-2 border-top"><div class="col-md-6"><small class="text-muted d-block">Traitée par</small><strong><?= htmlspecialchars($requisition['traiteur_prenom'] . ' ' . $requisition['traiteur_nom']) ?></strong></div>
        <div class="col-md-6"><small class="text-muted d-block">Date traitement</small><strong><?= formatDateTime($requisition['date_traitement']) ?></strong></div></div>
        <?php endif; ?>
        <?php if (!empty($requisition['observation'])): ?>
        <div class="row mt-2 pt-2 border-top"><div class="col-12"><small class="text-muted d-block">Observation</small><p class="mb-0"><?= nl2br(htmlspecialchars($requisition['observation'])) ?></p></div></div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES RAPIDES -->
<!-- ========================================= -->
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1fae5;"><i class="bi bi-capsule" style="color: #198754;"></i></div><div class="stat-value"><?= $total_produits ?></div><div class="stat-small">Produits différents</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #dbeafe;"><i class="bi bi-box" style="color: #2563eb;"></i></div><div class="stat-value"><?= $total_quantite ?></div><div class="stat-small">Unités totales</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #fef3c7;"><i class="bi bi-currency-dollar" style="color: #f59e0b;"></i></div><div class="stat-value"><?= formatMoney($valeur_totale) ?></div><div class="stat-small">Valeur totale</div></div></div></div>
</div>

<!-- ========================================= -->
<!-- LISTE DES PRODUITS -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Produits demandés</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr>
                    <th>Produit</th><th>Code</th><th>Type</th><th>Famille</th><th>Voie</th>
                    <th class="text-center">Quantité demandée</th><th class="text-center">Quantité servie</th>
                    <th class="text-center">Prix unitaire</th><th class="text-center">Valeur</th>
                </table></thead>
                <tbody>
                    <?php foreach ($lignes as $l): 
                        $valeur = $l['quantite_demandee'] * $l['prix_achat'];
                        $servie_class = $l['quantite_servie'] ? ($l['quantite_servie'] >= $l['quantite_demandee'] ? 'bg-success' : 'bg-warning') : 'bg-secondary';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($l['libelle']) ?></strong></td>
                        <td><code><?= htmlspecialchars($l['code']) ?></code></td>
                        <td><?= getPharmaTypeProduitBadge($l['type_produit']) ?></td>
                        <td><?= htmlspecialchars($l['famille'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($l['voie'] ?? '-') ?></td>
                        <td class="text-center fw-bold"><?= $l['quantite_demandee'] ?> <?= $l['unite'] ?></td>
                        <td class="text-center"><span class="badge <?= $servie_class ?>"><?= $l['quantite_servie'] ?? 0 ?></span></td>
                        <td class="text-center"><?= formatMoney($l['prix_achat']) ?></td>
                        <td class="text-center fw-bold"><?= formatMoney($valeur) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light"><tr><td colspan="8" class="text-end fw-bold">VALEUR TOTALE:</td><td class="text-center fw-bold"><?= formatMoney($valeur_totale) ?></td></tr></tfoot>
            </table>
        </div>
    </div>
</div>

<script>
function printRequisition() {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html><head><title>Réquisition <?= $requisition['numero_requisition'] ?></title>
        <style>body{font-family:Arial;padding:20px}.header{text-align:center;margin-bottom:30px}.header h1{color:#198754;margin:0}.header h2{margin:5px 0}.info{margin-bottom:20px}.info p{margin:5px 0}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f2f2f2}.text-center{text-align:center}.footer{margin-top:30px;text-align:right}</style>
        </head><body>
        <div class="header"><h1>RÉQUISITION N° <?= $requisition['numero_requisition'] ?></h1><h2><?= htmlspecialchars($requisition['officine_nom']) ?></h2></div>
        <div class="info"><p><strong>Date:</strong> <?= formatDateTime($requisition['date_requisition']) ?></p><p><strong>Demandé par:</strong> <?= htmlspecialchars($requisition['user_prenom'] . ' ' . $requisition['user_nom']) ?></p></div>
        <table><thead><tr><th>Produit</th><th>Code</th><th class="text-center">Quantité</th></tr></thead><tbody>
        <?php foreach ($lignes as $l): ?><tr><td><?= htmlspecialchars($l['libelle']) ?></td><td><?= htmlspecialchars($l['code']) ?></td><td class="text-center"><?= $l['quantite_demandee'] ?></td></tr><?php endforeach; ?>
        </tbody></table><div class="footer"><p>Signature: ________________________</p></div>
        </body></html>
    `);
    printWindow.document.close();
    printWindow.print();
}
</script>