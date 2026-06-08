<?php
/**
 * API Notifications - VERSION FINALE
 *
 * Correction principale : clé session 'profil_code' (pas 'user_profil_code')
 * et 'groupe_notification' pour le groupe du destinataire.
 */

session_start();

header('Content-Type: application/json');

function logError($message, $data = null) {
    $log = date('Y-m-d H:i:s') . " [Notif API] " . $message;
    if ($data !== null) $log .= " | " . json_encode($data, JSON_UNESCAPED_UNICODE);
    error_log($log);
}

function exception_error_handler($severity, $message, $file, $line) {
    logError("PHP Error: $message in $file:$line");
    throw new ErrorException($message, 0, $severity, $file, $line);
}
set_error_handler("exception_error_handler");

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/notifications_helpers.php';

    $action = $_GET['action'] ?? '';

    // ===============================
    // ACTION : COUNT (pour polling)
    // ===============================
    if ($action === 'count') {

        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['success' => false]);
            exit();
        }

        $db = new Database();
        $conn = $db->getServicesConnection();

        $user_id  = (int)$_SESSION['user_id'];
        $groupe   = $_SESSION['groupe_notification'] ?? '';
        $profil   = $_SESSION['profil_code'] ?? '';
        $is_admin = ($profil === 'admin');

        if ($is_admin) {

            $sql = "SELECT COUNT(*)
                    FROM services_notifications
                    WHERE lu = 0 AND archive = 0";

            $stmt = $conn->query($sql);

        } else {

            $sql = "SELECT COUNT(*)
                    FROM services_notifications
                    WHERE lu = 0
                      AND archive = 0
                      AND (
                          id_destinataire = ?
                          OR groupe_destinataire = ?
                          OR groupe_destinataire = 'tous'
                      )";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $groupe]);
        }

        echo json_encode([
            'success' => true,
            'count'   => (int)$stmt->fetchColumn()
        ]);

        exit();
    }

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Non authentifié.']);
        exit();
    }

    // ── Clés session correctes ──────────────────────────────────────────
    $user_id            = (int)$_SESSION['user_id'];
    $user_profil_code   = $_SESSION['profil_code']         ?? '';   // ← 'profil_code', pas 'user_profil_code'
    $groupe_notification = $_SESSION['groupe_notification'] ?? '';   // ← groupe déjà calculé à la connexion
    $is_admin           = ($user_profil_code === 'admin');
    // ───────────────────────────────────────────────────────────────────

    logError("User $user_id | profil=$user_profil_code | groupe=$groupe_notification | admin=" . ($is_admin ? 'OUI' : 'non'));

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) $input = $_POST;

    $csrf_token = $input['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        logError("CSRF invalide");
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide.']);
        exit();
    }

    switch ($action) {
        case 'mark_read':     handleMarkRead($user_id, $groupe_notification, $is_admin, $input);    break;
        case 'archive':       handleArchive($user_id, $groupe_notification, $is_admin, $input);     break;
        case 'mark_all_read': handleMarkAllRead($user_id, $groupe_notification, $is_admin, $input); break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Action '$action' non reconnue."]);
    }

} catch (Exception $e) {
    logError("Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}
exit();

// =============================================
// WHERE de permission :
// - Admin      → 1=1 (aucune restriction)
// - Autres     → filtre sur id_destinataire / groupe_destinataire
//   On utilise directement $_SESSION['groupe_notification'] qui est
//   déjà calculé à la connexion (ex: 'techniciens_labo', 'admin', …)
// =============================================
function permissionClause(int $user_id, string $groupe, bool $is_admin): array
{
    if ($is_admin) {
        return ['where' => '1=1', 'params' => []];
    }

    return [
        'where'  => "(id_destinataire = ?
                      OR groupe_destinataire = ?
                      OR groupe_destinataire = 'tous')",
        'params' => [$user_id, $groupe],
    ];
}

// =============================================
function handleMarkRead(int $user_id, string $groupe, bool $is_admin, array $input): void
{
    $id = (int)($input['idnotification'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'idnotification requis.']);
        return;
    }

    try {
        $db   = new Database();
        $conn = $db->getServicesConnection();

        $check = $conn->prepare(
            "SELECT idnotification, id_destinataire, groupe_destinataire, lu
             FROM services_notifications WHERE idnotification = ?"
        );
        $check->execute([$id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Notification introuvable.']);
            return;
        }
        logError("Notif $id", $row);

        $perm   = permissionClause($user_id, $groupe, $is_admin);
        $params = array_merge([$id], $perm['params']);
        $sql    = "UPDATE services_notifications
                   SET lu = 1, date_lecture = NOW()
                   WHERE idnotification = ? AND " . $perm['where'];

        logError("SQL mark_read", ['params' => $params]);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => 'Permission insuffisante.',
                '_debug'  => [
                    'notif_dest'  => $row['id_destinataire'],
                    'notif_group' => $row['groupe_destinataire'],
                    'user_id'     => $user_id,
                    'groupe'      => $groupe,
                    'is_admin'    => $is_admin,
                ],
            ]);
        }

    } catch (Exception $e) {
        logError("Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =============================================
function handleArchive(int $user_id, string $groupe, bool $is_admin, array $input): void
{
    $id = (int)($input['idnotification'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'idnotification requis.']);
        return;
    }

    try {
        $db   = new Database();
        $conn = $db->getServicesConnection();

        $check = $conn->prepare(
            "SELECT idnotification, id_destinataire, groupe_destinataire
             FROM services_notifications WHERE idnotification = ?"
        );
        $check->execute([$id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Notification introuvable.']);
            return;
        }
        logError("Notif $id à archiver", $row);

        $perm   = permissionClause($user_id, $groupe, $is_admin);
        $params = array_merge([$id], $perm['params']);
        $sql    = "UPDATE services_notifications
                   SET archive = 1
                   WHERE idnotification = ? AND " . $perm['where'];

        logError("SQL archive", ['params' => $params]);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => 'Permission insuffisante.',
                '_debug'  => [
                    'notif_dest'  => $row['id_destinataire'],
                    'notif_group' => $row['groupe_destinataire'],
                    'user_id'     => $user_id,
                    'groupe'      => $groupe,
                    'is_admin'    => $is_admin,
                ],
            ]);
        }

    } catch (Exception $e) {
        logError("Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =============================================
function handleMarkAllRead(int $user_id, string $groupe, bool $is_admin, array $input): void
{
    $service = trim($input['service'] ?? '');

    try {
        $db   = new Database();
        $conn = $db->getServicesConnection();

        $perm   = permissionClause($user_id, $groupe, $is_admin);
        $params = $perm['params'];
        $sql    = "UPDATE services_notifications
                   SET lu = 1, date_lecture = NOW()
                   WHERE lu = 0 AND archive = 0 AND " . $perm['where'];

        if (!empty($service)) {
            $sql     .= " AND service = ?";
            $params[] = $service;
        }

        logError("SQL mark_all_read", ['params' => $params, 'service' => $service]);
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $count = $stmt->rowCount();
        logError("Rows: $count");

        echo json_encode(['success' => true, 'count' => $count]);

    } catch (Exception $e) {
        logError("Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =============================================
function handleIncoming(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'POST requis.']);
        return;
    }

    $token    = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    $expected = defined('INCOMING_WEBHOOK_TOKEN') ? INCOMING_WEBHOOK_TOKEN : 'csk_internal';
    if ($token !== $expected) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Token invalide.']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'JSON invalide.']);
        return;
    }

    $service  = sanitizeInput($input['service'] ?? '');
    $type     = sanitizeInput($input['type']    ?? 'prescription_recue');
    $code     = sanitizeInput($input['code']    ?? '');
    $titre    = sanitizeInput($input['titre']   ?? 'Nouvelle prescription');
    $message  = $input['message']  ?? '';
    $priorite = sanitizeInput($input['priorite'] ?? 'normale');

    if (empty($service) || !in_array($service, ['labo', 'imagerie', 'pharmacie'])) {
        echo json_encode(['success' => false, 'error' => 'Service invalide.']);
        return;
    }

    $id = sendNotification($service, $type, null, '', $code, $titre, $message, null, $service, $priorite);
    echo json_encode(['success' => $id !== false, 'idnotification' => $id]);
}