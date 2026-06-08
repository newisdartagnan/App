<?php
/**
 * Module Laboratoire — Workflow
 *
 * Workflow simplifié 3 étapes :
 *   attente_prelevement → preleve → validation_technique
 *
 * VERSION csk_base (CSK.sql) :
 *   - csk_base.patient     : idpatient, Noms, Prenom, Date_de_naissance, SEXE
 *   - csk_base.actes_presc  : idactes_presc, IDActe, IDUser_presc, Statut(int 1/2/3)
 *   - csk_base.acte        : IDActe, Nom (libelle), idsous_specialite
 *   - csk_base.utilisateur : idutilisateur, Noms
 *   - csk_base.resultatslabo : idactes_presc, resultat, Observation_achev (valeur_normale),
 *                                  IDUser_val (varchar nom), date_analyse, interpretation
 *   - Paiement : Acte_presc.Statut >= 2 (= payé)
 */

require_once __DIR__ . '/../../includes/labo_helpers.php';

$DB = defined('DB_MAIN') ? DB_MAIN : 'csk_base';  // Nom de la base principale

$current_user     = getCurrentUser();
$user_id          = $current_user['id'];
$user_profil_code = $current_user['profil_code'];

$statut_labels = $GLOBALS['labo_statut_labels'];
$tube_colors   = $GLOBALS['labo_tube_colors'];
$transitions   = $GLOBALS['labo_transitions'];

$db            = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();  // csk_base

$code_param = isset($_GET['code']) ? sanitizeInput($_GET['code']) : '';

// ─────────────────────────────────────────────────────────────
// POST : transition individuelle sur UN sous-examen
// ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_workflow'])) {
    $code_ech    = sanitizeInput($_POST['code_echantillon'] ?? '');
    $new_statut  = sanitizeInput($_POST['nouveau_statut']  ?? '');
    $observation = sanitizeInput($_POST['observation']     ?? '');
    $qualite     = sanitizeInput($_POST['qualite_echantillon'] ?? '');
    $comm_q      = sanitizeInput($_POST['commentaire_qualite'] ?? '');
    $action_label= sanitizeInput($_POST['action_label']    ?? '');
    $redirect_to = sanitizeInput($_POST['redirect_to']     ?? '');
    $error_wf    = '';

    if (!$code_ech || !$new_statut) {
        $error_wf = 'Données manquantes.';
    } else {
        try {
            $stmt = $conn_services->prepare("
                SELECT le.idechantillon, le.statut, le.code_echantillon, le.IDgroupe
                FROM {$DB}.labo_echantillons le
                WHERE le.code_echantillon = :code AND le.deleted_at IS NULL
            ");
            $stmt->execute([':code' => $code_ech]);
            $se = $stmt->fetch();

            if (!$se) {
                $error_wf = "Sous-examen introuvable.";
            } elseif (!isset($transitions[$se['statut']][$new_statut])) {
                $error_wf = "Transition non autorisée.";
            } elseif (!in_array($user_profil_code, $transitions[$se['statut']][$new_statut][3])) {
                $error_wf = "Votre profil n'est pas autorisé pour cette action.";
            }

            if (empty($error_wf)) {
                $ancien = $se['statut'];
                $conn_services->beginTransaction();

                $conn_services->prepare(
                    "UPDATE {$DB}.labo_echantillons SET statut = :s, updated_at = NOW() WHERE idechantillon = :id"
                )->execute([':s' => $new_statut, ':id' => $se['idechantillon']]);

                $sets = []; $p = [':id' => $se['idechantillon']];
                switch ($new_statut) {
                    case 'preleve':
                        $sets[] = "date_prelevement = NOW()";
                        $sets[] = "preleveur = :user";
                        $p[':user'] = $user_id;
                        break;
                    case 'rejete':
                    case 'perdu':
                        if ($qualite) { $sets[] = "qualite_echantillon = :q";  $p[':q']  = $qualite; }
                        if ($comm_q)  { $sets[] = "commentaire_qualite = :cq"; $p[':cq'] = $comm_q; }
                        break;
                }
                if (!empty($sets)) {
                    $conn_services->prepare(
                        "UPDATE {$DB}.labo_echantillons SET " . implode(', ', $sets) . " WHERE idechantillon = :id"
                    )->execute($p);
                }

                $conn_services->prepare("
                    INSERT INTO labo_workflow_history
                        (idechantillon, action, ancien_statut, nouveau_statut, idutilisateur, observation, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $se['idechantillon'],
                    $action_label ?: ($statut_labels[$new_statut]['label'] ?? $new_statut),
                    $ancien, $new_statut, $user_id, $observation ?: null,
                ]);

                $conn_services->commit();
                logAction('WORKFLOW_LABO', "{$code_ech} : $ancien -> $new_statut");
                setFlash('success', "Statut mis à jour pour <strong>$code_ech</strong>.");

                $redirect_code = $redirect_to ?: $code_ech;
                if (!$redirect_to && $se['idgroupe']) {
                    $cg = $conn_services->query(
                        "SELECT code_groupe FROM labo_groupes_echantillons WHERE idgroupe = {$se['idgroupe']}"
                    )->fetchColumn();
                    if ($cg) $redirect_code = $cg;
                }
                header("Location: " . BASE_URL . "index.php?page=labo&action=workflow&code=" . urlencode($redirect_code));
                exit();
            }
        } catch (Exception $e) {
            if ($conn_services->inTransaction()) $conn_services->rollBack();
            $error_wf = 'Erreur : ' . $e->getMessage();
            error_log("[Workflow] POST: " . $e->getMessage());
        }
    }
    if ($error_wf) setFlash('error', $error_wf);
}

