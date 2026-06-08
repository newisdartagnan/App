<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$date_debut = $_GET['date_debut'] ?? date('Y-m-d');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$societe_id = $_GET['societe_id'] ?? null;

// RÃ©cupÃ©rer les sociÃ©tÃ©s
$societes = $db->query("SELECT * FROM societe WHERE actif = 1 ORDER BY nom")->fetchAll();

// RÃ©cupÃ©rer les patients conventionnÃ©s avec actes validÃ©s non facturÃ©s
$query = "SELECT 
            p.idpatient, p.nom, p.prenom, p.numero_dossier,
            s.idsejour, s.numero_sejour, s.date_entree,
            soc.nom as societe_nom, soc.idsociete,
            AVG(st.taux_couverture) as taux_couverture_moyen,
            COUNT(DISTINCT ap.idactes_presc) as nb_actes,
            SUM(ap.montant_total) as montant_total,
            SUM(ap.montant_total * st.taux_couverture / 100) as part_societe,
            SUM(ap.montant_total * (100 - st.taux_couverture) / 100) as part_patient
          FROM patient p
          JOIN sejour s ON p.idpatient = s.idpatient
          JOIN sous_sejour ss ON s.idsejour = ss.idsejour
          JOIN actes_presc ap ON ss.idsous_sejour = ap.idsous_sejour
          JOIN societe soc ON p.idsociete = soc.idsociete
          JOIN societe_tarif st ON soc.idsociete = st.idsociete AND ap.idacte = st.idtarif
          WHERE p.type_patient = 'conventionne'
          AND ap.statut_validation = 'valide'
          AND s.idsite = :idsite
          AND DATE(ap.date_prescription) BETWEEN :date_debut AND :date_fin
          " . ($societe_id ? "AND soc.idsociete = :societe_id" : "") . "
          GROUP BY p.idpatient, s.idsejour, soc.nom, soc.idsociete
          ORDER BY soc.nom, p.nom";

$stmt = $db->prepare($query);
$params = [
    ':idsite' => $_SESSION['site_id'],
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin
];
if ($societe_id) $params[':societe_id'] = $societe_id;
$stmt->execute($params);
$patients = $stmt->fetchAll();

$pageTitle = "Facturation ConventionnÃ©e - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-handshake"></i> Facturation ConventionnÃ©e</h1>
    <a href="index.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<!-- Filtres -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="date_debut">Date dÃ©but</label>
                    <input type="date" id="date_debut" name="date_debut" class="form-control" value="<?php echo $date_debut; ?>">
                </div>
                <div class="form-group">
                    <label for="date_fin">Date fin</label>
                    <input type="date" id="date_fin" name="date_fin" class="form-control" value="<?php echo $date_fin; ?>">
                </div>
                <div class="form-group">
                    <label for="societe_id">SociÃ©tÃ©</label>
                    <select id="societe_id" name="societe_id" class="form-control">
                        <option value="">Toutes les sociÃ©tÃ©s</option>
                        <?php foreach ($societes as $soc): ?>
                        <option value="<?php echo $soc['idsociete']; ?>" <?php echo $societe_id == $soc['idsociete'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($soc['nom']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrer
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Liste patients -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">Actes Ã  facturer <span class="badge badge-primary"><?php echo count($patients); ?></span></h3>
        <?php if (!empty($patients)): ?>
            <button onclick="genererBordereau()" class="btn btn-success">
                <i class="fas fa-file-invoice"></i> GÃ©nÃ©rer Bordereau
            </button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($patients)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Aucun acte Ã  facturer pour cette pÃ©riode.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                            </th>
                            <th>Patient</th>
                            <th>NÂ° Dossier</th>
                            <th>SociÃ©tÃ©</th>
                            <th>Nb Actes</th>
                            <th>Montant Total</th>
                            <th>Part SociÃ©tÃ©</th>
                            <th>Part Patient</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $pat): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="patients[]" value="<?php echo $pat['idsejour']; ?>" class="patient-checkbox">
                            </td>
                            <td><strong><?php echo htmlspecialchars($pat['nom'] . ' ' . $pat['prenom']); ?></strong></td>
                            <td><?php echo htmlspecialchars($pat['numero_dossier']); ?></td>
                            <td><?php echo htmlspecialchars($pat['societe_nom']); ?></td>
                            <td class="text-center"><span class="badge badge-info"><?php echo $pat['nb_actes']; ?></span></td>
                            <td><strong><?php echo formatMoney($pat['montant_total']); ?></strong></td>
                            <td class="text-success"><strong><?php echo formatMoney($pat['part_societe']); ?></strong></td>
                            <td class="text-warning"><?php echo formatMoney($pat['part_patient']); ?></td>
                            <td>
                                <a href="detail-actes.php?sejour=<?php echo $pat['idsejour']; ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> DÃ©tails
                                </a>
                                <button onclick="facturer(<?php echo $pat['idsejour']; ?>)" class="btn btn-sm btn-success">
                                    <i class="fas fa-check"></i> Facturer
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background: #f8fafc; font-weight: bold;">
                            <td colspan="5" style="text-align: right;">TOTAUX:</td>
                            <td><?php echo formatMoney(array_sum(array_column($patients, 'montant_total'))); ?></td>
                            <td class="text-success"><?php echo formatMoney(array_sum(array_column($patients, 'part_societe'))); ?></td>
                            <td class="text-warning"><?php echo formatMoney(array_sum(array_column($patients, 'part_patient'))); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAll(source) {
    document.querySelectorAll('.patient-checkbox').forEach(cb => cb.checked = source.checked);
}

function genererBordereau() {
    const selected = Array.from(document.querySelectorAll('.patient-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        alert('Veuillez sÃ©lectionner au moins un patient');
        return;
    }
    window.open('bordereau.php?sejours=' + selected.join(','), '_blank');
}

function facturer(sejour_id) {
    if (confirm('Confirmer la facturation de ce patient ?')) {
        window.location.href = 'traiter-facturation.php?sejour=' + sejour_id;
    }
}
</script>

<?php include '../views/includes/footer.php'; ?>