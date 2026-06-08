<?php
/**
 * Module Pharmacie - Workflow
 * 
 * Gestion des transitions de statut d'une preparation.
 * Si ?code=XXX est fourni, on affiche le detail + actions.
 * Sinon, on affiche le tableau Kanban par colonnes de statut.
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';
$statut_labels    = $GLOBALS['pharma_statut_labels'];
$transitions      = $GLOBALS['pharma_transitions'];
$kanban_colonnes  = $GLOBALS['pharma_kanban_colonnes'];

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

$user_id          = $_SESSION['user_id'];
$user_profil_code = $_SESSION['user_profil_code'] ?? '';
$user_nom         = $_SESSION['user_nom'] ?? '';

// =============================================
// TRAITEMENT POST : TRANSITION DE STATUT
// =============================================

$flash_message = '';
$flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_transition'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if ($csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $flash_message = 'Token CSRF invalide. Veuillez reessayer.';
        $flash_type = 'danger';
    } else {
        $post_code    = sanitizeInput($_POST['code_preparation'] ?? '');
        $post_new_st  = sanitizeInput($_POST['nouveau_statut'] ?? '');
        $post_obs     = trim($_POST['observation'] ?? '');
        
        // Charger la preparation
        $stmt_check = $conn_services->prepare("SELECT idpreparation, statut FROM pharmacie_preparations WHERE code_preparation = ? AND deleted_at IS NULL");
        $stmt_check->execute([$post_code]);
        $prep_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$prep_check) {
            $flash_message = 'Preparation introuvable.';
            $flash_type = 'danger';
        } else {
            $old_statut = $prep_check['statut'];
            
            // Verifier transition autorisee
            if (!isset($transitions[$old_statut][$post_new_st])) {
                $flash_message = "Transition non autorisee: $old_statut -> $post_new_st";
                $flash_type = 'danger';
            } else {
                $profils_ok = $transitions[$old_statut][$post_new_st][3];
                if (!in_array($user_profil_code, $profils_ok)) {
                    $flash_message = "Votre profil n'est pas autorise pour cette action.";
                    $flash_type = 'danger';
                } else {
                    $action_label = $transitions[$old_statut][$post_new_st][0];
                    
                    try {
                        $conn_services->beginTransaction();
                        
                        // MAJ statut
                        $stmt_up = $conn_services->prepare("UPDATE pharmacie_preparations SET statut = ? WHERE idpreparation = ?");
                        $stmt_up->execute([$post_new_st, $prep_check['idpreparation']]);
                        
                        // MAJ champs specifiques selon la transition
                        $updates = [];
                        $up_params = [':id' => $prep_check['idpreparation']];
                        
                        switch ($post_new_st) {
                            case 'verification_stock':
                                $updates[] = "pharmacien_verif = :uid";
                                $updates[] = "date_verification = NOW()";
                                $up_params[':uid'] = $user_id;
                                break;
                            case 'en_preparation':
                                $updates[] = "pharmacien_prep = :uid";
                                $updates[] = "date_debut_preparation = NOW()";
                                $up_params[':uid'] = $user_id;
                                break;
                            case 'preparation_terminee':
                                $updates[] = "date_fin_preparation = NOW()";
                                break;
                            case 'controle_qualite':
                                $updates[] = "pharmacien_controle = :uid";
                                $updates[] = "date_controle = NOW()";
                                $up_params[':uid'] = $user_id;
                                break;
                            case 'prete':
                                $updates[] = "controle_conforme = 1";
                                break;
                            case 'delivree':
                                $updates[] = "pharmacien_delivrance = :uid";
                                $updates[] = "date_delivrance = NOW()";
                                $up_params[':uid'] = $user_id;
                                break;
                            case 'retournee':
                                $updates[] = "date_retour = NOW()";
                                break;
                        }
                        
                        if (!empty($updates)) {
                            $sql_up = "UPDATE pharmacie_preparations SET " . implode(', ', $updates) . " WHERE idpreparation = :id";
                            $stmt_extra = $conn_services->prepare($sql_up);
                            $stmt_extra->execute($up_params);
                        }
                        
                        // Logger historique
                        $stmt_log = $conn_services->prepare("
                            INSERT INTO pharmacie_workflow_history 
                            (idpreparation, ancien_statut, nouveau_statut, action, idutilisateur, observation, ip_address, user_agent)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt_log->execute([
                            $prep_check['idpreparation'],
                            $old_statut,
                            $post_new_st,
                            $action_label,
                            $user_id,
                            $post_obs ?: null,
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                        
                        $conn_services->commit();
                        $flash_message = "Transition effectuee: $action_label";
                        $flash_type = 'success';
                    } catch (Exception $e) {
                        $conn_services->rollBack();
                        $flash_message = 'Erreur lors de la transition: ' . $e->getMessage();
                        $flash_type = 'danger';
                        error_log("[CSK Services][Pharmacie Workflow] Erreur transition: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// Generer un nouveau token CSRF
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// =============================================
// MODE : DETAIL D'UNE PREPARATION OU KANBAN
// =============================================

$code = isset($_GET['code']) ? trim($_GET['code']) : '';

if ($code):
    // =============================================
    // VUE DETAIL
    // =============================================
    
    $stmt = $conn_services->prepare("SELECT * FROM pharmacie_preparations WHERE code_preparation = ?");
    $stmt->execute([$code]);
    $preparation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$preparation): ?>
        <div class="alert alert-danger">Preparation introuvable: <strong><?= htmlspecialchars($code) ?></strong></div>
        <a href="index.php?page=pharmacie&action=workflow" class="btn btn-outline-secondary">Retour au workflow</a>
    <?php return; endif;
    
    // Patient
    $stmt_p = $conn_base->prepare("SELECT * FROM patient WHERE idpatient = ?");
    $stmt_p->execute([$preparation['idpatient']]);
    $patient = $stmt_p->fetch(PDO::FETCH_ASSOC);
    
    // Prescription + Produit
    $prescription = null;
    $produit = null;
    if ($preparation['idpharma_presc']) {
        $stmt_pr = $conn_base->prepare("
            SELECT pp.*, pr.libelle as produit_libelle, pr.code as produit_code, 
                   pr.type_produit, pr.forme, pr.voie, pr.unite, pr.prix_achat, pr.prix_vente,
                   pr.seuil_alerte, pr.disponibilite
            FROM pharma_presc pp
            LEFT JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
            WHERE pp.idpharma_presc = ?
        ");
        $stmt_pr->execute([$preparation['idpharma_presc']]);
        $prescription = $stmt_pr->fetch(PDO::FETCH_ASSOC);
        if ($prescription) {
            $produit = [
                'libelle' => $prescription['produit_libelle'],
                'code' => $prescription['produit_code'],
                'type_produit' => $prescription['type_produit'],
                'forme' => $prescription['forme'],
                'voie' => $prescription['voie'],
                'unite' => $prescription['unite'],
                'disponibilite' => $prescription['disponibilite'],
                'seuil_alerte' => $prescription['seuil_alerte'],
            ];
        }
    }
    
    // Personnel (noms)
    $personnel_ids = array_filter([
        $preparation['pharmacien_verif'] ?? null,
        $preparation['pharmacien_prep'] ?? null,
        $preparation['pharmacien_controle'] ?? null,
        $preparation['pharmacien_delivrance'] ?? null,
    ]);
    $personnel = [];
    if (!empty($personnel_ids)) {
        $ph = implode(',', array_fill(0, count($personnel_ids), '?'));
        $stmt_u = $conn_base->prepare("SELECT idutilisateur, nom, prenom FROM utilisateur WHERE idutilisateur IN ($ph)");
        $stmt_u->execute(array_values($personnel_ids));
        foreach ($stmt_u->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $personnel[$u['idutilisateur']] = trim($u['prenom'] . ' ' . $u['nom']);
        }
    }
    
    // Prescripteur
    $prescripteur_nom = null;
    if (!empty($prescription['prescripteur'])) {
        $stmt_pres = $conn_base->prepare("SELECT nom, prenom FROM utilisateur WHERE idutilisateur = ?");
        $stmt_pres->execute([$prescription['prescripteur']]);
        $pres = $stmt_pres->fetch(PDO::FETCH_ASSOC);
        if ($pres) $prescripteur_nom = trim($pres['prenom'] . ' ' . $pres['nom']);
    }
    
    // Historique workflow
    $stmt_h = $conn_services->prepare("
        SELECT h.*, u.nom as user_nom, u.prenom as user_prenom
        FROM pharmacie_workflow_history h
        LEFT JOIN (SELECT idutilisateur, nom, prenom FROM {$GLOBALS['db_base_name']}.utilisateur) u 
            ON h.idutilisateur = u.idutilisateur
        ORDER BY h.created_at ASC
    ");
    // Fallback si la jointure cross-base ne marche pas
    $stmt_h = $conn_services->prepare("
        SELECT h.action, h.ancien_statut, h.nouveau_statut, h.observation, h.created_at, h.idutilisateur
        FROM pharmacie_workflow_history h
        WHERE h.idpreparation = ?
        ORDER BY h.created_at ASC
    ");
    $stmt_h->execute([$preparation['idpreparation']]);
    $historique = $stmt_h->fetchAll(PDO::FETCH_ASSOC);
    
    // Noms utilisateurs historique
    $hist_uids = array_unique(array_column($historique, 'idutilisateur'));
    $hist_users = [];
    if (!empty($hist_uids)) {
        $ph = implode(',', array_fill(0, count($hist_uids), '?'));
        $stmt_hu = $conn_base->prepare("SELECT idutilisateur, nom, prenom FROM utilisateur WHERE idutilisateur IN ($ph)");
        $stmt_hu->execute(array_values($hist_uids));
        foreach ($stmt_hu->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $hist_users[$u['idutilisateur']] = trim($u['prenom'] . ' ' . $u['nom']);
        }
    }
    
    // Transitions possibles
    $actions_possibles = getPharmaTransitionsPossibles($preparation['statut'], $user_profil_code);
    $en_retard = isPharmaEnRetard($preparation);

?>

<!-- Flash message -->
<?php if ($flash_message): ?>
<div class="alert alert-<?= $flash_type ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash_message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Header detail -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">
            <i class="bi bi-capsule me-2"></i>
            Preparation <code><?= htmlspecialchars($preparation['code_preparation']) ?></code>
        </h3>
        <div class="d-flex gap-2 align-items-center">
            <?= getPharmaStatutBadge($preparation['statut']) ?>
            <?= getPharmaUrgenceBadge(!empty($preparation['urgence'])) ?>
            <?php if ($en_retard): ?>
                <span class="badge bg-danger"><i class="bi bi-clock-fill me-1"></i>En retard</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=pharmacie&action=workflow" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-kanban me-1"></i>Kanban
        </a>
        <a href="index.php?page=pharmacie&action=preparations" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-ul me-1"></i>Liste
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Colonne gauche : infos -->
    <div class="col-lg-7">
        
        <!-- Patient -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-0 py-2">
                <h6 class="mb-0"><i class="bi bi-person me-1"></i>Patient</h6>
            </div>
            <div class="card-body py-2">
                <?php if ($patient): ?>
                <div class="row g-2" style="font-size:0.9rem;">
                    <div class="col-sm-6"><strong>Nom:</strong> <?= htmlspecialchars($patient['nom'] . ' ' . ($patient['prenom'] ?? '')) ?></div>
                    <div class="col-sm-3"><strong>Sexe:</strong> <?= htmlspecialchars($patient['sexe'] ?? '-') ?></div>
                    <div class="col-sm-3"><strong>Ne(e):</strong> <?= !empty($patient['datenais']) ? date('d/m/Y', strtotime($patient['datenais'])) : '-' ?></div>
                </div>
                <?php else: ?>
                <span class="text-muted">Patient #<?= $preparation['idpatient'] ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Prescription + Produit -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-0 py-2">
                <h6 class="mb-0"><i class="bi bi-file-medical me-1"></i>Prescription & Produit</h6>
            </div>
            <div class="card-body py-2">
                <?php if ($prescription): ?>
                <div class="row g-2" style="font-size:0.9rem;">
                    <div class="col-sm-6">
                        <strong>Produit:</strong> <?= htmlspecialchars($produit['libelle'] ?? '-') ?>
                        <?php if ($produit['code'] ?? null): ?>
                            <code class="ms-1"><?= htmlspecialchars($produit['code']) ?></code>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-3"><strong>Forme:</strong> <?= htmlspecialchars($produit['forme'] ?? '-') ?></div>
                    <div class="col-sm-3"><strong>Voie:</strong> <?= htmlspecialchars($produit['voie'] ?? '-') ?></div>
                    <div class="col-sm-4"><strong>Quantite:</strong> <?= htmlspecialchars($prescription['quantite'] ?? '-') ?> <?= htmlspecialchars($produit['unite'] ?? '') ?></div>
                    <div class="col-sm-4"><strong>Posologie:</strong> <?= htmlspecialchars($prescription['posologie'] ?? '-') ?></div>
                    <div class="col-sm-4"><strong>Prescripteur:</strong> <?= htmlspecialchars($prescripteur_nom ?? '-') ?></div>
                    <div class="col-12 mt-1">
                        <strong>Stock actuel:</strong> 
                        <?php 
                        $stock = (int)($produit['disponibilite'] ?? 0);
                        $seuil = (int)($produit['seuil_alerte'] ?? 0);
                        $stock_color = $stock <= 0 ? 'text-danger' : ($stock <= $seuil ? 'text-warning' : 'text-success');
                        ?>
                        <span class="fw-bold <?= $stock_color ?>"><?= $stock ?></span>
                        <small class="text-muted">(seuil alerte: <?= $seuil ?>)</small>
                    </div>
                </div>
                <?php else: ?>
                <span class="text-muted">Prescription non trouvee</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Details preparation -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-0 py-2">
                <h6 class="mb-0"><i class="bi bi-capsule me-1"></i>Details preparation</h6>
            </div>
            <div class="card-body py-2">
                <div class="row g-2" style="font-size:0.9rem;">
                    <div class="col-sm-4"><strong>Lot:</strong> <?= htmlspecialchars($preparation['numero_lot'] ?? '-') ?></div>
                    <div class="col-sm-4"><strong>Date expiration:</strong> <?= !empty($preparation['date_expiration']) ? date('d/m/Y', strtotime($preparation['date_expiration'])) : '-' ?></div>
                    <div class="col-sm-4"><strong>Controle conforme:</strong> 
                        <?php if ($preparation['controle_conforme'] === null): ?>
                            <span class="text-muted">En attente</span>
                        <?php elseif ($preparation['controle_conforme']): ?>
                            <span class="text-success fw-semibold"><i class="bi bi-check-circle"></i> Conforme</span>
                        <?php else: ?>
                            <span class="text-danger fw-semibold"><i class="bi bi-x-circle"></i> Non conforme</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($preparation['observation_controle'])): ?>
                    <div class="col-12"><strong>Observation controle:</strong> <?= htmlspecialchars($preparation['observation_controle']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($preparation['motif_retour'])): ?>
                    <div class="col-12"><strong>Motif retour:</strong> <span class="text-danger"><?= htmlspecialchars($preparation['motif_retour']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($preparation['motif_annulation'])): ?>
                    <div class="col-12"><strong>Motif annulation:</strong> <span class="text-danger"><?= htmlspecialchars($preparation['motif_annulation']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Personnel -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-0 py-2">
                <h6 class="mb-0"><i class="bi bi-people me-1"></i>Personnel</h6>
            </div>
            <div class="card-body py-2">
                <div class="row g-2" style="font-size:0.9rem;">
                    <div class="col-sm-6"><strong>Verification:</strong> <?= htmlspecialchars($personnel[$preparation['pharmacien_verif'] ?? 0] ?? '-') ?></div>
                    <div class="col-sm-6"><strong>Preparation:</strong> <?= htmlspecialchars($personnel[$preparation['pharmacien_prep'] ?? 0] ?? '-') ?></div>
                    <div class="col-sm-6"><strong>Controle:</strong> <?= htmlspecialchars($personnel[$preparation['pharmacien_controle'] ?? 0] ?? '-') ?></div>
                    <div class="col-sm-6"><strong>Delivrance:</strong> <?= htmlspecialchars($personnel[$preparation['pharmacien_delivrance'] ?? 0] ?? '-') ?></div>
                </div>
            </div>
        </div>

        <!-- Timing -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-0 py-2">
                <h6 class="mb-0"><i class="bi bi-clock me-1"></i>Timing</h6>
            </div>
            <div class="card-body py-2">
                <div class="row g-2" style="font-size:0.9rem;">
                    <div class="col-sm-4"><strong>Creee:</strong> <?= date('d/m/Y H:i', strtotime($preparation['created_at'])) ?></div>
                    <div class="col-sm-4"><strong>Verification:</strong> <?= !empty($preparation['date_verification']) ? date('d/m H:i', strtotime($preparation['date_verification'])) : '-' ?></div>
                    <div class="col-sm-4"><strong>Debut prep:</strong> <?= !empty($preparation['date_debut_preparation']) ? date('d/m H:i', strtotime($preparation['date_debut_preparation'])) : '-' ?></div>
                    <div class="col-sm-4"><strong>Fin prep:</strong> <?= !empty($preparation['date_fin_preparation']) ? date('d/m H:i', strtotime($preparation['date_fin_preparation'])) : '-' ?></div>
                    <div class="col-sm-4"><strong>Controle:</strong> <?= !empty($preparation['date_controle']) ? date('d/m H:i', strtotime($preparation['date_controle'])) : '-' ?></div>
                    <div class="col-sm-4"><strong>Delivrance:</strong> <?= !empty($preparation['date_delivrance']) ? date('d/m H:i', strtotime($preparation['date_delivrance'])) : '-' ?></div>
                </div>
                <div class="mt-2">
                    <strong>Delai total:</strong> <?= getPharmaDelaiProgressHtml($preparation) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Colonne droite : actions + timeline -->
    <div class="col-lg-5">
        
        <!-- Actions -->
        <?php if (!empty($actions_possibles)): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-0 py-2">
                <h6 class="mb-0"><i class="bi bi-lightning me-1"></i>Actions disponibles</h6>
            </div>
            <div class="card-body py-3">
                <?php foreach ($actions_possibles as $new_st => $info): ?>
                <form method="POST" class="mb-2">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action_transition" value="1">
                    <input type="hidden" name="code_preparation" value="<?= htmlspecialchars($preparation['code_preparation']) ?>">
                    <input type="hidden" name="nouveau_statut" value="<?= $new_st ?>">
                    
                    <div class="input-group input-group-sm">
                        <input type="text" name="observation" class="form-control" 
                               placeholder="Observation (optionnel)">
                        <button type="submit" class="btn <?= $info[2] ?>" 
                                onclick="return confirm('Confirmer: <?= htmlspecialchars($info[0]) ?> ?')">
                            <i class="bi <?= $info[1] ?> me-1"></i><?= htmlspecialchars($info[0]) ?>
                        </button>
                    </div>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Timeline -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-2">
                <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i>Historique</h6>
            </div>
            <div class="card-body py-2">
                <?php if (empty($historique)): ?>
                    <div class="text-center text-muted py-3">Aucun historique</div>
                <?php else: ?>
                <div class="position-relative" style="padding-left:20px;">
                    <div class="position-absolute" style="left:8px; top:0; bottom:0; width:2px; background:#dee2e6;"></div>
                    <?php foreach ($historique as $h): 
                        $h_info = $statut_labels[$h['nouveau_statut']] ?? ['color' => '#6c757d'];
                    ?>
                    <div class="position-relative mb-3 ps-3">
                        <div class="position-absolute" style="left:-14px; top:4px; width:12px; height:12px; border-radius:50%; background:<?= $h_info['color'] ?>; border:2px solid #fff;"></div>
                        <div>
                            <div class="d-flex justify-content-between align-items-start">
                                <strong style="font-size:0.85rem;"><?= htmlspecialchars($h['action']) ?></strong>
                                <small class="text-muted"><?= date('d/m H:i', strtotime($h['created_at'])) ?></small>
                            </div>
                            <div style="font-size:0.8rem;">
                                <?php if ($h['ancien_statut']): ?>
                                    <?= getPharmaStatutBadge($h['ancien_statut']) ?>
                                    <i class="bi bi-arrow-right mx-1"></i>
                                <?php endif; ?>
                                <?= getPharmaStatutBadge($h['nouveau_statut']) ?>
                            </div>
                            <div class="text-muted" style="font-size:0.75rem;">
                                Par: <?= htmlspecialchars($hist_users[$h['idutilisateur']] ?? 'Utilisateur #'.$h['idutilisateur']) ?>
                            </div>
                            <?php if (!empty($h['observation'])): ?>
                            <div class="text-muted fst-italic" style="font-size:0.75rem;">
                                <?= htmlspecialchars($h['observation']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: 
    // =============================================
    // VUE KANBAN
    // =============================================
    
    // Charger toutes les preparations actives
    $stmt_all = $conn_services->query("
        SELECT p.*
        FROM pharmacie_preparations p
        WHERE p.statut NOT IN ('annulee','retournee')
        ORDER BY p.urgence DESC, p.created_at ASC
    ");
    $all_preps = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    
    // Patients
    $pat_ids = array_unique(array_column($all_preps, 'idpatient'));
    $patients = [];
    if (!empty($pat_ids)) {
        $ph = implode(',', array_fill(0, count($pat_ids), '?'));
        $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom FROM patient WHERE idpatient IN ($ph)");
        $stmt_p->execute(array_values($pat_ids));
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $patients[$p['idpatient']] = trim($p['nom'] . ' ' . $p['prenom']);
        }
    }
    
    // Produits
    $presc_ids = array_unique(array_filter(array_column($all_preps, 'idpharma_presc')));
    $produits_map = [];
    if (!empty($presc_ids)) {
        $ph = implode(',', array_fill(0, count($presc_ids), '?'));
        $stmt_pr = $conn_base->prepare("
            SELECT pp.idpharma_presc, pr.libelle as produit_libelle
            FROM pharma_presc pp
            LEFT JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
            WHERE pp.idpharma_presc IN ($ph)
        ");
        $stmt_pr->execute(array_values($presc_ids));
        foreach ($stmt_pr->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $produits_map[$r['idpharma_presc']] = $r['produit_libelle'];
        }
    }
    
    // Grouper par colonne kanban
    $kanban = [];
    foreach ($kanban_colonnes as $col_key => $col) {
        $kanban[$col_key] = [];
    }
    foreach ($all_preps as $prep) {
        foreach ($kanban_colonnes as $col_key => $col) {
            if (in_array($prep['statut'], $col['statuts'])) {
                $kanban[$col_key][] = $prep;
                break;
            }
        }
    }
?>

<!-- Flash message -->
<?php if ($flash_message): ?>
<div class="alert alert-<?= $flash_type ?> alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($flash_message) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Header Kanban -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1"><i class="bi bi-kanban me-2"></i>Workflow Pharmacie</h3>
        <small class="text-muted"><?= count($all_preps) ?> preparation(s) active(s)</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=pharmacie" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="index.php?page=pharmacie&action=preparations" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list-ul me-1"></i>Liste
        </a>
    </div>
</div>

<!-- Kanban Board -->
<div class="d-flex gap-3 overflow-auto pb-3" style="min-height:60vh;">
    <?php foreach ($kanban_colonnes as $col_key => $col): 
        $items = $kanban[$col_key];
    ?>
    <div class="flex-shrink-0" style="width:260px;">
        <!-- Colonne header -->
        <div class="d-flex align-items-center justify-content-between mb-2 px-2">
            <div>
                <span class="fw-bold" style="color:<?= $col['color'] ?>; font-size:0.85rem;">
                    <?= htmlspecialchars($col['label']) ?>
                </span>
            </div>
            <span class="badge rounded-pill" style="background:<?= $col['color'] ?>;"><?= count($items) ?></span>
        </div>
        
        <!-- Cards -->
        <div class="d-flex flex-column gap-2" style="min-height:200px; background:#f8f9fa; border-radius:8px; padding:8px;">
            <?php if (empty($items)): ?>
                <div class="text-center text-muted py-4" style="font-size:0.8rem;">Aucune</div>
            <?php else: ?>
                <?php foreach ($items as $prep): 
                    $en_retard = isPharmaEnRetard($prep);
                ?>
                <a href="index.php?page=pharmacie&action=workflow&code=<?= urlencode($prep['code_preparation']) ?>" 
                   class="card border-0 shadow-sm text-decoration-none <?= $en_retard ? 'border-danger border' : '' ?>" 
                   style="font-size:0.8rem;">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <code class="fw-semibold text-dark"><?= htmlspecialchars($prep['code_preparation']) ?></code>
                            <?php if (!empty($prep['urgence'])): ?>
                                <span class="badge bg-danger" style="font-size:0.65rem;">URG</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-dark mb-1"><?= htmlspecialchars($patients[$prep['idpatient']] ?? 'Patient #'.$prep['idpatient']) ?></div>
                        <div class="text-muted text-truncate mb-1" style="max-width:220px;">
                            <?= htmlspecialchars($produits_map[$prep['idpharma_presc']] ?? '-') ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <?= getPharmaStatutBadge($prep['statut']) ?>
                            <?php if ($en_retard): ?>
                                <span class="text-danger" style="font-size:0.7rem;"><i class="bi bi-clock-fill"></i> Retard</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>