// ─────────────────────────────────────────────────────────────
// CHARGEMENT : groupe
// ─────────────────────────────────────────────────────────────

$mode          = null;
$groupe        = null;
$sous_eches    = [];
$historique    = [];
$resultats_map = [];
$statut_global = 'attente_prelevement';

if ($code_param) {

    // Chercher comme GROUPE
    try {
        $stmt = $conn_services->prepare("
            SELECT lg.*,
                   p.nom             AS patient_nom,
                   p.prenom           AS patient_prenom,
                   p.sexe             AS patient_sexe,
                   p.date_naissance AS patient_dob
            FROM labo_groupes_echantillons lg
            JOIN {$DB}.patient p ON p.idpatient = lg.idpatient
            WHERE lg.code_groupe = :code
        ");
        $stmt->execute([':code' => $code_param]);
        $groupe_row = $stmt->fetch();
    } catch (Exception $e) {
        error_log("[Workflow] Cherche groupe: " . $e->getMessage());
        $groupe_row = null;
    }

    if ($groupe_row) {
        $mode   = 'groupe';
        $groupe = $groupe_row;
        try {
            // ap.statut_execution >= 2 → payé (csk_base)
            $stmt = $conn_services->prepare("
                SELECT le.idechantillon, le.code_echantillon, le.sous_numero,
                       le.statut, le.type_prelevement, le.tube_type, le.couleur_tube,
                       le.urgence, le.date_prelevement, le.date_validation,
                       le.qualite_echantillon, le.idresultat, le.idactes_presc,
                       a.libelle  AS examen_libelle,
                       ap.statut_execution AS ap_statut
                FROM {$DB}.labo_echantillons le
                LEFT JOIN {$DB}.actes_presc ap ON ap.idactes_presc = le.idactes_presc
                LEFT JOIN {$DB}.acte       a  ON a.idacte        = ap.idacte
                WHERE le.IDgroupe = :id AND le.deleted_at IS NULL
                ORDER BY le.sous_numero
            ");
            $stmt->execute([':id' => $groupe['idgroupe']]);
            $sous_eches = $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("[Workflow] Sous-examens groupe: " . $e->getMessage());
        }

    } else {
        error_log("[Workflow] code_echantillon introuvable: " . $code_param);
    }

    if (!empty($sous_eches)) {
        $ids = array_values(array_filter(array_column($sous_eches, 'idechantillon')));
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            try {
                // Utilisateur.Noms = champ unique dans csk_base
                $stmt = $conn_services->prepare("
                    SELECT lwh.*,
                           u.nom AS user_nom,
                           le.code_echantillon
                    FROM labo_workflow_history lwh
                    LEFT JOIN {$DB}.utilisateur u ON u.idutilisateur = lwh.idutilisateur
                    LEFT JOIN {$DB}.labo_echantillons le ON le.idechantillon = lwh.idechantillon
                    WHERE lwh.idechantillon IN ($ph)
                    ORDER BY lwh.created_at ASC
                ");
                $stmt->execute($ids);
                $historique = $stmt->fetchAll();
            } catch (Exception $e) {}
        }

        $ids_res = array_values(array_filter(array_column($sous_eches, 'idresultat')));
        if (!empty($ids_res)) {
            $ph_res = implode(',', array_fill(0, count($ids_res), '?'));
            try {
                // resultat_labo : Observation_achev = valeurs normales, IDUser_val = varchar(nom), date_analyse
                $stmt = $conn_base->prepare("
                    SELECT r.idresultat, r.resultat,
                           r.observations  AS valeur_normale,
                           r.interpretation,
                           r.analyse_par         AS analyste_nom,
                           r.date_analyse           AS date_analyse
                    FROM resultatslabo r
                    WHERE r.idresultat IN ($ph_res)
                ");
                $stmt->execute($ids_res);
                foreach ($stmt->fetchAll() as $res) {
                    $resultats_map[$res['idresultat']] = $res;
                }
            } catch (Exception $e) {
                error_log("[Workflow] Résultats: " . $e->getMessage());
            }
        }

        $ordre   = ['attente_prelevement','preleve','validation_technique','annule','rejete','perdu'];
        $min_idx = PHP_INT_MAX;
        foreach ($sous_eches as $se_tmp) {
            $idx = array_search($se_tmp['statut'] ?? '', $ordre);
            if ($idx !== false && $idx < $min_idx) $min_idx = $idx;
        }
        $statut_global = $ordre[$min_idx] ?? 'attente_prelevement';
    }
}

// ─────────────────────────────────────────────────────────────
// KANBAN (vue sans code)
// ─────────────────────────────────────────────────────────────

$kanban = [];

if (!$code_param) {
    try {
        $stmt = $conn_services->query("
            SELECT
                lg.code_groupe AS code,
                lg.date_creation,
                lg.idgroupe,
                p.nom   AS patient_nom,
                p.prenom AS patient_prenom,
                MAX(le.urgence) AS urgence,
                ELT(MIN(FIELD(le.statut,
                    'attente_prelevement','preleve','validation_technique','annule','rejete','perdu')),
                    'attente_prelevement','preleve','validation_technique','annule','rejete','perdu'
                ) AS statut,
                GROUP_CONCAT(a.libelle ORDER BY le.sous_numero SEPARATOR ', ') AS details,
                COUNT(le.idechantillon)        AS nb_total,
                SUM(le.idresultat IS NOT NULL) AS nb_resultats,
                TIMESTAMPDIFF(MINUTE, lg.date_creation, NOW()) AS delai_min,
                'groupe' AS type
            FROM labo_groupes_echantillons lg
            JOIN {$DB}.labo_echantillons le ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
            JOIN {$DB}.patient p ON p.idpatient = lg.idpatient
            LEFT JOIN {$DB}.actes_presc ap ON ap.idactes_presc = le.idactes_presc
            LEFT JOIN {$DB}.acte a ON a.idacte = ap.idacte
            GROUP BY lg.idgroupe
            HAVING statut NOT IN ('annule','rejete','perdu')
            ORDER BY MAX(le.urgence) DESC, lg.date_creation ASC
        ");
        foreach ($stmt->fetchAll() as $row) {
            $kanban[$row['statut']][] = $row;
        }
    } catch (Exception $e) {
        error_log("[Workflow] Kanban: " . $e->getMessage());
    }

    foreach ($kanban as &$col) {
        usort($col, function($a, $b) {
            if ($a['urgence'] != $b['urgence']) return $b['urgence'] - $a['urgence'];
            return strtotime($a['date_creation']) - strtotime($b['date_creation']);
        });
    }
    unset($col);
}
?>

<?php if ($mode && !empty($sous_eches)): ?>
<!-- VUE GROUPE -->

<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="index.php?page=labo&action=workflow" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Retour au kanban
    </a>
    <a href="index.php?page=labo&action=echantillons" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-list-ul me-1"></i>Liste
    </a>
</div>

<!-- En-tête patient -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex align-items-center justify-content-between"
         style="background:<?= $statut_labels[$statut_global]['bg'] ?? '#e9ecef' ?>;border:none;">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <strong style="font-size:1.05rem;">
                <i class="bi bi-collection me-2"></i>
                <?= htmlspecialchars($code_param) ?>
            </strong>
            <?php if (array_sum(array_column($sous_eches, 'urgence'))): ?>
                <span class="badge bg-danger">URGENT</span>
            <?php endif; ?>
            <span class="badge" style="background:#ede8f5;color:#5a3e9e;"><?= count($sous_eches) ?> examen(s)</span>
        </div>
        <span class="badge" style="background:<?= $statut_labels[$statut_global]['color'] ?? '#6c757d' ?>;color:#fff;">
            <?= htmlspecialchars($statut_labels[$statut_global]['label'] ?? $statut_global) ?>
        </span>
    </div>
    <div class="card-body py-2" style="font-size:.88rem;">
        <strong><?= htmlspecialchars(trim(($groupe['patient_nom'] ?? '') . ' ' . ($groupe['patient_prenom'] ?? ''))) ?></strong>
        <span class="text-muted ms-2">
            <?= !empty($groupe['patient_dob']) ? ' — ' . calculateAge($groupe['patient_dob']) . ' ans' : '' ?>
        </span>
        <span class="text-muted ms-3">
            <i class="bi bi-calendar3 me-1"></i>
            <?= formatDateTime($groupe['date_creation'] ?? '', 'd/m/Y H:i') ?>
        </span>
    </div>
</div>

<!-- Info workflow -->
<div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-4" style="font-size:.82rem;">
    <i class="bi bi-info-circle-fill" style="font-size:1rem;flex-shrink:0;"></i>
    <div>
        Chaque examen progresse <strong>indépendamment</strong> :
        <span class="badge" style="background:#e9ecef;color:#6c757d;">Att. prélèvement</span>
        <i class="bi bi-arrow-right mx-1"></i>
        <span class="badge" style="background:#cfe2ff;color:#0d6efd;">Prélevé</span>
        <i class="bi bi-arrow-right mx-1"></i>
        <span class="badge" style="background:#d1e7dd;color:#198754;">Résultats saisis &amp; validés</span>
    </div>
</div>

<?php
$nb_attente = count(array_filter($sous_eches, fn($s) => $s['statut'] === 'attente_prelevement'));
$nb_preleve = count(array_filter($sous_eches, fn($s) => in_array($s['statut'], ['preleve','validation_technique'])));
$nb_res_done= count(array_filter($sous_eches, fn($s) => !empty($s['idresultat'])));
$nb_total_se= count($sous_eches);
$lien_res_global = "index.php?page=labo&action=resultats&code=" . urlencode($code_param);
if ($nb_attente === 0 && $nb_preleve === $nb_total_se): ?>
<div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3" style="font-size:.83rem;">
    <i class="bi bi-check-circle-fill text-success"></i>
    <div>
        <strong>Tous les examens sont prélevés.</strong>
        <?php if ($nb_res_done < $nb_total_se): ?>
            <a href="<?= $lien_res_global ?>" class="ms-2 btn btn-sm btn-success" style="font-size:.75rem;">
                <i class="bi bi-clipboard2-plus me-1"></i>Saisir les résultats
            </a>
        <?php else: ?>
            <span class="ms-2 text-success"><i class="bi bi-check2-all me-1"></i>Tous les résultats sont saisis.</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- TABLEAU SOUS-EXAMENS -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <strong><i class="bi bi-list-check me-2"></i>Sous-examens — actions individuelles</strong>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:.83rem;">
            <thead class="table-light">
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Code</th>
                    <th>Examen</th>
                    <th>Prélèvement</th>
                    <th>Statut</th>
                    <th>Résultat</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sous_eches as $se):
                if (!$se) continue;
                $tc  = $tube_colors[$se['couleur_tube'] ?? ''] ?? null;
                $si  = $statut_labels[$se['statut'] ?? 'attente_prelevement']
                       ?? ['label' => '-', 'color' => '#6c757d', 'bg' => '#e9ecef'];

                $trans_se = [];
                foreach ($transitions[$se['statut'] ?? ''] ?? [] as $new_st => $info) {
                    if (in_array($user_profil_code, $info[3])) $trans_se[$new_st] = $info;
                }
                $has_result = !empty($se['idresultat']);
                $res_apercu = $has_result ? ($resultats_map[$se['idresultat']] ?? null) : null;

                // Paiement : Acte_presc.Statut >= 2 dans csk_base (2=payé, 3=achevé)
                $paiement_valide_wf = ((int)($se['ap_statut'] ?? 1)) >= 2;

                $lien_res_se = "index.php?page=labo&action=resultats&code=" . urlencode($code_param);
            ?>
            <tr class="<?= $se['urgence'] ? 'table-danger' : '' ?>">
                <td class="text-center fw-bold"><?= $se['sous_numero'] ?? 1 ?></td>
                <td>
                    <?php if ($tc): ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $tc ?>;margin-right:3px;"></span><?php endif; ?>
                    <code style="font-size:.75rem;"><?= htmlspecialchars($se['code_echantillon']) ?></code>
                </td>
                <td>
                    <div class="fw-semibold"><?= htmlspecialchars($se['examen_libelle'] ?? '-') ?></div>
                    <?php if (!empty($se['type_prelevement'])): ?>
                        <div class="text-muted" style="font-size:.72rem;">
                            <?= htmlspecialchars(str_replace('_', ' ', ucfirst($se['type_prelevement']))) ?>
                            <?= !empty($se['tube_type']) ? ' / ' . $se['tube_type'] : '' ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.78rem;white-space:nowrap;">
                    <?= !empty($se['date_prelevement']) ? formatDateTime($se['date_prelevement'], 'd/m H:i') : '—' ?>
                </td>
                <td>
                    <span class="badge" style="background:<?= $si['bg'] ?>;color:<?= $si['color'] ?>;font-size:.72rem;">
                        <?= htmlspecialchars($si['label']) ?>
                    </span>
                    <?php if (!$has_result && in_array($se['statut'], ['preleve','validation_technique','attente_prelevement'])): ?>
                    <br>
                    <?php if ($paiement_valide_wf): ?>
                        <span class="badge mt-1" style="background:#d1e7dd;color:#0a3622;font-size:.65rem;">
                            <i class="bi bi-check-circle me-1"></i>Payé
                        </span>
                    <?php else: ?>
                        <span class="badge mt-1" style="background:#fff3cd;color:#664d03;font-size:.65rem;">
                            <i class="bi bi-clock me-1"></i>Paiement att.
                        </span>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($has_result):
                        $interp = $res_apercu['interpretation'] ?? '';
                        $ic     = match($interp) {
                            'normal'   => 'bg-success',
                            'anormal'  => 'bg-warning text-dark',
                            'critique' => 'bg-danger',
                            default    => 'bg-secondary'
                        };
                    ?>
                        <div class="d-flex align-items-center gap-1">
                            <span class="badge <?= $ic ?>" style="font-size:.68rem;"><?= $interp ? ucfirst($interp) : 'Saisi' ?></span>
                            <?php if ($res_apercu): ?>
                            <button type="button" class="btn p-0"
                                    style="font-size:.7rem;border:none;background:none;color:#6c757d;"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modal-res-<?= $se['idechantillon'] ?>">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php elseif (in_array($se['statut'], ['preleve','validation_technique'])): ?>
                        <span class="badge bg-warning text-dark" style="font-size:.7rem;">À saisir</span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex flex-wrap gap-1 align-items-start">
                        <?php foreach ($trans_se as $new_st => $info):
                            if ($new_st === 'validation_technique') continue;
                            $is_danger = in_array($new_st, ['rejete','perdu','annule']);
                        ?>
                            <?php if ($is_danger): ?>
                            <button type="button" class="btn btn-sm <?= $info[2] ?>"
                                    style="font-size:.7rem;padding:2px 6px;"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#confirm-<?= $se['idechantillon'] ?>-<?= $new_st ?>">
                                <i class="bi <?= $info[1] ?>"></i>
                            </button>
                            <?php else: ?>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('<?= addslashes($info[0]) ?> ?');">
                                <input type="hidden" name="action_workflow"  value="1">
                                <input type="hidden" name="Code" value="<?= htmlspecialchars($se['code_echantillon']) ?>">
                                <input type="hidden" name="nouveau_statut"   value="<?= htmlspecialchars($new_st) ?>">
                                <input type="hidden" name="action_label"     value="<?= htmlspecialchars($info[0]) ?>">
                                <input type="hidden" name="redirect_to"      value="<?= htmlspecialchars($code_param) ?>">
                                <button type="submit" class="btn btn-sm <?= $info[2] ?>"
                                        style="font-size:.72rem;padding:2px 8px;">
                                    <i class="bi <?= $info[1] ?>"></i>
                                    <span class="ms-1"><?= htmlspecialchars($info[0]) ?></span>
                                </button>
                            </form>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if ($has_result): ?>
                            <a href="<?= $lien_res_se ?>"
                               class="btn btn-sm btn-success"
                               style="font-size:.72rem;padding:2px 8px;">
                                <i class="bi bi-clipboard2-check"></i>
                                <span class="ms-1">Voir/Modifier</span>
                            </a>
                        <?php elseif (in_array($se['statut'], ['preleve','validation_technique'])): ?>
                            <?php if ($paiement_valide_wf): ?>
                                <a href="<?= $lien_res_se ?>"
                                   class="btn btn-sm btn-outline-success"
                                   style="font-size:.72rem;padding:2px 8px;">
                                    <i class="bi bi-clipboard2-plus"></i>
                                    <span class="ms-1">Saisir résultat</span>
                                </a>
                            <?php else: ?>
                                <span class="badge"
                                      style="background:#f8d7da;color:#842029;font-size:.7rem;cursor:help;padding:4px 7px;"
                                      title="Paiement requis — la réception doit valider (Statut ≥ 2)">
                                    <i class="bi bi-lock-fill me-1"></i>Saisie bloquée
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($trans_se as $new_st => $info):
                        if (!in_array($new_st, ['rejete','perdu','annule'])) continue; ?>
                    <div class="collapse mt-2" id="confirm-<?= $se['idechantillon'] ?>-<?= $new_st ?>">
                        <form method="POST" class="border rounded p-2 bg-light" style="font-size:.8rem;">
                            <input type="hidden" name="action_workflow"  value="1">
                            <input type="hidden" name="Code" value="<?= htmlspecialchars($se['code_echantillon']) ?>">
                            <input type="hidden" name="nouveau_statut"   value="<?= htmlspecialchars($new_st) ?>">
                            <input type="hidden" name="action_label"     value="<?= htmlspecialchars($info[0]) ?>">
                            <input type="hidden" name="redirect_to"      value="<?= htmlspecialchars($code_param) ?>">
                            <?php if ($new_st !== 'annule'): ?>
                            <div class="mb-1">
                                <select name="qualite_echantillon" class="form-select form-select-sm">
                                    <option value="">-- Qualité --</option>
                                    <option value="hemolyse">Hémolysée</option>
                                    <option value="coagule">Coagulée</option>
                                    <option value="insuffisant">Insuffisante</option>
                                    <option value="contamine">Contaminée</option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="mb-1">
                                <input type="text" name="commentaire_qualite" class="form-control form-control-sm" placeholder="Motif...">
                            </div>
                            <button type="submit" class="btn btn-sm <?= $info[2] ?> w-100">
                                <i class="bi <?= $info[1] ?> me-1"></i>Confirmer : <?= htmlspecialchars($info[0]) ?>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modals aperçu résultats -->
<?php foreach ($sous_eches as $se):
    if (!$se || empty($se['idresultat'])) continue;
    $res_m = $resultats_map[$se['idresultat']] ?? null;
    if (!$res_m) continue;
    $interp_m = $res_m['interpretation'] ?? '';
    $ic_m = match($interp_m) {'normal' => '#198754', 'anormal' => '#fd7e14', 'critique' => '#dc3545', default => '#6c757d'};
?>
<div class="modal fade" id="modal-res-<?= $se['idechantillon'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2"
                 style="background:<?= $statut_labels[$se['statut']]['bg'] ?? '#e9ecef' ?>;border-bottom:none;">
                <h6 class="modal-title mb-0">
                    <i class="bi bi-clipboard2-check me-2"></i>
                    <?= htmlspecialchars($se['examen_libelle'] ?? $se['code_echantillon']) ?>
                    <code class="ms-2" style="font-size:.75rem;"><?= htmlspecialchars($se['code_echantillon']) ?></code>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:.88rem;">
                <div class="row g-3">
                    <div class="col-md-7">
                        <strong class="text-muted d-block mb-1">Résultat</strong>
                        <div class="p-3 rounded" style="background:#f8f9fa;white-space:pre-wrap;font-family:monospace;font-size:.85rem;"><?= htmlspecialchars($res_m['resultat']) ?></div>
                    </div>
                    <?php if (!empty($res_m['valeur_normale'])): ?>
                    <div class="col-md-5">
                        <strong class="text-muted d-block mb-1">Valeurs de référence</strong>
                        <div class="p-3 rounded" style="background:#f8f9fa;white-space:pre-wrap;font-size:.82rem;"><?= htmlspecialchars($res_m['valeur_normale']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Interprétation</strong>
                        <span style="color:<?= $ic_m ?>;font-weight:700;"><?= ucfirst($interp_m ?: '—') ?></span>
                    </div>
                    <div class="col-md-8">
                        <strong class="text-muted d-block mb-1">Analysé par</strong>
                        <?= htmlspecialchars($res_m['analyste_nom'] ?? '—') ?>
                        <div class="text-muted" style="font-size:.75rem;"><?= formatDateTime($res_m['date_analyse'] ?? '', 'd/m/Y H:i') ?></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <a href="index.php?page=labo&action=resultats&code=<?= urlencode($code_param) ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-pencil-square me-1"></i>Modifier le résultat
                </a>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Résumé + Historique -->
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <strong><i class="bi bi-pie-chart me-2"></i>Résumé</strong>
            </div>
            <div class="card-body" style="font-size:.85rem;">
                <?php
                $nb_prel_r = count(array_filter($sous_eches, fn($s) => in_array($s['statut'], ['preleve','validation_technique'])));
                $nb_res_r  = count(array_filter($sous_eches, fn($s) => !empty($s['idresultat'])));
                $nb_tot_r  = count($sous_eches);
                ?>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Prélevés</span>
                    <strong style="color:#0d6efd;"><?= $nb_prel_r ?>/<?= $nb_tot_r ?></strong>
                </div>
                <div class="progress mb-3" style="height:5px;">
                    <div class="progress-bar bg-primary" style="width:<?= $nb_tot_r > 0 ? round($nb_prel_r / $nb_tot_r * 100) : 0 ?>%;"></div>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted">Résultats saisis</span>
                    <strong style="color:<?= ($nb_res_r === $nb_tot_r && $nb_tot_r > 0) ? '#198754' : '#fd7e14' ?>;">
                        <?= $nb_res_r ?>/<?= $nb_tot_r ?>
                    </strong>
                </div>
                <div class="progress mb-3" style="height:5px;">
                    <div class="progress-bar bg-success" style="width:<?= $nb_tot_r > 0 ? round($nb_res_r / $nb_tot_r * 100) : 0 ?>%;"></div>
                </div>
                <hr class="my-2">
                <?php foreach ($sous_eches as $se): if (!$se) continue; ?>
                <div class="d-flex align-items-center justify-content-between py-1 border-bottom" style="font-size:.78rem;">
                    <span class="text-truncate me-2" style="max-width:160px;"
                          title="<?= htmlspecialchars($se['examen_libelle'] ?? '') ?>">
                        <?= htmlspecialchars($se['examen_libelle'] ?? $se['code_echantillon']) ?>
                    </span>
                    <div class="d-flex align-items-center gap-1">
                        <?= getLaboStatutBadge($se['statut']) ?>
                        <?php if (!empty($se['idresultat'])): ?>
                            <span class="badge bg-success" style="font-size:.65rem;">R</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($historique)): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <strong><i class="bi bi-clock-history me-2"></i>Historique des actions</strong>
            </div>
            <div class="card-body p-3" style="max-height:380px;overflow-y:auto;">
                <?php foreach ($historique as $i => $h):
                    $last = ($i === count($historique) - 1); ?>
                <div class="d-flex gap-3 <?= !$last ? 'mb-3 pb-3 border-bottom' : '' ?>">
                    <div style="flex-shrink:0;width:26px;height:26px;border-radius:50%;
                                background:<?= $last ? '#6f42c1' : '#e9ecef' ?>;
                                display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-arrow-right" style="color:<?= $last ? '#fff' : '#6c757d' ?>;font-size:.65rem;"></i>
                    </div>
                    <div style="font-size:.8rem;">
                        <div class="fw-semibold"><?= htmlspecialchars($h['action']) ?></div>
                        <?php if (!empty($h['code_echantillon'])): ?>
                            <div class="text-muted" style="font-size:.7rem;">
                                <code><?= htmlspecialchars($h['code_echantillon']) ?></code>
                            </div>
                        <?php endif; ?>
                        <div>
                            <?php if ($h['ancien_statut']): ?>
                                <?= getLaboStatutBadge($h['ancien_statut']) ?>
                                <i class="bi bi-arrow-right mx-1"></i>
                            <?php endif; ?>
                            <?= getLaboStatutBadge($h['nouveau_statut']) ?>
                        </div>
                        <?php if ($h['observation']): ?>
                            <div class="text-muted" style="font-size:.73rem;"><?= htmlspecialchars($h['observation']) ?></div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:.7rem;">
                            <?= formatDateTime($h['created_at'], 'd/m/Y H:i') ?>
                            <?php if (!empty($h['user_nom'])): ?>
                            — <?= htmlspecialchars($h['user_nom']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($code_param): ?>
<div class="alert alert-warning">
    Élément <strong><?= htmlspecialchars($code_param) ?></strong> introuvable.
    <a href="index.php?page=labo&action=workflow">Retour au kanban</a>
</div>

<?php else: ?>
<!-- ═══════════════════════ KANBAN ═══════════════════════ -->

<?php
$total_kanban  = array_sum(array_map('count', $kanban));
$total_urgents = array_sum(array_map(fn($col) => count(array_filter($col, fn($i) => $i['urgence'])), $kanban));
$total_res     = array_sum(array_map(fn($col) => array_sum(array_column($col, 'nb_resultats')), $kanban));
$total_exa     = array_sum(array_map(fn($col) => array_sum(array_column($col, 'nb_total')), $kanban));

$cols_config = [
    'attente_prelevement' => [
        'label' => 'Attente prélèvement', 'icon' => 'bi-hourglass-split',
        'accent' => '#6c757d', 'bg_col' => '#f8f9fa',
        'bg_head' => 'linear-gradient(135deg,#e9ecef,#dee2e6)', 'card_border' => '#dee2e6',
    ],
    'preleve' => [
        'label' => 'Prélevé — En cours', 'icon' => 'bi-droplet-half',
        'accent' => '#0d6efd', 'bg_col' => '#f0f4ff',
        'bg_head' => 'linear-gradient(135deg,#cfe2ff,#b6d4fe)', 'card_border' => '#b6d4fe',
    ],
    'validation_technique' => [
        'label' => 'Résultats saisis', 'icon' => 'bi-clipboard2-check',
        'accent' => '#198754', 'bg_col' => '#f0fff4',
        'bg_head' => 'linear-gradient(135deg,#d1e7dd,#a3cfbb)', 'card_border' => '#a3cfbb',
    ],
];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:.88rem;">
        <span><strong><?= $total_kanban ?></strong> groupe(s) actif(s)
        <?php if ($total_exa > 0): ?> — <strong><?= $total_exa ?></strong> examen(s)<?php endif; ?>
        </span>
        <?php if ($total_urgents > 0): ?>
        <span class="text-danger fw-semibold">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= $total_urgents ?> urgent(s)
        </span>
        <?php endif; ?>
        <?php if ($total_exa > 0): ?>
        <div class="d-flex align-items-center gap-2">
            <div class="progress" style="width:100px;height:7px;border-radius:4px;">
                <div class="progress-bar bg-success"
                     style="width:<?= round($total_res / $total_exa * 100) ?>%;"></div>
            </div>
            <span class="text-muted" style="font-size:.78rem;"><?= $total_res ?>/<?= $total_exa ?> résultats</span>
        </div>
        <?php endif; ?>
    </div>
    <a href="index.php?page=labo&action=echantillons" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-list-ul me-1"></i>Vue liste
    </a>
</div>

<div style="display:flex;gap:14px;overflow-x:auto;padding-bottom:1.5rem;align-items:flex-start;">
<?php foreach ($cols_config as $st => $cfg):
    $items    = $kanban[$st] ?? [];
    $nb_items = count($items);
    $nb_urg   = count(array_filter($items, fn($i) => $i['urgence']));
?>
<div style="min-width:260px;max-width:300px;flex:1;flex-shrink:0;">
    <div class="rounded-top px-3 py-2"
         style="background:<?= $cfg['bg_head'] ?>;border-bottom:3px solid <?= $cfg['accent'] ?>;">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <i class="bi <?= $cfg['icon'] ?>" style="color:<?= $cfg['accent'] ?>;font-size:1rem;"></i>
                <strong style="font-size:.82rem;color:<?= $cfg['accent'] ?>;"><?= $cfg['label'] ?></strong>
            </div>
            <div class="d-flex align-items-center gap-1">
                <?php if ($nb_urg > 0): ?>
                <span class="badge bg-danger" style="font-size:.65rem;">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i><?= $nb_urg ?>
                </span>
                <?php endif; ?>
                <span class="rounded-pill px-2"
                      style="background:<?= $cfg['accent'] ?>;color:#fff;font-size:.72rem;font-weight:700;">
                    <?= $nb_items ?>
                </span>
            </div>
        </div>
    </div>

    <div class="rounded-bottom"
         style="background:<?= $cfg['bg_col'] ?>;padding:8px;min-height:100px;max-height:65vh;overflow-y:auto;
                border:1px solid <?= $cfg['card_border'] ?>;border-top:none;">
        <?php if (empty($items)): ?>
            <div class="text-center py-4" style="color:#adb5bd;font-size:.78rem;">
                <i class="bi <?= $cfg['icon'] ?>" style="font-size:1.5rem;display:block;margin-bottom:4px;opacity:.4;"></i>
                Aucun élément
            </div>
        <?php endif; ?>

        <?php foreach ($items as $item):
            $nb_r = (int)($item['nb_resultats'] ?? 0);
            $nb_t = max(1, (int)($item['nb_total'] ?? 1));
            $pct  = round($nb_r / $nb_t * 100);
            $complet = ($nb_r === $nb_t && $nb_t > 0);
            $age_min = (int)($item['delai_min'] ?? 0);
            $retard  = $age_min > 120 && !$complet;
        ?>
        <a href="index.php?page=labo&action=workflow&code=<?= urlencode($item['code']) ?>"
           class="d-block text-decoration-none mb-2 rounded overflow-hidden"
           style="border:1.5px solid <?= $item['urgence'] ? '#dc3545' : ($complet ? '#a3cfbb' : $cfg['card_border']) ?>;
                  border-left:3px solid #6f42c1;background:#fff;
                  box-shadow:0 1px 4px rgba(0,0,0,.07);transition:box-shadow .15s,transform .15s;"
           onmouseover="this.style.boxShadow='0 3px 12px rgba(0,0,0,.13)';this.style.transform='translateY(-1px)';"
           onmouseout="this.style.boxShadow='0 1px 4px rgba(0,0,0,.07)';this.style.transform='translateY(0)';">

            <div style="height:3px;background:<?= $item['urgence'] ? '#dc3545' : '#6f42c1' ?>;"></div>

            <div class="px-2 py-1 d-flex justify-content-between align-items-center"
                 style="background:#f3e8ff;border-bottom:1px solid #6f42c120;">
                <span style="font-size:.62rem;color:#6f42c1;font-weight:700;">
                    <i class="bi bi-collection me-1"></i>GROUPE
                </span>
                <?php if ($item['urgence']): ?>
                <span class="badge bg-danger" style="font-size:.58rem;padding:2px 5px;">URGENT</span>
                <?php endif; ?>
            </div>

            <div class="p-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <code style="font-size:.7rem;color:#6f42c1;font-weight:700;">
                        <?= htmlspecialchars($item['code']) ?>
                    </code>
                    <?php if ($retard): ?>
                        <span class="badge bg-warning text-dark" style="font-size:.58rem;" title="<?= $age_min ?>min">
                            <i class="bi bi-clock-fill"></i> <?= $age_min >= 60 ? round($age_min / 60) . 'h' : $age_min . 'm' ?>
                        </span>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:.65rem;"><?= formatDateTime($item['date_creation'], 'H:i') ?></span>
                    <?php endif; ?>
                </div>

                <div class="fw-semibold" style="font-size:.82rem;color:#212529;line-height:1.25;">
                    <?= htmlspecialchars(trim($item['patient_nom'] . ' ' . $item['patient_prenom'])) ?>
                </div>

                <?php if (!empty($item['details'])): ?>
                <div style="font-size:.7rem;color:#6c757d;margin-top:2px;line-height:1.3;"
                     title="<?= htmlspecialchars($item['details']) ?>">
                    <i class="bi bi-list-ul me-1" style="font-size:.65rem;"></i>
                    <?= htmlspecialchars(mb_strimwidth($item['details'], 0, 45, '…')) ?>
                </div>
                <?php endif; ?>

                <div class="mt-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="font-size:.63rem;color:#6c757d;">Résultats</span>
                        <span style="font-size:.63rem;font-weight:600;color:<?= $complet ? '#198754' : ($nb_r > 0 ? '#fd7e14' : '#adb5bd') ?>;">
                            <?= $nb_r ?>/<?= $nb_t ?>
                        </span>
                    </div>
                    <div class="progress" style="height:4px;border-radius:2px;">
                        <div class="progress-bar"
                             style="width:<?= $pct ?>%;background:<?= $complet ? '#198754' : ($nb_r > 0 ? '#fd7e14' : '#dee2e6') ?>;"></div>
                    </div>
                </div>

                <div class="mt-2 d-flex align-items-center justify-content-between">
                    <?php if ($complet): ?>
                    <span style="font-size:.7rem;color:#198754;"><i class="bi bi-check-circle-fill me-1"></i>Complet</span>
                    <?php else: ?><span></span><?php endif; ?>

                    <?php if (in_array($st, ['preleve','validation_technique'])): ?>
                    <a href="index.php?page=labo&action=resultats&code=<?= urlencode($item['code']) ?>"
                       class="btn py-0 px-1 <?= $nb_r > 0 ? 'btn-outline-success' : 'btn-outline-warning' ?>"
                       style="font-size:.6rem;"
                       onclick="event.stopPropagation();"
                       title="<?= $nb_r > 0 ? 'Voir résultats' : 'Saisir résultats' ?>">
                        <i class="bi bi-clipboard2-<?= $nb_r > 0 ? 'check' : 'plus' ?>"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>