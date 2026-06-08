<?php
/**
 * Module Imagerie - Workflow
 * 
 * Gestion des transitions de statut d'un examen.
 * Si ?code=XXX est fourni, on affiche le detail + actions.
 * Sinon, on affiche le tableau Kanban par colonnes de statut.
 */

require_once __DIR__ . '/../../includes/imagerie_helpers.php';
$statut_labels   = $GLOBALS['imagerie_statut_labels'];
$transitions     = $GLOBALS['imagerie_transitions'];
$kanban_colonnes = $GLOBALS['imagerie_kanban_colonnes'];

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

$code = isset($_GET['code']) ? sanitizeInput($_GET['code']) : '';
$profil_code = $_SESSION['profil_code'] ?? '';

// =============================================
// TRAITEMENT DES TRANSITIONS (POST)
// =============================================

$flash_message = '';
$flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transition_to'])) {
    $examen_code     = sanitizeInput($_POST['code_examen'] ?? '');
    $new_statut      = sanitizeInput($_POST['transition_to']);
    $observation     = sanitizeInput($_POST['observation'] ?? '');
    
    // Recuperer l'examen
    $stmt = $conn_services->prepare("SELECT * FROM imagerie_examens WHERE code_examen = ?");
    $stmt->execute([$examen_code]);
    $examen_post = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($examen_post) {
        $actions_possibles = getImagerieTransitionsPossibles($examen_post['statut'], $profil_code);
        
        if (isset($actions_possibles[$new_statut])) {
            try {
                $conn_services->beginTransaction();
                
                // Mettre a jour le statut
                $stmt_up = $conn_services->prepare("UPDATE imagerie_examens SET statut = ? WHERE idexamen = ?");
                $stmt_up->execute([$new_statut, $examen_post['idexamen']]);
                
                // Logger dans l'historique
                $stmt_log = $conn_services->prepare("
                    INSERT INTO imagerie_workflow_history 
                    (idexamen, ancien_statut, nouveau_statut, action, idutilisateur, observation, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $action_label = $actions_possibles[$new_statut][0] ?? 'Transition';
                $stmt_log->execute([
                    $examen_post['idexamen'],
                    $examen_post['statut'],
                    $new_statut,
                    $action_label,
                    $_SESSION['idutilisateur'],
                    $observation,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
                
                $conn_services->commit();
                $flash_message = 'Transition effectuee : ' . $action_label;
                $flash_type = 'success';
                $code = $examen_code; // rester sur le detail
                
            } catch (Exception $e) {
                $conn_services->rollBack();
                $flash_message = 'Erreur lors de la transition : ' . $e->getMessage();
                $flash_type = 'danger';
            }
        } else {
            $flash_message = 'Transition non autorisee pour votre profil.';
            $flash_type = 'danger';
        }
    }
}

// =============================================
// MODE DETAIL (si ?code=XXX)
// =============================================

if ($code) {
    // Recuperer l'examen
    $stmt = $conn_services->prepare("SELECT * FROM imagerie_examens WHERE code_examen = ?");
    $stmt->execute([$code]);
    $examen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$examen) {
        echo '<div class="alert alert-danger">Examen introuvable : ' . htmlspecialchars($code) . '</div>';
        echo '<a href="index.php?page=imagerie&action=workflow" class="btn btn-outline-primary">Retour au Kanban</a>';
        return;
    }
    
    // Patient
    $stmt_p = $conn_base->prepare("SELECT * FROM patient WHERE idpatient = ?");
    $stmt_p->execute([$examen['idpatient']]);
    $patient = $stmt_p->fetch(PDO::FETCH_ASSOC);
    
    // Prescription d'origine
    $prescription = null;
    if ($examen['idactes_presc']) {
        $stmt_presc = $conn_base->prepare("SELECT * FROM actes_presc WHERE idactes_presc = ?");
        $stmt_presc->execute([$examen['idactes_presc']]);
        $prescription = $stmt_presc->fetch(PDO::FETCH_ASSOC);
    }
    
    // Resultat imagerie (si lie)
    $resultat = null;
    if ($examen['idresultat_imagerie']) {
        $stmt_res = $conn_base->prepare("SELECT * FROM resultats_imagerie WHERE idresultat_imagerie = ?");
        $stmt_res->execute([$examen['idresultat_imagerie']]);
        $resultat = $stmt_res->fetch(PDO::FETCH_ASSOC);
    }
    
    // Noms utilisateurs assignes
    $user_ids = array_filter([
        $examen['secretaire_accueil'],
        $examen['manipulateur'],
        $examen['radiologue'],
        $examen['radiologue_validateur'],
    ]);
    $users = [];
    if (!empty($user_ids)) {
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $stmt_u = $conn_base->prepare("SELECT idutilisateur, nom, prenom FROM utilisateur WHERE idutilisateur IN ($placeholders)");
        $stmt_u->execute(array_values($user_ids));
        foreach ($stmt_u->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $users[$u['idutilisateur']] = $u;
        }
    }
    
    // Historique des transitions
    $stmt_h = $conn_services->prepare("
        SELECT h.*, u.nom as user_nom, u.prenom as user_prenom
        FROM imagerie_workflow_history h
        LEFT JOIN csk_base.utilisateur u ON u.idutilisateur = h.idutilisateur
        WHERE h.idexamen = ?
        ORDER BY h.created_at DESC
    ");
    $stmt_h->execute([$examen['idexamen']]);
    $historique = $stmt_h->fetchAll(PDO::FETCH_ASSOC);
    
    // Transitions possibles
    $actions = getImagerieTransitionsPossibles($examen['statut'], $profil_code);
    
    // ---- RENDU VUE DETAIL ----
    ?>
    
    <?php if ($flash_message): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show">
            <?= htmlspecialchars($flash_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- EN-TETE -->
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <a href="index.php?page=imagerie&action=workflow" class="text-decoration-none text-muted mb-2 d-inline-block">
                <i class="bi bi-arrow-left me-1"></i>Retour au Kanban
            </a>
            <h2 class="mb-1" style="font-weight:700;">
                <i class="bi bi-camera me-2" style="color:#0d6efd;"></i>
                <?= htmlspecialchars($examen['code_examen']) ?>
            </h2>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($examen['type_examen'] ?? 'Examen non specifie') ?>
                — <?= getImagerieStatutBadge($examen['statut']) ?>
                <?= getImageriePrioriteBadge($examen['priorite'] ?? 'programme') ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php?page=imagerie&action=examens" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ul me-1"></i>Liste
            </a>
        </div>
    </div>
    
    <div class="row g-4">
        <!-- COLONNE GAUCHE : Infos + Actions -->
        <div class="col-lg-8">
            
            <!-- INFOS PATIENT -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                    <strong><i class="bi bi-person me-2"></i>Patient</strong>
                </div>
                <div class="card-body">
                    <?php if ($patient): ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <small class="text-muted d-block">Nom complet</small>
                                <strong><?= htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']) ?></strong>
                            </div>
                            <div class="col-md-2">
                                <small class="text-muted d-block">Sexe</small>
                                <?= $patient['sexe'] === 'M' ? 'Masculin' : 'Feminin' ?>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Date de naissance</small>
                                <?= !empty($patient['datenais']) ? date('d/m/Y', strtotime($patient['datenais'])) : '-' ?>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted d-block">Sejour</small>
                                <?= $examen['idsejour'] ? '#' . $examen['idsejour'] : '-' ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Patient non trouve (ID: <?= $examen['idpatient'] ?>)</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- DETAILS EXAMEN -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                    <strong><i class="bi bi-clipboard2-data me-2"></i>Details de l'examen</strong>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Type d'examen</small>
                            <strong><?= htmlspecialchars($examen['type_examen'] ?? '-') ?></strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Salle</small>
                            <?= htmlspecialchars($examen['salle'] ?? '-') ?>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Equipement</small>
                            <?= htmlspecialchars($examen['equipement'] ?? '-') ?>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Date RDV</small>
                            <?= $examen['date_rdv'] ? date('d/m/Y H:i', strtotime($examen['date_rdv'])) : '-' ?>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Duree estimee</small>
                            <?= $examen['duree_estimee_min'] ? $examen['duree_estimee_min'] . ' min' : '-' ?>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Protocole</small>
                            <?= htmlspecialchars($examen['protocole_utilise'] ?? '-') ?>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block">Qualite images</small>
                            <?= getImagerieQualiteBadge($examen['qualite_images']) ?>
                        </div>
                    </div>
                    
                    <?php if ($examen['produits_contraste']): ?>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Produit de contraste</small>
                            <?= htmlspecialchars($examen['produits_contraste']) ?>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Dose</small>
                            <?= htmlspecialchars($examen['dose_contraste'] ?? '-') ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($examen['artefacts']): ?>
                    <div class="mt-3">
                        <small class="text-muted d-block">Artefacts</small>
                        <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($examen['artefacts']) ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($examen['reprise_acquisition']): ?>
                    <div class="mt-3">
                        <small class="text-muted d-block">Reprise d'acquisition</small>
                        <span class="text-danger"><i class="bi bi-arrow-repeat me-1"></i>Oui — <?= htmlspecialchars($examen['motif_reprise'] ?? '') ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- PERSONNEL ASSIGNE -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                    <strong><i class="bi bi-people me-2"></i>Personnel assigne</strong>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $roles = [
                            'secretaire_accueil'    => ['Secretaire accueil', 'bi-person-badge'],
                            'manipulateur'          => ['Manipulateur/Technicien', 'bi-person-gear'],
                            'radiologue'            => ['Radiologue', 'bi-person-workspace'],
                            'radiologue_validateur' => ['Radiologue validateur', 'bi-person-check'],
                        ];
                        foreach ($roles as $field => $role_info):
                            $uid = $examen[$field] ?? null;
                            $user = $uid ? ($users[$uid] ?? null) : null;
                        ?>
                        <div class="col-md-3">
                            <small class="text-muted d-block"><i class="bi <?= $role_info[1] ?> me-1"></i><?= $role_info[0] ?></small>
                            <?php if ($user): ?>
                                <strong><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></strong>
                            <?php else: ?>
                                <span class="text-muted">Non assigne</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- COMPTE-RENDU -->
            <?php if (in_array($examen['statut'], IMAGERIE_STATUTS_COMPTE_RENDU) || $examen['compte_rendu_text']): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                    <strong><i class="bi bi-file-text me-2"></i>Compte-rendu</strong>
                </div>
                <div class="card-body">
                    <?php if ($examen['compte_rendu_text']): ?>
                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">Compte-rendu</small>
                            <div style="white-space: pre-wrap; background:#f8f9fa; padding:1rem; border-radius:0.5rem; font-size:0.9rem;">
                                <?= htmlspecialchars($examen['compte_rendu_text']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($examen['conclusion']): ?>
                        <div class="mb-3">
                            <small class="text-muted d-block mb-1">Conclusion</small>
                            <div style="white-space: pre-wrap; background:#d1e7dd; padding:1rem; border-radius:0.5rem; font-size:0.9rem; color:#0f5132;">
                                <?= htmlspecialchars($examen['conclusion']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($examen['recommandations']): ?>
                        <div>
                            <small class="text-muted d-block mb-1">Recommandations</small>
                            <div style="white-space: pre-wrap; background:#cff4fc; padding:1rem; border-radius:0.5rem; font-size:0.9rem; color:#055160;">
                                <?= htmlspecialchars($examen['recommandations']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!$examen['compte_rendu_text'] && !$examen['conclusion']): ?>
                        <p class="text-muted mb-0"><i class="bi bi-hourglass me-1"></i>Compte-rendu en attente de redaction.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- BOUTONS DE TRANSITION -->
            <?php if (!empty($actions)): ?>
            <div class="card border-0 shadow-sm mb-4" style="border-left: 4px solid #0d6efd !important;">
                <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                    <strong><i class="bi bi-arrow-right-circle me-2"></i>Actions disponibles</strong>
                </div>
                <div class="card-body">
                    <form method="POST" id="transitionForm">
                        <input type="hidden" name="code_examen" value="<?= htmlspecialchars($examen['code_examen']) ?>">
                        
                        <div class="mb-3">
                            <label class="form-label" style="font-size:0.85rem; font-weight:600;">Observation (optionnel)</label>
                            <textarea name="observation" class="form-control" rows="2" 
                                      placeholder="Commentaire sur cette transition..."></textarea>
                        </div>
                        
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($actions as $new_st => $info): ?>
                                <button type="submit" name="transition_to" value="<?= $new_st ?>" 
                                        class="btn <?= $info[2] ?>"
                                        onclick="return confirm('Confirmer : <?= addslashes($info[0]) ?> ?');">
                                    <i class="bi <?= $info[1] ?> me-1"></i><?= htmlspecialchars($info[0]) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- COLONNE DROITE : Timeline -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                    <strong><i class="bi bi-clock-history me-2"></i>Historique</strong>
                    <span class="badge bg-secondary ms-2"><?= count($historique) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($historique)): ?>
                        <div class="text-center text-muted py-4">Aucun historique</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush" style="font-size:0.82rem;">
                            <?php foreach ($historique as $h): ?>
                            <div class="list-group-item px-3 py-2">
                                <div class="d-flex justify-content-between">
                                    <strong><?= htmlspecialchars($h['action']) ?></strong>
                                    <small class="text-muted"><?= date('d/m H:i', strtotime($h['created_at'])) ?></small>
                                </div>
                                <div class="mt-1">
                                    <?php if ($h['ancien_statut']): ?>
                                        <?= getImagerieStatutBadge($h['ancien_statut']) ?>
                                        <i class="bi bi-arrow-right mx-1" style="font-size:0.7rem;"></i>
                                    <?php endif; ?>
                                    <?= getImagerieStatutBadge($h['nouveau_statut']) ?>
                                </div>
                                <?php if ($h['user_nom']): ?>
                                    <small class="text-muted">par <?= htmlspecialchars($h['user_prenom'] . ' ' . $h['user_nom']) ?></small>
                                <?php endif; ?>
                                <?php if ($h['observation']): ?>
                                    <div class="mt-1 text-muted" style="font-style:italic;">
                                        <?= htmlspecialchars($h['observation']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    return; // fin du mode detail
}

// =============================================
// MODE KANBAN (vue par defaut)
// =============================================

// Charger tous les examens actifs
$stmt = $conn_services->query("
    SELECT e.*,
           TIMESTAMPDIFF(MINUTE, e.date_rdv, NOW()) as delai_minutes
    FROM imagerie_examens e
    WHERE e.statut NOT IN ('annule')
    ORDER BY 
        CASE e.priorite 
            WHEN 'extreme_urgence' THEN 1 
            WHEN 'urgence' THEN 2 
            ELSE 3 
        END,
        e.date_rdv ASC
");
$tous_examens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recuperer tous les patients
$patients = [];
if (!empty($tous_examens)) {
    $ids = array_unique(array_column($tous_examens, 'idpatient'));
    if (!empty($ids)) {
        // Réindexer le tableau pour avoir des clés numériques consécutives
        $ids = array_values($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom FROM patient WHERE idpatient IN ($placeholders)");
        
        // Exécuter avec les valeurs
        $stmt_p->execute($ids);
        
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $patients[$p['idpatient']] = $p;
        }
    }
}

// Grouper par colonne kanban
$kanban_data = [];
foreach ($kanban_colonnes as $col_key => $col_info) {
    $kanban_data[$col_key] = [];
    foreach ($tous_examens as $ex) {
        if (in_array($ex['statut'], $col_info['statuts'])) {
            $kanban_data[$col_key][] = $ex;
        }
    }
}
?>

<?php if ($flash_message): ?>
    <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show">
        <?= htmlspecialchars($flash_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- EN-TETE -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1" style="font-weight:700; color:#0d6efd;">
            <i class="bi bi-kanban me-2"></i>Workflow Imagerie
        </h2>
        <p class="text-muted mb-0">Vue Kanban — <?= count($tous_examens) ?> examen(s) actif(s)</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=imagerie&action=dashboard" class="btn btn-outline-secondary">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="index.php?page=imagerie&action=examens" class="btn btn-outline-primary">
            <i class="bi bi-list-ul me-1"></i>Liste
        </a>
    </div>
</div>

<!-- KANBAN BOARD -->
<div class="row g-3" style="min-height:400px;">
    <?php foreach ($kanban_colonnes as $col_key => $col_info): 
        $items = $kanban_data[$col_key];
        $count = count($items);
    ?>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header border-0 d-flex align-items-center justify-content-between" 
                 style="background:<?= $col_info['color'] ?>15; padding:0.75rem 1rem;">
                <strong style="color:<?= $col_info['color'] ?>; font-size:0.85rem;">
                    <?= $col_info['label'] ?>
                </strong>
                <span class="badge" style="background:<?= $col_info['color'] ?>; color:white;">
                    <?= $count ?>
                </span>
            </div>
            <div class="card-body p-2" style="max-height:600px; overflow-y:auto;">
                <?php if (empty($items)): ?>
                    <div class="text-center text-muted py-3" style="font-size:0.8rem;">
                        <i class="bi bi-inbox"></i><br>Vide
                    </div>
                <?php else: ?>
                    <?php foreach ($items as $ex): 
                        $pat = $patients[$ex['idpatient']] ?? null;
                        $en_retard = isImagerieEnRetard($ex);
                    ?>
                    <a href="index.php?page=imagerie&action=workflow&code=<?= urlencode($ex['code_examen']) ?>" 
                       class="card mb-2 border-0 text-decoration-none" 
                       style="background:<?= $en_retard ? '#fff3cd' : '#f8f9fa' ?>; transition:transform 0.15s;">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <code style="font-size:0.7rem;"><?= htmlspecialchars($ex['code_examen']) ?></code>
                                <?php if ($ex['urgence']): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:0.7rem;"></i>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($pat): ?>
                                <div style="font-size:0.78rem; font-weight:600; color:#212529;">
                                    <?= htmlspecialchars($pat['nom'] . ' ' . $pat['prenom']) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div style="font-size:0.72rem; color:#6c757d;" class="mt-1">
                                <?= htmlspecialchars($ex['type_examen'] ?? '-') ?>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <?= getImagerieStatutBadge($ex['statut']) ?>
                                <?php if ($ex['date_rdv']): ?>
                                    <small class="text-muted" style="font-size:0.68rem;">
                                        <?= date('H:i', strtotime($ex['date_rdv'])) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($ex['salle']): ?>
                                <div style="font-size:0.7rem; color:#6c757d;" class="mt-1">
                                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($ex['salle']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
.card a.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
</style>
