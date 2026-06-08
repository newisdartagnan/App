<?php
/**
 * Module Pharmacie - Traitement des prescriptions
 * Liste des prescriptions d'un patient à traiter
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

$sejour_id = $_GET['sejour_id'] ?? null;
$idofficine = $_GET['idofficine'] ?? null;

if (!$sejour_id || !$idofficine) {
    redirect('index.php?page=pharmacie&action=officine');
}

// =============================================
// INFOS PATIENT ET SÉJOUR
// =============================================
$query_patient = "SELECT p.*, s.idsejour, s.date_entree, s.type_sejour,
                         soc.nom as societe_nom, cat.nom as categorie_nom
                  FROM patient p
                  JOIN sejour s ON p.idpatient = s.idpatient
                  LEFT JOIN societe soc ON p.idsociete = soc.idsociete
                  LEFT JOIN categorie cat ON p.idcategorie = cat.idcategorie
                  WHERE s.idsejour = ?";
$stmt_patient = $conn_base->prepare($query_patient);
$stmt_patient->execute([$sejour_id]);
$patient = $stmt_patient->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    redirect('index.php?page=pharmacie&action=officine');
}

// =============================================
// LISTE DES PRESCRIPTIONS
// =============================================
$query = "SELECT 
            pp.*, 
            pr.libelle as produit_libelle, 
            pr.code as produit_code,
            pr.idvoie_prod, 
            v.nom as voie_nom,
            pr.idunite, 
            u.nom as unite_nom, 
            pr.prix_vente_externe,
            pr.prix_achat,
            sp.quantite as stock_disponible,
            pres.nom as prescripteur_nom, 
            pres.prenom as prescripteur_prenom
          FROM pharma_presc pp
          JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
          LEFT JOIN voie_prod v ON pr.idvoie_prod = v.idvoie_prod
          LEFT JOIN unite u ON pr.idunite = u.idunite
          JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
          JOIN sejour s ON ss.idsejour = s.idsejour
          LEFT JOIN utilisateur pres ON pp.prescripteur = pres.idutilisateur
          LEFT JOIN stockpharma sp ON pr.idprodpharma = sp.idprodpharma AND sp.idofficine = :idofficine
          WHERE s.idsejour = :sejour_id
          AND pp.statut_execution = 'en_attente'
          ORDER BY pp.urgent DESC, pp.date_prescription ASC";

$stmt = $conn_base->prepare($query);
$stmt->execute([
    ':sejour_id' => $sejour_id,
    ':idofficine' => $idofficine
]);
$prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// STATISTIQUES
// =============================================
$total_prescriptions = count($prescriptions);
$total_montant = array_sum(array_column($prescriptions, 'montant_total'));
$urgent_count = count(array_filter($prescriptions, fn($p) => $p['urgent']));
$stock_insuffisant = count(array_filter($prescriptions, fn($p) => ($p['stock_disponible'] ?? 0) < $p['quantite']));
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-prescription2 me-2" style="color: #198754;"></i>
            Traiter les prescriptions
        </h4>
        <p class="text-muted mb-0">
            Patient: <strong><?= htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']) ?></strong> 
            | N° dossier: <?= htmlspecialchars($patient['numero_dossier']) ?>
            <?php if (!empty($patient['societe_nom'])): ?>
                | Société: <?= htmlspecialchars($patient['societe_nom']) ?>
            <?php endif; ?>
        </p>
    </div>
    <a href="index.php?page=pharmacie&action=officine&idofficine=<?= $idofficine ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Retour
    </a>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES RAPIDES -->
<!-- ========================================= -->
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1fae5;"><i class="bi bi-prescription2" style="color: #198754;"></i></div><div class="stat-value"><?= $total_prescriptions ?></div><div class="stat-small">Prescriptions</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #fee2e2;"><i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i></div><div class="stat-value"><?= $urgent_count ?></div><div class="stat-small">Urgentes</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #dbeafe;"><i class="bi bi-box" style="color: #2563eb;"></i></div><div class="stat-value"><?= $stock_insuffisant ?></div><div class="stat-small">Stock insuffisant</div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #fef3c7;"><i class="bi bi-currency-dollar" style="color: #f59e0b;"></i></div><div class="stat-value"><?= formatMoney($total_montant) ?></div><div class="stat-small">Montant total</div></div></div></div>
</div>

<!-- ========================================= -->
<!-- LISTE DES PRESCRIPTIONS -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Produits à délivrer</h6>
        <?php if ($stock_insuffisant > 0): ?>
        <a href="index.php?page=pharmacie&action=requisition&idofficine=<?= $idofficine ?>" class="btn btn-warning btn-sm"><i class="bi bi-file-import"></i> Faire une réquisition</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($prescriptions)): ?>
            <div class="text-center text-muted py-5"><i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i><p class="mb-0">Aucune prescription en attente pour ce patient</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr>
                        <th>Produit</th><th>Code</th><th>Posologie</th><th class="text-center">Quantité</th>
                        <th class="text-center">Stock</th><th class="text-center">Prix unit.</th><th class="text-center">Montant</th>
                        <th>Urgence</th><th class="text-center">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($prescriptions as $p): 
                            $stock_ok = ($p['stock_disponible'] ?? 0) >= $p['quantite'];
                        ?>
                        <tr class="<?= $p['urgent'] ? 'table-danger' : '' ?>">
                            <td><strong><?= htmlspecialchars($p['produit_libelle']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($p['voie_nom'] ?? '') ?></small></td>
                            <td><code><?= htmlspecialchars($p['produit_code']) ?></code></td>
                            <td><small><?= nl2br(htmlspecialchars($p['posologie'] ?? '-')) ?></small></td>
                            <td class="text-center fw-bold"><?= (float)$p['quantite'] ?> <?= htmlspecialchars($p['unite_nom'] ?? '') ?></td>
                            <td class="text-center"><span class="badge <?= $stock_ok ? 'bg-success' : 'bg-danger' ?>"><?= $p['stock_disponible'] ?? 0 ?></span></td>
                            <td class="text-center"><?= formatMoney($p['prix_unitaire']) ?></td>
                            <td class="text-center fw-bold"><?= formatMoney($p['montant_total']) ?></td>
                            <td><?= getPharmaUrgenceBadge(!empty($p['urgent'])) ?></td>
                            <td class="text-center">
                                <?php if ($stock_ok): ?>
                                <a href="index.php?page=pharmacie&action=delivrer&id=<?= $p['idpharma_presc'] ?>&idofficine=<?= $idofficine ?>" class="btn btn-sm btn-success"><i class="bi bi-hand-thumbs-up"></i> Délivrer</a>
                                <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled><i class="bi bi-x-circle"></i> Indisponible</button>
                                <?php endif; ?>
                             </div>
                         </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light"><tr><td colspan="6" class="text-end fw-bold">TOTAL:</td><td class="text-center fw-bold"><?= formatMoney($total_montant) ?></td><td colspan="2"></td></tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================= -->
<!-- INFOS COMPLÉMENTAIRES -->
<!-- ========================================= -->
<div class="row g-3 mt-3">
    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Informations patient</h6></div><div class="card-body"><table class="table table-sm"><tr><th style="width: 150px;">Nom complet</th><td><?= htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']) ?></td></tr><tr><th>N° dossier</th><td><?= htmlspecialchars($patient['numero_dossier']) ?></td></tr><tr><th>Date naissance</th><td><?= formatDate($patient['date_naissance']) ?> (<?= calculateAge($patient['date_naissance']) ?> ans)</td></tr><tr><th>Sexe</th><td><?= $patient['sexe'] === 'M' ? 'Masculin' : 'Féminin' ?></td></tr><?php if (!empty($patient['societe_nom'])): ?><tr><th>Société</th><td><?= htmlspecialchars($patient['societe_nom']) ?></td></tr><?php endif; ?><tr><th>Type patient</th><td><span class="badge <?= $patient['type_patient'] === 'prive' ? 'bg-warning' : 'bg-success' ?>"><?= $patient['type_patient'] === 'prive' ? 'Privé' : 'Conventionné' ?></span></td></tr><tr><th>Date entrée</th><td><?= formatDate($patient['date_entree']) ?></td></tr><tr><th>Séjour</th><td><?= htmlspecialchars($patient['type_sejour']) ?></td></tr></table></div></div></div>
    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-lightbulb me-2"></i>Actions possibles</h6></div><div class="card-body"><div class="list-group"><div class="list-group-item"><div class="d-flex align-items-center"><i class="bi bi-hand-thumbs-up fs-4 me-3 text-success"></i><div><strong>Délivrance</strong><br><small>Cliquez sur "Délivrer" pour chaque produit disponible</small></div></div></div><div class="list-group-item"><div class="d-flex align-items-center"><i class="bi bi-file-import fs-4 me-3 text-warning"></i><div><strong>Réquisition</strong><br><small>Si stock insuffisant, faites une réquisition au dépôt central</small></div></div></div><div class="list-group-item"><div class="d-flex align-items-center"><i class="bi bi-printer fs-4 me-3 text-info"></i><div><strong>Ordonnance</strong><br><small>Imprimez l'ordonnance si nécessaire</small></div></div></div></div></div></div></div>
</div>

<style>
.stat-card { transition: transform 0.2s; }
.stat-card:hover { transform: translateY(-2px); }
.stat-icon-wrapper { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; }
.stat-icon-wrapper i { font-size: 1.2rem; }
.stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
.stat-small { font-size: 0.75rem; color: #6c757d; }
</style>