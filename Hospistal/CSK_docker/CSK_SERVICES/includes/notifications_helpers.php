<?php
/**
 * Systeme de notifications bidirectionnel - VERSION CORRIGÉE
 */

// URL du webhook de l'appli GPS (configurable)
if (!defined('GPS_WEBHOOK_URL')) {
    define('GPS_WEBHOOK_URL', 'http://localhost/csk_gps/api/notifications_incoming.php');
}

// =============================================
// ENVOYER UNE NOTIFICATION INTERNE
// =============================================

function sendNotification(
    string $service,
    string $type,
    ?int $id_ref,
    string $table_ref,
    string $code_ref,
    string $titre,
    string $message,
    ?int $id_destinataire = null,
    ?string $groupe_dest = null,
    string $priorite = 'normale'
): int|false {
    try {
        $db = new Database();
        $conn = $db->getServicesConnection();
        
        $stmt = $conn->prepare("
            INSERT INTO services_notifications 
                (service, type, id_reference, table_reference, code_reference, 
                 titre, message, id_destinataire, groupe_destinataire, priorite)
            VALUES (:service, :type, :id_ref, :table_ref, :code_ref,
                    :titre, :message, :id_dest, :groupe, :priorite)
        ");
        $stmt->execute([
            ':service'   => $service,
            ':type'      => $type,
            ':id_ref'    => $id_ref,
            ':table_ref' => $table_ref,
            ':code_ref'  => $code_ref,
            ':titre'     => $titre,
            ':message'   => $message,
            ':id_dest'   => $id_destinataire,
            ':groupe'    => $groupe_dest,
            ':priorite'  => $priorite,
        ]);
        
        return (int) $conn->lastInsertId();
    } catch (Exception $e) {
        error_log("[CSK Services][Notifications] Erreur sendNotification: " . $e->getMessage());
        return false;
    }
}

// =============================================
// NOTIFICATION EXTERNE -> APPLI GPS
// =============================================

function sendExternalNotification(array $data): bool
{
    $payload = array_merge([
        'source'    => 'csk_services',
        'timestamp' => date('Y-m-d H:i:s'),
    ], $data);

    try {
        $ch = curl_init(GPS_WEBHOOK_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Source: csk_services',
                'X-Auth-Token: ' . (defined('GPS_WEBHOOK_TOKEN') ? GPS_WEBHOOK_TOKEN : 'csk_internal'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            return true;
        }

        error_log("[CSK Services][Notifications] Webhook GPS echec HTTP $http_code: $curl_err | Response: $response");
        return false;
        
    } catch (Exception $e) {
        error_log("[CSK Services][Notifications] Erreur webhook GPS: " . $e->getMessage());
        return false;
    }
}

function notifyGPS_ResultatPret(string $service, string $code_ref, int $idpatient, string $acte_libelle, string $message_detail = ''): bool
{
    return sendExternalNotification([
        'type'         => 'resultat_pret',
        'service'      => $service,
        'code'         => $code_ref,
        'idpatient'    => $idpatient,
        'acte'         => $acte_libelle,
        'message'      => $message_detail ?: "Les resultats de $code_ref ($acte_libelle) sont disponibles.",
    ]);
}

function notifyGPS_MedicamentDelivre(string $code_ref, int $idpatient, string $produit_nom, string $message_detail = ''): bool
{
    return sendExternalNotification([
        'type'      => 'medicament_delivre',
        'service'   => 'pharmacie',
        'code'      => $code_ref,
        'idpatient' => $idpatient,
        'produit'   => $produit_nom,
        'message'   => $message_detail ?: "Les medicaments de la preparation $code_ref ont ete delivres.",
    ]);
}

// =============================================
// LIRE LES NOTIFICATIONS
// =============================================

function getNotifications(
    int $user_id, 
    string $profil_code, 
    ?string $service_filter = null,
    int $limit = 20, 
    int $offset = 0,
    bool $non_lues_seulement = false
): array {
    try {
        $db = new Database();
        $conn = $db->getServicesConnection();
        
        $where = ["n.archive = 0"];
        $params = [];
        
        if ($profil_code === 'admin') {
            // Admin voit tout
        } else {
            // Groupe par profil - UTILISE LE MAPPING DE config.php
            $groupe = getGroupeFromProfil($profil_code);
            $where[] = "(n.id_destinataire = :uid OR n.groupe_destinataire = :grp OR n.groupe_destinataire = 'tous')";
            $params[':uid'] = $user_id;
            $params[':grp'] = $groupe;
        }
        
        if (!empty($service_filter)) {
            $where[] = "n.service = :svc";
            $params[':svc'] = $service_filter;
        }
        
        if ($non_lues_seulement) {
            $where[] = "n.lu = 0";
        }
        
        $where_sql = implode(' AND ', $where);
        
        $stmt = $conn->prepare("
            SELECT n.* FROM services_notifications n
            WHERE $where_sql
            ORDER BY n.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("[CSK Services][Notifications] Erreur getNotifications: " . $e->getMessage());
        return [];
    }
}

function countUnread(int $user_id, string $profil_code, ?string $service_filter = null): int
{
    try {
        $db = new Database();
        $conn = $db->getServicesConnection();
        
        $where = ["n.archive = 0", "n.lu = 0"];
        $params = [];
        
        if ($profil_code === 'admin') {
            // Admin voit tout
        } else {
            $groupe = getGroupeFromProfil($profil_code);
            $where[] = "(n.id_destinataire = :uid OR n.groupe_destinataire = :grp OR n.groupe_destinataire = 'tous')";
            $params[':uid'] = $user_id;
            $params[':grp'] = $groupe;
        }
        
        if (!empty($service_filter)) {
            $where[] = "n.service = :svc";
            $params[':svc'] = $service_filter;
        }
        
        $where_sql = implode(' AND ', $where);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM services_notifications n WHERE $where_sql");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
        
    } catch (Exception $e) {
        error_log("[CSK Services][Notifications] Erreur countUnread: " . $e->getMessage());
        return 0;
    }
}

// =============================================
// ACTIONS SUR LES NOTIFICATIONS
// =============================================

function markAsRead(int $idnotification): bool
{
    try {
        $db = new Database();
        $conn = $db->getServicesConnection();
        $stmt = $conn->prepare("UPDATE services_notifications SET lu = 1, read_at = NOW() WHERE idnotification = :id");
        return $stmt->execute([':id' => $idnotification]);
    } catch (Exception $e) {
        return false;
    }
}

function markAllRead(int $user_id, string $profil_code, ?string $service = null): bool
{
    try {
        $db = new Database();
        $conn = $db->getServicesConnection();
        
        $where = ["lu = 0", "archive = 0"];
        $params = [];
        
        if ($profil_code !== 'admin') {
            $groupe = getGroupeFromProfil($profil_code);
            $where[] = "(id_destinataire = :uid OR groupe_destinataire = :grp OR groupe_destinataire = 'tous')";
            $params[':uid'] = $user_id;
            $params[':grp'] = $groupe;
        }
        
        if (!empty($service)) {
            $where[] = "service = :svc";
            $params[':svc'] = $service;
        }
        
        $where_sql = implode(' AND ', $where);
        $sql = "UPDATE services_notifications SET lu = 1, date_lecture = NOW() WHERE $where_sql";
        
        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    } catch (Exception $e) {
        error_log("[Notifications] Erreur markAllRead: " . $e->getMessage());
        return false;
    }
}

function archiveNotification(int $idnotification): bool
{
    try {
        $db = new Database();
        $conn = $db->getServicesConnection();
        $stmt = $conn->prepare("UPDATE services_notifications SET archive = 1 WHERE idnotification = :id");
        return $stmt->execute([':id' => $idnotification]);
    } catch (Exception $e) {
        return false;
    }
}

// =============================================
// HELPER : mapper profil -> groupe notification
// CORRECTION : DOIT RETOURNER LES MÊMES VALEURS QUE config.php PROFIL_GROUPE_NOTIFICATION
// =============================================

function getGroupeFromProfil(string $profil_code): string
{
    // Utiliser le mapping de config.php si disponible
    if (defined('PROFIL_GROUPE_NOTIFICATION') && isset(PROFIL_GROUPE_NOTIFICATION[$profil_code])) {
        return PROFIL_GROUPE_NOTIFICATION[$profil_code];
    }
    
    // Sinon, mapping par défaut (doit correspondre à config.php)
    return match ($profil_code) {
        'admin'                => 'admin',
        'technicien_labo'      => 'techniciens_labo',
        'biologiste'           => 'biologistes',
        'technicien_imagerie'  => 'manipulateurs_imagerie',
        'radiologue'           => 'radiologues',
        'pharmacien'           => 'pharmaciens',
        'pharmacien_chef'      => 'pharmaciens',
        default                => $profil_code,
    };
}

// =============================================
// HELPERS DE NOTIFICATION PAR SERVICE
// =============================================

function createNotification(PDO $conn, array $data): int|false
{
    return sendNotification(
        service:          $data['service'] ?? 'general',
        type:             $data['type'] ?? 'info',
        id_ref:           $data['id_reference'] ?? null,
        table_ref:        $data['table_reference'] ?? '',
        code_ref:         $data['code_reference'] ?? '',
        titre:            $data['titre'] ?? '',
        message:          $data['message'] ?? '',
        id_destinataire:  $data['id_destinataire'] ?? null,
        groupe_dest:      $data['groupe_destinataire'] ?? null,
        priorite:         $data['priorite'] ?? 'normale'
    );
}

function notifyStatutChange(
    string $service,
    string $code_ref,
    int $id_ref,
    string $table_ref,
    string $ancien_statut,
    string $nouveau_statut,
    int $idpatient,
    string $acte_libelle = ''
): void {
    $titre = strtoupper($service) . " : $code_ref";
    $message = "Statut passe de '$ancien_statut' a '$nouveau_statut'";
    if (!empty($acte_libelle)) {
        $message .= " (acte: $acte_libelle)";
    }

    // Determiner le groupe destinataire et la priorite
    $groupe = match($service) {
        'labo'      => 'techniciens_labo',
        'imagerie'  => 'manipulateurs_imagerie',
        'pharmacie' => 'pharmaciens',
        default     => $service
    };
    $priorite = 'normale';

    // Statuts finaux -> notifier aussi l'app GPS
    $statuts_finaux = [
        'labo'      => 'resultat_transmis',
        'imagerie'  => 'transmis',
        'pharmacie' => 'delivre',
    ];

    $type = 'statut_change';
    if (isset($statuts_finaux[$service]) && $nouveau_statut === $statuts_finaux[$service]) {
        $type = ($service === 'pharmacie') ? 'medicament_delivre' : 'resultat_pret';
        $priorite = 'haute';
        $groupe = 'medecin'; // Notifier le groupe medecin dans csk_services
        
        // Notifier l'appli GPS
        if ($service === 'pharmacie') {
            notifyGPS_MedicamentDelivre($code_ref, $idpatient, $acte_libelle);
        } else {
            notifyGPS_ResultatPret($service, $code_ref, $idpatient, $acte_libelle);
        }
    }

    sendNotification(
        $service, $type, $id_ref, $table_ref, $code_ref,
        $titre, $message, null, $groupe, $priorite
    );
}