<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../models/BlocOperatoire.php';
requireLogin();
if (!hasPermission('bloc')) redirect('../index.php?error=Accès non autorisé');

$database = new Database();
$db  = $database->getConnection();
$bloc = new BlocOperatoire($db);

$date    = $_GET['date']   ?? date('Y-m-d');
$idsalle = $_GET['salle']  ?? null;
$statut  = $_GET['statut'] ?? null;

$programme = $bloc->getProgrammeOperatoire($_SESSION['site_id'], $date, $idsalle);

$stmt_salles = $db->prepare("SELECT * FROM salle_bloc WHERE idsite = :idsite AND statut = 'operationnelle' ORDER BY numero_salle");
$stmt_salles->execute([':idsite' => $_SESSION['site_id']]);
$salles = $stmt_salles->fetchAll();

$stmt_stats = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN statut = 'programmee' THEN 1 ELSE 0 END) AS programmees,
    SUM(CASE WHEN statut = 'en_cours'   THEN 1 ELSE 0 END) AS en_cours,
    SUM(CASE WHEN statut = 'terminee'   THEN 1 ELSE 0 END) AS terminees,
    SUM(CASE WHEN urgence = 1           THEN 1 ELSE 0 END) AS urgences
FROM bloc_intervention bi
JOIN sous_sejour ss ON bi.idsous_sejour = ss.idsous_sejour
JOIN sejour      s  ON ss.idsejour      = s.idsejour
WHERE s.idsite = :idsite AND bi.date_prevue = :date");
$stmt_stats->execute([':idsite' => $_SESSION['site_id'], ':date' => $date]);
$stats = $stmt_stats->fetch();

$pageTitle = "Bloc Opératoire - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <div class="header-content">
        <h1><i class="fas fa-procedures"></i> Bloc Opératoire</h1>
        <div class="header-badge">
            <span class="badge badge-primary"><?php echo $stats['total'] ?? 0; ?> interventions</span>
            <span class="badge badge-danger"><?php echo $stats['urgences'] ?? 0; ?> urgences</span>
        </div>
    </div>
    <div class="header-actions">
        <a href="programmer.php" class="btn btn-primary">
            <i class="fas fa-plus-circle"></i> Programmer une intervention
        </a>
    </div>
</div>

