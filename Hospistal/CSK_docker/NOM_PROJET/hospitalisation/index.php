<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();
if (!hasPermission('hospitalisation')) redirect('../index.php');

$database = new Database();
$db = $database->getConnection();

$service_actif = $_GET['service'] ?? null;

// -------------------------------------------------------
// REQUETE CORRIGEE : ss.idlit -> lit.idchambre -> chambre
// (correction issue des conversations : ss.idchambre n'existe PAS)
// -------------------------------------------------------
$query_services = "SELECT DISTINCT
                    s.idservices,
                    s.nom AS service_nom,
                    COUNT(DISTINCT ss.idsous_sejour) AS nb_patients,
                    COUNT(DISTINCT l.idlit)          AS lits_occupes
                FROM services s
                LEFT JOIN unite_med   um ON s.idservices     = um.idservices
                LEFT JOIN sous_sejour ss ON um.idunite_med   = ss.idunite_med
                    AND ss.statut = 'en_cours'
                LEFT JOIN lit         l  ON ss.idlit         = l.idlit AND l.statut = 'occupé'
                LEFT JOIN chambre     c  ON l.idchambre      = c.idchambre
                WHERE s.idsite = :idsite
                AND s.type_service IN ('hospitalisation','chirurgie','gynecologie','pediatrie','maternite')
                GROUP BY s.idservices
                ORDER BY s.nom";

$stmt_services = $db->prepare($query_services);
$stmt_services->execute([':idsite' => $_SESSION['site_id']]);
$services = $stmt_services->fetchAll();

if (!$service_actif && !empty($services)) {
    $service_actif = $services[0]['idservices'];
}

// -------------------------------------------------------
// REQUETE CORRIGEE : jointure ss -> lit -> chambre
// -------------------------------------------------------
$patients = [];
if ($service_actif) {
    $query_patients = "SELECT ss.*,
                        p.nom, p.prenom, p.numero_dossier, p.sexe, p.date_naissance,
                        s.numero_sejour, s.date_entree,
                        um.nom   AS unite_nom,
                        c.numero AS numero_chambre,
                        l.numero AS numero_lit,
                        m.nom    AS medecin_nom
                    FROM sous_sejour ss
                    JOIN sejour      s   ON ss.idsejour      = s.idsejour
                    JOIN patient     p   ON s.idpatient      = p.idpatient
                    JOIN unite_med   um  ON ss.idunite_med   = um.idunite_med
                    JOIN services    srv ON um.idservices    = srv.idservices
                    LEFT JOIN lit     l  ON ss.idlit         = l.idlit
                    LEFT JOIN chambre c  ON l.idchambre      = c.idchambre
                    LEFT JOIN utilisateur m ON s.idmedecin   = m.idutilisateur
                    WHERE srv.idservices = :service
                    AND   ss.statut = 'en_cours'
                    ORDER BY c.numero, l.numero";

    $stmt_patients = $db->prepare($query_patients);
    $stmt_patients->execute([':service' => $service_actif]);
    $patients = $stmt_patients->fetchAll();
}

$pageTitle = "Hospitalisation - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-hospital"></i> Hospitalisation</h1>
    <div class="header-actions">
        <a href="../reception/creer-sejour.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Nouvelle admission
        </a>
    </div>
</div>

<div style="display:flex;gap:20px;align-items:flex-start;">

    <!-- Liste des services -->
    <div style="width:220px;min-width:220px;">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Services</h3></div>
            <div style="padding:8px 0;">
                <?php foreach ($services as $srv): ?>
                <a href="?service=<?php echo $srv['idservices']; ?>"
                   style="display:block;padding:10px 16px;
                          background:<?php echo $srv['idservices'] == $service_actif ? 'var(--primary)' : 'transparent'; ?>;
                          color:<?php echo $srv['idservices'] == $service_actif ? '#fff' : 'var(--dark)'; ?>;
                          text-decoration:none;border-radius:6px;margin:2px 8px;">
                    <strong><?php echo htmlspecialchars($srv['service_nom']); ?></strong>
                    <br>
                    <small>
                        <?php echo $srv['nb_patients']; ?> patient(s)
                        &bull; <?php echo $srv['lits_occupes']; ?> lit(s)
                    </small>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Patients du service sélectionné -->
    <div style="flex:1;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-bed"></i> Patients hospitalisés
                    <span class="badge badge-primary"><?php echo count($patients); ?></span>
                </h3>
            </div>
            <div class="card-body">
                <?php if (empty($patients)): ?>
                    <div class="empty-state">
                        <i class="fas fa-bed"></i>
                        <p>Aucun patient hospitalisé dans ce service.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>N° Dossier</th><th>Patient</th><th>Âge/Sexe</th>
                                    <th>Unité</th><th>Chambre</th><th>Lit</th>
                                    <th>Entrée</th><th>Médecin</th><th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($patients as $pt): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($pt['numero_dossier']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($pt['prenom'] . ' ' . $pt['nom']); ?></td>
                                    <td><?php echo calculateAge($pt['date_naissance']); ?> ans / <?php echo $pt['sexe']; ?></td>
                                    <td><?php echo htmlspecialchars($pt['unite_nom']); ?></td>
                                    <td><?php echo htmlspecialchars($pt['numero_chambre'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($pt['numero_lit'] ?? '-'); ?></td>
                                    <td><?php echo formatDate($pt['date_entree']); ?></td>
                                    <td><?php echo htmlspecialchars($pt['medecin_nom'] ?? '-'); ?></td>
                                    <td>
                                        <a href="../reception/dossier-patient.php?id=<?php echo $pt['idpatient'] ?? ''; ?>"
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-folder-open"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../views/includes/footer.php'; ?>
