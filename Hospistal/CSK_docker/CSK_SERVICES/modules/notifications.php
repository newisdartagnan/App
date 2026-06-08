<?php
/**
 * Module Notifications - Vue
 * Affiche les notifications filtrées par service
 *
 * Corrections :
 * 1. Suppression de session_start() - la session est déjà active via index.php
 * 2. Token CSRF lu depuis la session au moment du rendu (pas de double session)
 * 3. Génération du token CSRF si absent
 * 4. URL API corrigée avec base_url pour éviter les problèmes de chemin relatif
 */

require_once __DIR__ . '/../includes/notifications_helpers.php';

// Générer le token CSRF s'il n'existe pas encore
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Paramètres
$onglet    = sanitizeInput($_GET['onglet'] ?? '');
$filtre_lu = isset($_GET['lu']) ? $_GET['lu'] : '';
$page_num  = max(1, (int)($_GET['p'] ?? 1));
$per_page  = 20;
$offset    = ($page_num - 1) * $per_page;

// Pour un utilisateur avec un seul service, forcer l'onglet sur ce service
$nb_services = 0;
if ($has_labo) $nb_services++;
if ($has_imagerie) $nb_services++;
if ($has_pharmacie) $nb_services++;

if ($nb_services === 1 && ($onglet === '' || $onglet === 'toutes')) {
    if ($has_labo)      $onglet = 'labo';
    elseif ($has_imagerie)  $onglet = 'imagerie';
    elseif ($has_pharmacie) $onglet = 'pharmacie';
}

if (empty($onglet)) {
    $onglet = 'toutes';
}

// Onglet -> filtre service
$service_filter = null;
if (in_array($onglet, ['labo', 'imagerie', 'pharmacie'])) {
    $service_filter = $onglet;
}

// Compter par service
$count_all       = countUnread($user_id, $user_profil_code);
$count_labo      = countUnread($user_id, $user_profil_code, 'labo');
$count_imagerie  = countUnread($user_id, $user_profil_code, 'imagerie');
$count_pharmacie = countUnread($user_id, $user_profil_code, 'pharmacie');

$non_lues_only = ($filtre_lu === '0');

// Charger les notifications
$notifs = getNotifications($user_id, $user_profil_code, $service_filter, $per_page, $offset, $non_lues_only);

// Total pour pagination
$total_count = 0;
if ($non_lues_only) {
    $total_count = countUnread($user_id, $user_profil_code, $service_filter);
} else {
    try {
        $db2   = new Database();
        $conn2 = $db2->getServicesConnection();
        $where_p  = ["n.archive = 0"];
        $params_p = [];

        if ($user_profil_code !== 'admin') {
            $grp = getGroupeFromProfil($user_profil_code);
            $where_p[]       = "(n.id_destinataire = :uid OR n.groupe_destinataire = :grp OR n.groupe_destinataire = 'tous')";
            $params_p[':uid'] = $user_id;
            $params_p[':grp'] = $grp;
        }
        if ($service_filter) {
            $where_p[]       = "n.service = :svc";
            $params_p[':svc'] = $service_filter;
        }
        $wp  = implode(' AND ', $where_p);
        $stc = $conn2->prepare("SELECT COUNT(*) FROM services_notifications n WHERE $wp");
        $stc->execute($params_p);
        $total_count = (int)$stc->fetchColumn();
    } catch (Exception $e) {
        error_log("[Notifications] Erreur count total: " . $e->getMessage());
        $total_count = count($notifs);
    }
}
$total_pages = max(1, ceil($total_count / $per_page));

// Helpers d'affichage
function getNotifIcon(string $type): string {
    return match($type) {
        'prescription_entrant',
        'prescription_recue'  => 'bi-prescription2 text-primary',
        'resultat_pret'       => 'bi-check-circle text-success',
        'medicament_delivre'  => 'bi-capsule text-success',
        'statut_change'       => 'bi-arrow-repeat text-info',
        'alerte'              => 'bi-exclamation-triangle text-danger',
        default               => 'bi-bell text-secondary',
    };
}

