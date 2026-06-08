<?php
/**
 * Module Imagerie - Planning des examens
 * 
 * Vue planning avec créneaux horaires, intégration avec le dashboard
 */

require_once __DIR__ . '/../../includes/imagerie_helpers.php';

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base = $db->getBaseConnection();

// =============================================
// GESTION DE LA DATE
// =============================================
$date = $_GET['date'] ?? date('Y-m-d');
$date_sql = $date;

// Date précédente et suivante pour navigation
$date_prec = date('Y-m-d', strtotime($date . ' -1 day'));
$date_suiv = date('Y-m-d', strtotime($date . ' +1 day'));

// =============================================
// STATISTIQUES RAPIDES POUR LA DATE
// =============================================
$stmt_stats = $conn_services->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN urgence = 1 THEN 1 ELSE 0 END) as urgents,
        SUM(CASE WHEN statut = 'programme' THEN 1 ELSE 0 END) as programmes,
        SUM(CASE WHEN statut IN ('accueil','en_preparation','en_acquisition','acquisition_terminee','en_reconstruction') THEN 1 ELSE 0 END) as en_cours,
        SUM(CASE WHEN statut IN ('transmis') THEN 1 ELSE 0 END) as termines
    FROM imagerie_examens
    WHERE DATE(date_rdv) = ?
");
$stmt_stats->execute([$date_sql]);
$stats_jour = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Valeurs par défaut
$stats_jour = array_map(function($val) { return $val ?? 0; }, $stats_jour);

// =============================================
// RÉCUPÉRATION DES EXAMENS PLANIFIÉS
// =============================================
$query = "SELECT 
    e.*,
    TIMESTAMPDIFF(MINUTE, e.date_rdv, NOW()) as delai_minutes
    FROM imagerie_examens e
    WHERE DATE(e.date_rdv) = :date
    ORDER BY e.date_rdv ASC, e.urgence DESC";

$stmt = $conn_services->prepare($query);
$stmt->bindParam(':date', $date_sql);
$stmt->execute();
$examens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les informations patients
$patients = [];
if (!empty($examens)) {
    $ids = array_unique(array_column($examens, 'idpatient'));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom, sexe, date_naissance, numero_dossier FROM patient WHERE idpatient IN ($placeholders)");
        $stmt_p->execute($ids);
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $patients[$p['idpatient']] = $p;
        }
    }
}

// =============================================
// ORGANISATION PAR CRÉNEAUX HORAIRES
// =============================================
$creneaux = [
    '08:00' => ['label' => 'Matin (08:00 - 10:00)', 'examens' => []],
    '10:00' => ['label' => 'Mi-matin (10:00 - 12:00)', 'examens' => []],
    '12:00' => ['label' => 'Pause méridienne (12:00 - 14:00)', 'examens' => []],
    '14:00' => ['label' => 'Après-midi (14:00 - 16:00)', 'examens' => []],
    '16:00' => ['label' => 'Fin après-midi (16:00 - 18:00)', 'examens' => []],
    '18:00' => ['label' => 'Soirée (18:00 - 20:00)', 'examens' => []],
];