<!-- Filtres -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="form-row">
                <div class="form-group">
                    <label>Date :</label>
                    <input type="date" name="date" value="<?php echo $date; ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label>Salle :</label>
                    <select name="salle" class="form-control">
                        <option value="">Toutes les salles</option>
                        <?php foreach ($salles as $salle): ?>
                        <option value="<?php echo $salle['idsalle_bloc']; ?>"
                            <?php echo $idsalle == $salle['idsalle_bloc'] ? 'selected' : ''; ?>>
                            Salle <?php echo $salle['numero_salle']; ?> - <?php echo htmlspecialchars($salle['nom_salle']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Statut :</label>
                    <select name="statut" class="form-control">
                        <option value="">Tous les statuts</option>
                        <option value="programmee" <?php echo $statut == 'programmee' ? 'selected' : ''; ?>>Programmée</option>
                        <option value="en_cours"   <?php echo $statut == 'en_cours'   ? 'selected' : ''; ?>>En cours</option>
                        <option value="terminee"   <?php echo $statut == 'terminee'   ? 'selected' : ''; ?>>Terminée</option>
                        <option value="annulee"    <?php echo $statut == 'annulee'    ? 'selected' : ''; ?>>Annulée</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-outline" style="margin-top:25px;">
                        <i class="fas fa-filter"></i> Filtrer
                    </button>
                    <a href="index.php" class="btn btn-outline" style="margin-top:25px;">
                        <i class="fas fa-redo"></i> Réinitialiser
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Statistiques rapides -->
<div class="stats-grid mb-4">
    <div class="stat-card">
        <div class="stat-icon programmee"><i class="fas fa-calendar-check"></i></div>
        <div class="stat-content"><h3><?php echo $stats['programmees'] ?? 0; ?></h3><p>Programmées</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon en_cours"><i class="fas fa-play-circle"></i></div>
        <div class="stat-content"><h3><?php echo $stats['en_cours'] ?? 0; ?></h3><p>En cours</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon terminee"><i class="fas fa-check-circle"></i></div>
        <div class="stat-content"><h3><?php echo $stats['terminees'] ?? 0; ?></h3><p>Terminées</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon urgence"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-content"><h3><?php echo $stats['urgences'] ?? 0; ?></h3><p>Urgences</p></div>
    </div>
</div>

<!-- Programme opératoire -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-scalpel"></i> Programme du <?php echo formatDate($date); ?></h3>
        <button onclick="window.print()" class="btn btn-sm btn-outline"><i class="fas fa-print"></i> Imprimer</button>
    </div>
    <div class="card-body">
        <?php if (empty($programme)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>Aucune intervention programmée</h3>
                <p>Aucune intervention n'est prévue pour cette date.</p>
                <a href="programmer.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Programmer</a>
            </div>
        <?php else: ?>
            <div class="programme-container">
                <?php foreach ($programme as $intervention): ?>
                <div class="intervention-card <?php echo $intervention['statut']; ?> <?php echo $intervention['urgence'] ? 'urgent' : ''; ?>">
                    <div class="intervention-header">
                        <div class="intervention-info">
                            <h4 class="intervention-title">
                                <?php if ($intervention['urgence']): ?>
                                    <i class="fas fa-exclamation-triangle urgent-badge"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($intervention['libelle_intervention']); ?>
                            </h4>
                            <div class="intervention-meta">
                                <span><i class="fas fa-user"></i>
                                    <?php echo htmlspecialchars($intervention['prenom'] . ' ' . $intervention['nom']); ?>
                                    (<?php echo htmlspecialchars($intervention['numero_dossier']); ?>)
                                </span>
                                <span><i class="fas fa-door-open"></i> Salle <?php echo $intervention['numero_salle']; ?></span>
                                <span><i class="fas fa-user-md"></i> Dr.
                                    <?php echo htmlspecialchars(($intervention['chirurgien_prenom'] ?? '') . ' ' . ($intervention['chirurgien_nom'] ?? '')); ?>
                                </span>
                            </div>
                        </div>
                        <div class="intervention-status">
                            <?php
                            $labels = ['programmee'=>'Programmée','en_cours'=>'En cours','terminee'=>'Terminée','annulee'=>'Annulée'];
                            ?>
                            <span class="status-badge <?php echo $intervention['statut']; ?>">
                                <?php echo $labels[$intervention['statut']] ?? $intervention['statut']; ?>
                            </span>
                        </div>
                    </div>

                    <div class="intervention-details">
                        <div class="detail-item">
                            <strong>Horaire :</strong>
                            <?php echo substr($intervention['heure_debut_prevue'] ?? '', 0, 5); ?>
                            (<?php echo $intervention['duree_prevue_minutes']; ?> min)
                        </div>
                        <?php if ($intervention['type_anesthesie']): ?>
                        <div class="detail-item">
                            <strong>Anesthésie :</strong> <?php echo htmlspecialchars($intervention['type_anesthesie']); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($intervention['type_intervention']): ?>
                        <div class="detail-item">
                            <strong>Type :</strong> <?php echo htmlspecialchars($intervention['type_intervention']); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="intervention-actions">
                        <?php if ($intervention['statut'] == 'programmee'): ?>
                            <a href="debuter.php?id=<?php echo $intervention['idintervention']; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-play"></i> Débuter
                            </a>
                        <?php elseif ($intervention['statut'] == 'en_cours'): ?>
                            <a href="compte-rendu.php?id=<?php echo $intervention['idintervention']; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-file-medical"></i> Compte-rendu
                            </a>
                            <a href="terminer.php?id=<?php echo $intervention['idintervention']; ?>" class="btn btn-warning btn-sm">
                                <i class="fas fa-stop"></i> Terminer
                            </a>
                        <?php endif; ?>
                        <a href="details.php?id=<?php echo $intervention['idintervention']; ?>" class="btn btn-outline btn-sm">
                            <i class="fas fa-eye"></i> Détails
                        </a>
                        <?php if ($intervention['statut'] == 'programmee'): ?>
                        <a href="modifier.php?id=<?php echo $intervention['idintervention']; ?>" class="btn btn-outline btn-sm">
                            <i class="fas fa-edit"></i> Modifier
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.header-content { display:flex;align-items:center;gap:20px; }
.header-badge   { display:flex;gap:10px; }
.stats-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px; }
.stat-card  { background:white;border-radius:12px;padding:25px;box-shadow:0 2px 10px rgba(0,0,0,.1);
              display:flex;align-items:center;gap:20px;transition:transform .2s; }
.stat-card:hover { transform:translateY(-2px); }
.stat-icon  { width:60px;height:60px;border-radius:12px;display:flex;align-items:center;
              justify-content:center;font-size:24px;color:#fff; }
.stat-icon.programmee { background:#3b82f6; }
.stat-icon.en_cours   { background:#f59e0b; }
.stat-icon.terminee   { background:#10b981; }
.stat-icon.urgence    { background:#ef4444; }
.stat-content h3 { font-size:32px;font-weight:bold;margin:0;color:var(--dark); }
.stat-content p  { margin:5px 0 0 0;color:#64748b;font-weight:500; }
.programme-container { display:flex;flex-direction:column;gap:15px; }
.intervention-card { background:white;border-radius:12px;padding:20px;
    border-left:4px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.08);transition:all .2s; }
.intervention-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.12); }
.intervention-card.urgent    { border-left-color:#ef4444;background:#fef2f2; }
.intervention-card.en_cours  { border-left-color:#f59e0b;background:#fffbeb; }
.intervention-card.terminee  { border-left-color:#10b981;background:#f0fdf4; }
.intervention-header { display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:15px; }
.intervention-title  { margin:0 0 10px 0;font-size:18px; }
.urgent-badge { color:#ef4444;margin-right:8px; }
.intervention-meta { display:flex;flex-wrap:wrap;gap:15px;font-size:13px;color:#64748b; }
.intervention-meta span { display:flex;align-items:center;gap:5px; }
.status-badge { padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;text-transform:uppercase; }
.status-badge.programmee { background:#dbeafe;color:#1e40af; }
.status-badge.en_cours   { background:#fef3c7;color:#92400e; }
.status-badge.terminee   { background:#d1fae5;color:#065f46; }
.status-badge.annulee    { background:#fee2e2;color:#dc2626; }
.intervention-details { display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:10px;margin-bottom:15px;padding:15px;background:#f8fafc;border-radius:8px; }
.detail-item { font-size:14px;color:#475569; }
.intervention-actions { display:flex;gap:8px;flex-wrap:wrap; }
</style>

<script>
// Auto-refresh si interventions en cours
setInterval(() => {
    if (document.querySelector('.intervention-card.en_cours')) location.reload();
}, 30000);

// Notification urgences
document.addEventListener('DOMContentLoaded', function() {
    const urgentCount = <?php echo $stats['urgences'] ?? 0; ?>;
    if (urgentCount > 0 && 'Notification' in window && Notification.permission === 'granted') {
        new Notification('Interventions Urgentes', {
            body: urgentCount + ' intervention(s) urgente(s) programmée(s)'
        });
    }
});
</script>

<?php include '../views/includes/footer.php'; ?>
