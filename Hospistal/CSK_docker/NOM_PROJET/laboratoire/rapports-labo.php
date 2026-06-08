<?php
// ============================================
// laboratoire/rapports-labo.php
// Rapports laboratoire avec export Excel
// (export via la fonction exportToExcel() de config.php)
// ============================================
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();
if (!hasPermission('laboratoire')) redirect('../index.php');

$database = new Database();
$db = $database->getConnection();

$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin   = $_GET['date_fin']   ?? date('Y-m-d');
$type_export= $_GET['export']     ?? null;

// -------------------------------------------------------
// Requête principale : prescriptions labo sur la période
// -------------------------------------------------------
$query = "SELECT
            ap.idactes_presc,
            ap.date_prescription,
            ap.statut_execution,
            ap.urgent,
            ap.prix_unitaire,
            ap.montant_total,
            ap.indication,
            a.code        AS acte_code,
            a.libelle     AS acte_libelle,
            p.numero_dossier,
            p.nom         AS patient_nom,
            p.prenom      AS patient_prenom,
            p.sexe,
            u.nom         AS prescripteur_nom,
            s.nom         AS service_nom
          FROM actes_presc ap
          JOIN acte        a   ON ap.idacte         = a.idacte
          JOIN sous_sejour ss  ON ap.idsous_sejour   = ss.idsous_sejour
          JOIN sejour      sej ON ss.idsejour        = sej.idsejour
          JOIN patient     p   ON sej.idpatient      = p.idpatient
          LEFT JOIN utilisateur u   ON ap.prescripteur    = u.idutilisateur
          LEFT JOIN unite_med   um  ON ss.idunite_med     = um.idunite_med
          LEFT JOIN services    s   ON um.idservices      = s.idservices
          WHERE sej.idsite = :idsite
          AND   DATE(ap.date_prescription) BETWEEN :date_debut AND :date_fin
          ORDER BY ap.date_prescription DESC";

$stmt = $db->prepare($query);
$stmt->execute([
    ':idsite'     => $_SESSION['site_id'],
    ':date_debut' => $date_debut,
    ':date_fin'   => $date_fin,
]);
$rapports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------
// EXPORT EXCEL — appelle exportToExcel() de config.php
// -------------------------------------------------------
if ($type_export === 'excel') {
    $export_data = array_map(function($r) {
        return [
            'Date'          => formatDateTime($r['date_prescription']),
            'N° Dossier'    => $r['numero_dossier'],
            'Patient'       => $r['patient_nom'] . ' ' . $r['patient_prenom'],
            'Sexe'          => $r['sexe'],
            'Code Acte'     => $r['acte_code'],
            'Acte'          => $r['acte_libelle'],
            'Service'       => $r['service_nom'] ?? '-',
            'Prescripteur'  => $r['prescripteur_nom'] ?? '-',
            'Prix (FC)'     => $r['prix_unitaire'],
            'Statut'        => $r['statut_execution'],
            'Urgent'        => $r['urgent'] ? 'Oui' : 'Non',
        ];
    }, $rapports);

    exportToExcel(
        $export_data,
        'rapport-labo-' . $date_debut . '-' . $date_fin . '.xlsx',
        ['Date','N° Dossier','Patient','Sexe','Code Acte','Acte','Service','Prescripteur','Prix (FC)','Statut','Urgent']
    );
    // exportToExcel() appelle exit() — on n'arrive jamais ici
}

if ($type_export === 'csv') {
    $export_data = array_map(function($r) {
        return [
            formatDateTime($r['date_prescription']),
            $r['numero_dossier'],
            $r['patient_nom'] . ' ' . $r['patient_prenom'],
            $r['sexe'],
            $r['acte_code'],
            $r['acte_libelle'],
            $r['service_nom'] ?? '-',
            $r['prescripteur_nom'] ?? '-',
            $r['prix_unitaire'],
            $r['statut_execution'],
            $r['urgent'] ? 'Oui' : 'Non',
        ];
    }, $rapports);

    exportToCSVDownload(
        $export_data,
        'rapport-labo-' . $date_debut . '-' . $date_fin . '.csv',
        ['Date','N° Dossier','Patient','Sexe','Code Acte','Acte','Service','Prescripteur','Prix (FC)','Statut','Urgent']
    );
}