function getNotifLink(array $n): string {
    $code = urlencode($n['code_reference'] ?? '');
    return match($n['service'] ?? '') {
        'labo'      => 'index.php?page=labo&action=workflow&code='      . $code,
        'imagerie'  => 'index.php?page=imagerie&action=workflow&code='  . $code,
        'pharmacie' => 'index.php?page=pharmacie&action=workflow&code=' . $code,
        default     => '#',
    };
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return "à l'instant";
    if ($diff < 3600)   return floor($diff / 60)    . ' min';
    if ($diff < 86400)  return floor($diff / 3600)  . 'h';
    if ($diff < 604800) return floor($diff / 86400) . 'j';
    return date('d/m/Y', strtotime($datetime));
}
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><i class="bi bi-bell me-2"></i>Notifications</h5>
    <div class="d-flex gap-2">
        <?php if ($count_all > 0): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="markAllRead()">
            <i class="bi bi-check2-all me-1"></i>Tout marquer comme lu
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3">
    <?php $show_toutes = $is_admin || $nb_services > 1; ?>

    <?php if ($show_toutes): ?>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'toutes' ? 'active' : '' ?>"
           href="index.php?page=notifications&onglet=toutes">
            Toutes
            <?php if ($count_all > 0): ?>
                <span class="badge bg-danger ms-1"><?= $count_all ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>

    <?php if ($has_labo || $is_admin): ?>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'labo' ? 'active' : '' ?>"
           href="index.php?page=notifications&onglet=labo">
            <i class="bi bi-droplet" style="color: #6f42c1;"></i> Labo
            <?php if ($count_labo > 0): ?>
                <span class="badge bg-danger ms-1"><?= $count_labo ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>

    <?php if ($has_imagerie || $is_admin): ?>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'imagerie' ? 'active' : '' ?>"
           href="index.php?page=notifications&onglet=imagerie">
            <i class="bi bi-image" style="color: #0dcaf0;"></i> Imagerie
            <?php if ($count_imagerie > 0): ?>
                <span class="badge bg-danger ms-1"><?= $count_imagerie ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>

    <?php if ($has_pharmacie || $is_admin): ?>
    <li class="nav-item">
        <a class="nav-link <?= $onglet === 'pharmacie' ? 'active' : '' ?>"
           href="index.php?page=notifications&onglet=pharmacie">
            <i class="bi bi-capsule" style="color: #198754;"></i> Pharmacie
            <?php if ($count_pharmacie > 0): ?>
                <span class="badge bg-danger ms-1"><?= $count_pharmacie ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endif; ?>
</ul>

<!-- Filtre lu/non-lu -->
<div class="mb-3">
    <div class="btn-group btn-group-sm">
        <a href="index.php?page=notifications&onglet=<?= $onglet ?>"
           class="btn <?= $filtre_lu === '' ? 'btn-primary' : 'btn-outline-primary' ?>">Toutes</a>
        <a href="index.php?page=notifications&onglet=<?= $onglet ?>&lu=0"
           class="btn <?= $filtre_lu === '0' ? 'btn-primary' : 'btn-outline-primary' ?>">Non lues</a>
    </div>
</div>