foreach ($examens as $examen) {
    if ($examen['date_rdv']) {
        $heure = date('H:i', strtotime($examen['date_rdv']));
        $heure_num = (int)substr($heure, 0, 2);
        
        // Assigner au créneau approprié
        if ($heure_num < 10) {
            $creneaux['08:00']['examens'][] = $examen;
        } elseif ($heure_num < 12) {
            $creneaux['10:00']['examens'][] = $examen;
        } elseif ($heure_num < 14) {
            $creneaux['12:00']['examens'][] = $examen;
        } elseif ($heure_num < 16) {
            $creneaux['14:00']['examens'][] = $examen;
        } elseif ($heure_num < 18) {
            $creneaux['16:00']['examens'][] = $examen;
        } else {
            $creneaux['18:00']['examens'][] = $examen;
        }
    }
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE DU PLANNING -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-calendar-week me-2" style="color: #0dcaf0;"></i>
        Planning des examens
    </h4>
    <div class="btn-group">
        <a href="index.php?page=imagerie&action=dashboard" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="index.php?page=imagerie&action=examens" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-ul"></i> Liste
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer"></i> Imprimer
        </button>
    </div>
</div>

<!-- ========================================= -->
<!-- NAVIGATION DATE -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex gap-2">
                <a href="?page=imagerie&action=planning&date=<?= $date_prec ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-chevron-left"></i> Jour précédent
                </a>
                <a href="?page=imagerie&action=planning&date=<?= date('Y-m-d') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-calendar-day"></i> Aujourd'hui
                </a>
                <a href="?page=imagerie&action=planning&date=<?= $date_suiv ?>" class="btn btn-outline-primary btn-sm">
                    Jour suivant <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-calendar3"></i>
                <input type="date" id="date-picker" class="form-control form-control-sm" style="width: auto;" 
                       value="<?= $date ?>" onchange="window.location.href='?page=imagerie&action=planning&date=' + this.value">
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES DU JOUR -->
<!-- ========================================= -->
<div class="row g-3 mb-4">
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #cff4fc;">
                    <i class="bi bi-calendar-check" style="color: #0dcaf0;"></i>
                </div>
                <div class="stat-value"><?= $stats_jour['total'] ?></div>
                <div class="stat-small">Total examens</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #fee2e2;">
                    <i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i>
                </div>
                <div class="stat-value"><?= $stats_jour['urgents'] ?></div>
                <div class="stat-small">Urgents</div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #fff3cd;">
                    <i class="bi bi-clock" style="color: #f59e0b;"></i>
                </div>
                <div class="stat-value"><?= $stats_jour['programmes'] ?></div>
                <div class="stat-small">Programmés</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1fae5;">
                    <i class="bi bi-arrow-repeat" style="color: #10b981;"></i>
                </div>
                <div class="stat-value"><?= $stats_jour['en_cours'] ?></div>
                <div class="stat-small">En cours</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #dbeafe;">
                    <i class="bi bi-check-circle" style="color: #2563eb;"></i>
                </div>
                <div class="stat-value"><?= $stats_jour['termines'] ?></div>
                <div class="stat-small">Terminés</div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- PLANNING PAR CRÉNEAUX -->
<!-- ========================================= -->
<div class="planning-container">
    <?php foreach ($creneaux as $creneau): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">
                <i class="bi bi-clock me-2" style="color: #0dcaf0;"></i>
                <?= $creneau['label'] ?>
            </h6>
            <span class="badge bg-primary"><?= count($creneau['examens']) ?> examen(s)</span>
        </div>
        
        <div class="card-body p-0">
            <?php if (empty($creneau['examens'])): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-calendar-x fs-1"></i>
                    <p class="mb-0">Aucun examen programmé sur ce créneau</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Heure</th>
                                <th>Code</th>
                                <th>Patient</th>
                                <th>Examen</th>
                                <th>Salle</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($creneau['examens'] as $ex): 
                                $patient = $patients[$ex['idpatient']] ?? null;
                                $heure = date('H:i', strtotime($ex['date_rdv']));
                                $past = strtotime($ex['date_rdv']) < time();
                                $est_retard = $past && $ex['statut'] === 'programme';
                            ?>
                            <tr class="<?= $est_retard ? 'table-warning' : '' ?> <?= $ex['urgence'] ? 'border-start border-3 border-danger' : '' ?>">
                                <td>
                                    <strong><?= $heure ?></strong>
                                    <?php if ($est_retard): ?>
                                        <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="En retard"></i>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($ex['code_examen']) ?></code></td>
                                <td>
                                    <?php if ($patient): ?>
                                        <strong><?= htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']) ?></strong>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($patient['numero_dossier'] ?? '') ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Patient #<?= $ex['idpatient'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($ex['type_examen'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ex['salle'] ?? '-') ?></td>
                                <td><?= getImagerieStatutBadge($ex['statut']) ?></td>
                                <td>
                                    <a href="index.php?page=imagerie&action=workflow&code=<?= urlencode($ex['code_examen']) ?>" 
                                       class="btn btn-sm btn-outline-primary" title="Voir workflow">
                                        <i class="bi bi-diagram-3"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-info" title="Démarrer examen" 
                                            onclick="demarrerExamen('<?= $ex['code_examen'] ?>')">
                                        <i class="bi bi-play-circle"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Script pour synchronisation avec dashboard -->
<script>
function demarrerExamen(code) {
    if (confirm('Démarrer cet examen ?')) {
        // Rediriger vers la page de réalisation (à créer)
        window.location.href = 'index.php?page=imagerie&action=realiser&code=' + code;
    }
}

// Synchronisation avec le dashboard via événement personnalisé
document.addEventListener('examenMisAJour', function(e) {
    // Recharger le planning si un examen a été mis à jour
    if (e.detail && e.detail.code) {
        console.log('Examen mis à jour:', e.detail.code);
        // Optionnel: recharger la page ou mettre à jour dynamiquement
    }
});

// Mise à jour en temps réel (toutes les 30 secondes)
setInterval(function() {
    fetch('api/imagerie.php?action=check_updates&date=<?= $date ?>')
        .then(r => r.json())
        .then(data => {
            if (data.updated) {
                // Recharger discrètement si des changements détectés
                location.reload();
            }
        })
        .catch(err => console.error('Erreur synchronisation:', err));
}, 30000);
</script>

<style>
.stat-card {
    transition: transform 0.2s;
}
.stat-card:hover {
    transform: translateY(-2px);
}
.stat-icon-wrapper {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.2;
}
.stat-small {
    font-size: 0.75rem;
    color: #6c757d;
}
.planning-container {
    max-height: calc(100vh - 300px);
    overflow-y: auto;
    padding-right: 5px;
}
.planning-container::-webkit-scrollbar {
    width: 6px;
}
.planning-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}
.planning-container::-webkit-scrollbar-thumb {
    background: #0dcaf0;
    border-radius: 3px;
}
</style>