// -------------------------------------------------------
// Statistiques rapides
// -------------------------------------------------------
$total      = count($rapports);
$termines   = count(array_filter($rapports, fn($r) => $r['statut_execution'] === 'acheve'));
$en_attente = count(array_filter($rapports, fn($r) => $r['statut_execution'] === 'en_attente'));
$urgents    = count(array_filter($rapports, fn($r) => $r['urgent']));
$montant_total = array_sum(array_column($rapports, 'montant_total'));

$pageTitle = "Rapports Laboratoire - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Rapports Laboratoire</h1>
    <a href="index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Retour</a>
</div>

<!-- Filtres + Export -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="form-row">
                <div class="form-group">
                    <label>Date début</label>
                    <input type="date" name="date_debut" class="form-control" value="<?php echo $date_debut; ?>">
                </div>
                <div class="form-group">
                    <label>Date fin</label>
                    <input type="date" name="date_fin" class="form-control" value="<?php echo $date_fin; ?>">
                </div>
                <div class="form-group" style="align-self:flex-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrer</button>
                    <a href="?date_debut=<?php echo $date_debut; ?>&date_fin=<?php echo $date_fin; ?>&export=excel"
                       class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                    <a href="?date_debut=<?php echo $date_debut; ?>&date_fin=<?php echo $date_fin; ?>&export=csv"
                       class="btn btn-info">
                        <i class="fas fa-file-csv"></i> CSV
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Stats rapides -->
<div class="stats-grid mb-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:15px;margin-bottom:20px;">
    <?php
    $stats_cards = [
        ['label'=>'Total',      'val'=>$total,        'color'=>'#3b82f6','icon'=>'fas fa-vial'],
        ['label'=>'Terminés',   'val'=>$termines,     'color'=>'#10b981','icon'=>'fas fa-check-circle'],
        ['label'=>'En attente', 'val'=>$en_attente,   'color'=>'#f59e0b','icon'=>'fas fa-clock'],
        ['label'=>'Urgents',    'val'=>$urgents,      'color'=>'#ef4444','icon'=>'fas fa-exclamation'],
        ['label'=>'Montant FC', 'val'=>formatMoney($montant_total), 'color'=>'#8b5cf6','icon'=>'fas fa-coins'],
    ];
    foreach ($stats_cards as $sc):
    ?>
    <div style="background:white;border-radius:10px;padding:18px;box-shadow:var(--shadow);
                display:flex;align-items:center;gap:12px;">
        <div style="width:44px;height:44px;border-radius:10px;background:<?php echo $sc['color']; ?>;
                    display:flex;align-items:center;justify-content:center;color:white;font-size:18px;">
            <i class="<?php echo $sc['icon']; ?>"></i>
        </div>
        <div>
            <div style="font-size:20px;font-weight:700"><?php echo $sc['val']; ?></div>
            <div style="font-size:12px;color:#64748b"><?php echo $sc['label']; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tableau des résultats -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-list"></i> Prescriptions
            <span class="badge badge-primary"><?php echo $total; ?></span>
        </h3>
    </div>
    <div class="card-body">
        <?php if (empty($rapports)): ?>
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <p>Aucun résultat pour cette période.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table id="rapportTable">
                    <thead>
                        <tr>
                            <th>Date</th><th>N° Dossier</th><th>Patient</th>
                            <th>Acte</th><th>Service</th><th>Prix</th><th>Statut</th><th>Urgent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rapports as $r): ?>
                        <tr>
                            <td><?php echo formatDate($r['date_prescription']); ?></td>
                            <td><strong><?php echo htmlspecialchars($r['numero_dossier']); ?></strong></td>
                            <td><?php echo htmlspecialchars($r['patient_prenom'] . ' ' . $r['patient_nom']); ?></td>
                            <td>
                                <span class="badge badge-secondary"><?php echo htmlspecialchars($r['acte_code']); ?></span>
                                <?php echo htmlspecialchars($r['acte_libelle']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($r['service_nom'] ?? '-'); ?></td>
                            <td><?php echo formatMoney($r['prix_unitaire']); ?></td>
                            <td>
                                <?php $bc = ['acheve'=>'badge-success','en_attente'=>'badge-warning','annule'=>'badge-danger'][$r['statut_execution']] ?? 'badge-secondary'; ?>
                                <span class="badge <?php echo $bc; ?>"><?php echo ucfirst(str_replace('_',' ',$r['statut_execution'])); ?></span>
                            </td>
                            <td>
                                <?php if ($r['urgent']): ?>
                                    <span class="badge badge-danger"><i class="fas fa-exclamation"></i> Urgent</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Normal</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../views/includes/footer.php'; ?>