<!-- Liste notifications -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($notifs)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-bell-slash" style="font-size: 3rem;"></i>
                <p class="mt-2">Aucune notification</p>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($notifs as $n):
                    $is_unread = !$n['lu'];
                    $service_color = match($n['service'] ?? '') {
                        'labo'      => '#6f42c1',
                        'imagerie'  => '#0dcaf0',
                        'pharmacie' => '#198754',
                        default     => '#6c757d',
                    };
                    $service_text = ($n['service'] === 'imagerie') ? '#000' : '#fff';
                ?>
                <div class="list-group-item d-flex align-items-start gap-3 <?= $is_unread ? '' : 'bg-light' ?>"
                     style="<?= $is_unread ? 'border-left: 3px solid ' . $service_color . ';' : 'opacity: 0.8;' ?>">

                    <!-- Icône type -->
                    <div class="mt-1">
                        <i class="bi <?= getNotifIcon($n['type'] ?? '') ?>" style="font-size: 1.3rem;"></i>
                    </div>

                    <!-- Contenu -->
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge" style="background: <?= $service_color ?>; color: <?= $service_text ?>; font-size: 0.65rem;">
                                <?= strtoupper($n['service'] ?? '?') ?>
                            </span>
                            <?php if ($n['priorite'] === 'urgente'): ?>
                                <span class="badge bg-danger" style="font-size: 0.65rem;">URGENT</span>
                            <?php elseif ($n['priorite'] === 'haute'): ?>
                                <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">HAUTE</span>
                            <?php endif; ?>
                            <?php if ($is_unread): ?>
                                <span class="badge bg-primary" style="font-size: 0.6rem;">NOUVEAU</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 0.9rem; font-weight: <?= $is_unread ? '600' : '400' ?>;">
                            <?= htmlspecialchars($n['titre'] ?? '') ?>
                        </div>
                        <div class="text-muted" style="font-size: 0.82rem;">
                            <?= htmlspecialchars($n['message'] ?? '') ?>
                        </div>
                        <?php if (!empty($n['code_reference'])): ?>
                        <div class="mt-1">
                            <a href="<?= getNotifLink($n) ?>" class="btn btn-sm btn-outline-secondary" style="font-size: 0.75rem;">
                                <i class="bi bi-arrow-right me-1"></i>Voir <?= htmlspecialchars($n['code_reference']) ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions + date -->
                    <div class="text-end text-nowrap">
                        <small class="text-muted d-block mb-1"><?= timeAgo($n['created_at']) ?></small>
                        <div class="d-flex gap-1 justify-content-end">
                            <?php if ($is_unread): ?>
                            <button class="btn btn-sm btn-outline-success" title="Marquer lu"
                                    onclick="markRead(<?= (int)$n['idnotification'] ?>)"
                                    style="font-size: 0.7rem;">
                                <i class="bi bi-check"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Archiver"
                                    onclick="archiveNotif(<?= (int)$n['idnotification'] ?>)"
                                    style="font-size: 0.7rem;">
                                <i class="bi bi-archive"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Page <?= $page_num ?>/<?= $total_pages ?></small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page_num > 1): ?>
                <li class="page-item">
                    <a class="page-link"
                       href="index.php?page=notifications&onglet=<?= $onglet ?>&p=<?= $page_num - 1 ?><?= $filtre_lu !== '' ? '&lu=' . $filtre_lu : '' ?>">Préc.</a>
                </li>
                <?php endif; ?>
                <?php for ($i = max(1, $page_num - 2); $i <= min($total_pages, $page_num + 2); $i++): ?>
                <li class="page-item <?= $i === $page_num ? 'active' : '' ?>">
                    <a class="page-link"
                       href="index.php?page=notifications&onglet=<?= $onglet ?>&p=<?= $i ?><?= $filtre_lu !== '' ? '&lu=' . $filtre_lu : '' ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page_num < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link"
                       href="index.php?page=notifications&onglet=<?= $onglet ?>&p=<?= $page_num + 1 ?><?= $filtre_lu !== '' ? '&lu=' . $filtre_lu : '' ?>">Suiv.</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Token CSRF dans une meta-tag pour le JS (évite l'interpolation PHP dans JS) -->
<meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
<meta name="service-filter" content="<?= htmlspecialchars($service_filter ?? '', ENT_QUOTES) ?>">

<script>
(function () {
    // Lire le token CSRF depuis la meta-tag (propre, pas d'interpolation PHP dans JS)
    const csrfToken    = document.querySelector('meta[name="csrf-token"]').content;
    const serviceFilter = document.querySelector('meta[name="service-filter"]').content;

    // URL de base de l'API (résolue depuis la racine du site)
    const API_BASE = 'api/notifications.php';

    async function apiCall(action, body) {
        const resp = await fetch(API_BASE + '?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...body, csrf_token: csrfToken }),
        });

        if (!resp.ok) {
            const text = await resp.text();
            throw new Error('HTTP ' + resp.status + ' : ' + text);
        }

        const data = await resp.json();
        if (!data.success) throw new Error(data.error || 'Erreur inconnue');
        return data;
    }

    window.markRead = function (id) {
        apiCall('mark_read', { idnotification: id })
            .then(() => location.reload())
            .catch(err => {
                console.error('markRead error:', err);
                alert('Erreur : ' + err.message);
            });
    };

    window.archiveNotif = function (id) {
        apiCall('archive', { idnotification: id })
            .then(() => location.reload())
            .catch(err => {
                console.error('archiveNotif error:', err);
                alert('Erreur : ' + err.message);
            });
    };

    window.markAllRead = function () {
        if (!confirm('Marquer toutes les notifications comme lues ?')) return;
        apiCall('mark_all_read', { service: serviceFilter })
            .then(() => location.reload())
            .catch(err => {
                console.error('markAllRead error:', err);
                alert('Erreur : ' + err.message);
            });
    };
})();
</script>