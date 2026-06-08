<?php
/**
 * Module Pharmacie - Gestion des officines
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

$query_officines = "SELECT * FROM officine WHERE idsite = :idsite AND actif = 1 ORDER BY nom";
$stmt_officines = $conn_base->prepare($query_officines);
$stmt_officines->bindParam(':idsite', $_SESSION['site_id']);
$stmt_officines->execute();
$officines = $stmt_officines->fetchAll(PDO::FETCH_ASSOC);

$idofficine = $_GET['idofficine'] ?? ($officines[0]['idofficine'] ?? null);

$date_debut = $_GET['date_debut'] ?? date('Y-m-d');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

$stats = [];
if ($idofficine) {
    $query_stats = "SELECT 
        COUNT(DISTINCT pp.idpharma_presc) as total_prescriptions,
        COUNT(DISTINCT CASE WHEN pp.statut_execution = 'en_attente' THEN pp.idpharma_presc END) as en_attente,
        COUNT(DISTINCT CASE WHEN pp.statut_execution = 'acheve' THEN pp.idpharma_presc END) as delivrees,
        COUNT(DISTINCT p.idpatient) as patients,
        SUM(pp.montant_total) as chiffre_affaire
        FROM pharma_presc pp
        JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
        JOIN sejour s ON ss.idsejour = s.idsejour
        JOIN patient p ON s.idpatient = p.idpatient
        WHERE pp.source_prescription = 'csk_services'
        AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin";
    
    $stmt_stats = $conn_base->prepare($query_stats);
    $stmt_stats->bindParam(':date_debut', $date_debut);
    $stmt_stats->bindParam(':date_fin', $date_fin);
    $stmt_stats->execute();
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
}

$prescriptions = [];
if ($idofficine) {
    $query = "SELECT 
        p.idpatient, p.numero_dossier, p.nom, p.prenom, p.date_naissance, p.type_patient,
        s.idsejour,
        COUNT(DISTINCT pp.idpharma_presc) as nb_prescriptions,
        SUM(pp.montant_total) as montant_total,
        MIN(pp.date_prescription) as date_prescription,
        MAX(pp.urgent) as urgent
        FROM patient p
        JOIN sejour s ON p.idpatient = s.idpatient
        JOIN sous_sejour ss ON s.idsejour = ss.idsejour
        JOIN pharma_presc pp ON ss.idsous_sejour = pp.idsous_sejour
        WHERE pp.statut_execution = 'en_attente'
        AND s.idsite = :idsite
        AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin";
    
    if (!empty($search)) {
        $query .= " AND (p.nom LIKE :search OR p.prenom LIKE :search OR p.numero_dossier LIKE :search)";
    }
    
    $query .= " GROUP BY p.idpatient, s.idsejour ORDER BY urgent DESC, MIN(pp.date_prescription) ASC";
    
    $stmt = $conn_base->prepare($query);
    $stmt->bindParam(':idsite', $_SESSION['site_id']);
    $stmt->bindParam(':date_debut', $date_debut);
    $stmt->bindParam(':date_fin', $date_fin);
    
    if (!empty($search)) {
        $search_param = "%$search%";
        $stmt->bindParam(':search', $search_param);
    }
    
    $stmt->execute();
    $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-store me-2" style="color: #198754;"></i>Gestion des Officines</h4>
    <a href="index.php?page=pharmacie&action=dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2"></i> Dashboard</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="pharmacie">
            <input type="hidden" name="action" value="officine">
            
            <div class="col-md-3">
                <label class="form-label">Officine</label>
                <select name="idofficine" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($officines as $off): ?>
                    <option value="<?= $off['idofficine'] ?>" <?= $off['idofficine'] == $idofficine ? 'selected' : '' ?>><?= htmlspecialchars($off['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2"><label class="form-label">Date début</label><input type="date" name="date_debut" class="form-control" value="<?= $date_debut ?>"></div>
            <div class="col-md-2"><label class="form-label">Date fin</label><input type="date" name="date_fin" class="form-control" value="<?= $date_fin ?>"></div>
            <div class="col-md-3"><label class="form-label">Recherche</label><input type="text" name="search" class="form-control" placeholder="Patient, N° dossier..." value="<?= htmlspecialchars($search) ?>"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Filtrer</button></div>
        </form>
    </div>
</div>

<?php if ($idofficine): ?>
<div class="row g-3 mb-4">
    <div class="col-md-2 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1fae5;"><i class="bi bi-prescription2" style="color: #198754;"></i></div><div class="stat-value"><?= (int)($stats['total_prescriptions'] ?? 0) ?></div><div class="stat-small">Prescriptions</div></div></div></div>
    <div class="col-md-2 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #fee2e2;"><i class="bi bi-clock" style="color: #dc2626;"></i></div><div class="stat-value"><?= (int)($stats['en_attente'] ?? 0) ?></div><div class="stat-small">En attente</div></div></div></div>
    <div class="col-md-2 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #dbeafe;"><i class="bi bi-check-circle" style="color: #2563eb;"></i></div><div class="stat-value"><?= (int)($stats['delivrees'] ?? 0) ?></div><div class="stat-small">Délivrées</div></div></div></div>
    <div class="col-md-3 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #fef3c7;"><i class="bi bi-people" style="color: #f59e0b;"></i></div><div class="stat-value"><?= (int)($stats['patients'] ?? 0) ?></div><div class="stat-small">Patients servis</div></div></div></div>
    <div class="col-md-3 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1e7dd;"><i class="bi bi-currency-dollar" style="color: #198754;"></i></div><div class="stat-value"><?= formatMoney($stats['chiffre_affaire'] ?? 0) ?></div><div class="stat-small">Chiffre d'affaire</div></div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>Prescriptions en attente de traitement</h6>
        <a href="index.php?page=pharmacie&action=stock-officine&idofficine=<?= $idofficine ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-boxes"></i> Voir le stock</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($prescriptions)): ?>
            <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2"></i><p class="mb-0">Aucune prescription en attente pour cette période</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>N° Dossier</th><th>Patient</th><th>Type</th><th>Date prescription</th><th class="text-center">Nb produits</th><th class="text-center">Montant</th><th>Urgence</th><th class="text-center">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($prescriptions as $p): ?>
                        <tr class="<?= $p['urgent'] ? 'table-danger' : '' ?>">
                            <td><code><?= htmlspecialchars($p['numero_dossier']) ?></code></td>
                            <td><strong><?= htmlspecialchars($p['nom'] . ' ' . $p['prenom']) ?></strong><br><small class="text-muted"><?= calculateAge($p['date_naissance']) ?> ans</small></td>
                            <td><span class="badge <?= $p['type_patient'] === 'prive' ? 'bg-warning' : 'bg-success' ?>"><?= $p['type_patient'] === 'prive' ? 'Privé' : 'Conventionné' ?></span></td>
                            <td><?= formatDateTime($p['date_prescription']) ?></td>
                            <td class="text-center"><span class="badge bg-primary"><?= $p['nb_prescriptions'] ?></span></td>
                            <td class="text-center fw-bold"><?= formatMoney($p['montant_total']) ?></td>
                            <td><?= getPharmaUrgenceBadge(!empty($p['urgent'])) ?></td>
                            <td class="text-center"><a href="index.php?page=pharmacie&action=traiter-prescription&sejour_id=<?= $p['idsejour'] ?>&idofficine=<?= $idofficine ?>" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Traiter</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>