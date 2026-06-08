<?php
/**
 * Laboratoire - Fonctions et constantes communes
 * VERSION SIMPLIFIÉE - Workflow à 5 étapes
 */

if (defined('LABO_HELPERS_LOADED')) return;
define('LABO_HELPERS_LOADED', true);

// =============================================
// CONNEXION INTERNE — évite le global fragile
// Utilise un singleton statique : initialisée une seule fois,
// réutilisée pour tous les appels suivants dans le même script.
// =============================================

function laboGetServicesConnection(): PDO {
    static $conn = null;
    if ($conn === null) {
        require_once __DIR__ . '/../config/database.php';
        $db   = new Database();
        $conn = $db->getServicesConnection();
    }
    return $conn;
}

// =============================================
// MAP STATUT -> LABELS ET COULEURS (simplifié)
// =============================================

$GLOBALS['labo_statut_labels'] = [
    // ── Workflow principal (3 étapes) ──────────────────────
    'attente_prelevement'   => ['label' => 'Att. prélèvement', 'color' => '#6c757d', 'bg' => '#e9ecef'],
    'preleve'               => ['label' => 'Prélevé',          'color' => '#0d6efd', 'bg' => '#cfe2ff'],
    'validation_technique'  => ['label' => 'Validé / Résultats saisis', 'color' => '#198754', 'bg' => '#d1e7dd'],
    // ── Statuts exceptionnels ──────────────────────────────
    'rejete'                => ['label' => 'Rejeté',           'color' => '#dc3545', 'bg' => '#f8d7da'],
    'perdu'                 => ['label' => 'Perdu',            'color' => '#842029', 'bg' => '#f5c2c7'],
    'annule'                => ['label' => 'Annulé',           'color' => '#6c757d', 'bg' => '#e9ecef'],
];

// =============================================
// COULEURS DE TUBE
// =============================================

$GLOBALS['labo_tube_colors'] = [
    'violet' => '#6f42c1',
    'rouge'  => '#dc3545',
    'vert'   => '#198754',
    'bleu'   => '#0d6efd',
    'gris'   => '#6c757d',
    'jaune'  => '#ffc107',
    'noir'   => '#000000',
    'rose'   => '#d63384',
];

// =============================================
// MAP DES TRANSITIONS AUTORISEES (workflow simplifié)
// =============================================

/**
 * WORKFLOW SIMPLIFIÉ — 3 étapes par sous-examen
 *
 *  attente_prelevement
 *       ↓ (technicien / biologiste / admin)
 *    preleve
 *       ↓ (technicien / biologiste / admin)
 *  validation_technique  ← résultats saisis ici
 *
 * Actions individuelles : chaque sous-examen avance indépendamment.
 */
$GLOBALS['labo_transitions'] = [
    'attente_prelevement' => [
        'preleve' => ['Marquer prélevé', 'bi-droplet-half', 'btn-primary',
                      ['admin','technicien_labo','biologiste']],
        'annule'  => ['Annuler',         'bi-x-circle',     'btn-outline-danger',
                      ['admin','biologiste']],
    ],
    'preleve' => [
        'validation_technique' => ['Saisir résultats & valider', 'bi-patch-check', 'btn-success',
                                   ['admin','technicien_labo','biologiste']],
        'rejete' => ['Rejeter',      'bi-x-octagon',       'btn-danger',
                     ['admin','technicien_labo','biologiste']],
        'perdu'  => ['Marquer perdu','bi-question-circle', 'btn-outline-danger',
                     ['admin','technicien_labo','biologiste']],
    ],
];

// =============================================
// STATUTS FINAUX
// =============================================

define('LABO_STATUTS_FINAUX',    ['validation_technique', 'annule', 'rejete', 'perdu']);

// Statuts où les résultats sont accessibles
define('LABO_STATUTS_RESULTATS', ['preleve', 'validation_technique']);

// =============================================
// FONCTIONS UTILITAIRES
// =============================================

function getLaboStatutBadge(string $statut): string {
    $labels = $GLOBALS['labo_statut_labels'];
    $info = $labels[$statut] ?? ['label' => $statut, 'color' => '#6c757d', 'bg' => '#e9ecef'];
    return '<span class="badge" style="background:' . $info['bg'] . '; color:' . $info['color'] . '; font-weight:600;">'
         . htmlspecialchars($info['label']) . '</span>';
}

function formatLaboDelai($minutes): string {
    if ($minutes === null) return '-';
    $minutes = (int)$minutes;
    if ($minutes < 60) return $minutes . ' min';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $h . 'h' . ($m > 0 ? str_pad($m, 2, '0', STR_PAD_LEFT) : '');
}

function getLaboTubeColor(?string $couleur): ?string {
    if (!$couleur) return null;
    return $GLOBALS['labo_tube_colors'][$couleur] ?? null;
}

function isLaboEnRetard(int $delai_actuel, int $delai_theorique, string $statut): bool {
    return ($delai_actuel > $delai_theorique) && !in_array($statut, LABO_STATUTS_FINAUX);
}

function getLaboTransitionsPossibles(string $statut_actuel, string $profil_code): array {
    $transitions = $GLOBALS['labo_transitions'];
    $actions = $transitions[$statut_actuel] ?? [];
    
    $filtrees = [];
    foreach ($actions as $new_st => $info) {
        if (in_array($profil_code, $info[3])) {
            $filtrees[$new_st] = $info;
        }
    }
    return $filtrees;
}

function buildLaboFilterUrl(string $base_action = 'echantillons', array $extra = []): string {
    $params = $_GET;
    unset($params['page'], $params['action']);
    $params = array_merge($params, $extra);
    $qs = http_build_query($params);
    return 'index.php?page=labo&action=' . $base_action . ($qs ? '&' . $qs : '');
}

function getLaboQualiteColor(?string $qualite): string {
    return match($qualite) {
        'excellente' => '#198754',
        'bonne'      => '#20c997',
        'moyenne'    => '#ffc107',
        default      => '#dc3545',
    };
}

function getLaboDelaiProgressHtml(int $delai_actuel, int $delai_theorique): string {
    $pct = $delai_theorique > 0 ? round(($delai_actuel / $delai_theorique) * 100) : 0;
    $color = $pct > 100 ? '#dc3545' : ($pct > 75 ? '#fd7e14' : '#ffc107');
    
    return '<div style="min-width:80px;">'
         . '<div style="font-size:0.75rem; color:' . $color . '; font-weight:600;">'
         . formatLaboDelai($delai_actuel) . ' / ' . formatLaboDelai($delai_theorique)
         . '</div>'
         . '<div class="progress" style="height:4px;">'
         . '<div class="progress-bar" style="width:' . min($pct, 100) . '%; background:' . $color . ';"></div>'
         . '</div>'
         . '</div>';
}

/**
 * Génère un token unique pour l'accès aux résultats
 */
function generateResultToken($code_echantillon, $idresultat, $email_destinataire = null, $duree_validite_heures = 168) { // 7 jours par défaut
    $conn_services = laboGetServicesConnection();
    
    $token = bin2hex(random_bytes(32)); // 64 caractères
    $date_creation = date('Y-m-d H:i:s');
    $date_expiration = $duree_validite_heures ? date('Y-m-d H:i:s', strtotime("+$duree_validite_heures hours")) : null;
    
    $stmt = $conn_services->prepare("
        INSERT INTO labo_resultats_tokens 
            (token, code_echantillon, idresultat, email_destinataire, date_creation, date_expiration, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $token,
        $code_echantillon,
        $idresultat,
        $email_destinataire,
        $date_creation,
        $date_expiration,
        $_SESSION['user_id'] ?? null
    ]);
    
    return $token;
}

/**
 * =============================================
 * SYSTÈME DE TOKENS POUR ACCÈS PUBLIC AUX RÉSULTATS
 * À ajouter à la fin du fichier includes/labo_helpers.php
 * =============================================
 */

/**
 * Génère un token sécurisé pour l'accès public à un résultat
 * 
 * @param string $code_echantillon Code de l'échantillon
 * @param int $idresultat ID du résultat dans my_database.resultat_labo
 * @param string|null $email_destinataire Email du prescripteur (optionnel)
 * @param int $duree_validite_heures Durée de validité en heures (168 = 7 jours par défaut)
 * @return string|false Token généré ou false en cas d'erreur
 */
function generateLaboResultToken($code_echantillon, $idresultat, $email_destinataire = null, $duree_validite_heures = 168) {
    $conn_services = laboGetServicesConnection();
    
    $user_id = $_SESSION['user_id'] ?? null;
    
    try {
        // Appeler la procédure stockée
        $stmt = $conn_services->prepare("
            CALL sp_creer_token_resultat(
                :code_echantillon,
                :idresultat,
                :email_destinataire,
                :duree_validite_heures,
                :created_by,
                @token,
                @success,
                @message
            )
        ");
        
        $stmt->execute([
            ':code_echantillon' => $code_echantillon,
            ':idresultat' => $idresultat,
            ':email_destinataire' => $email_destinataire,
            ':duree_validite_heures' => $duree_validite_heures,
            ':created_by' => $user_id
        ]);
        
        // Récupérer le token généré
        $result = $conn_services->query("SELECT @token as token, @success as success, @message as message")->fetch();
        
        if ($result && $result['success']) {
            error_log("[TOKEN] Token généré avec succès : " . substr($result['token'], 0, 10) . "... pour échantillon $code_echantillon");
            return $result['token'];
        } else {
            error_log("[TOKEN] Échec génération token : " . ($result['message'] ?? 'Erreur inconnue'));
            return false;
        }
        
    } catch (Exception $e) {
        error_log("[TOKEN] Erreur génération token : " . $e->getMessage());
        return false;
    }
}

/**
 * Vérifie si un token est valide
 * 
 * @param string $token Token à vérifier
 * @return array|false Informations du token ou false si invalide
 */
function verifyLaboResultToken($token) {
    $conn_services = laboGetServicesConnection();
    
    try {
        $stmt = $conn_services->prepare("
            SELECT *
            FROM v_tokens_resultats
            WHERE token = :token
            AND actif = 1
            AND (date_expiration IS NULL OR date_expiration > NOW())
        ");
        $stmt->execute([':token' => $token]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("[TOKEN] Erreur vérification token : " . $e->getMessage());
        return false;
    }
}

/**
 * Marque un token comme consulté
 * 
 * @param string $token Token consulté
 * @param string|null $ip Adresse IP du visiteur
 * @return bool Succès de l'opération
 */
function markLaboTokenAsViewed($token, $ip = null) {
    $conn_services = laboGetServicesConnection();
    
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    try {
        $stmt = $conn_services->prepare("CALL sp_marquer_token_consulte(:token, :ip)");
        $stmt->execute([
            ':token' => $token,
            ':ip' => $ip
        ]);
        
        return true;
        
    } catch (Exception $e) {
        error_log("[TOKEN] Erreur marquage consultation : " . $e->getMessage());
        return false;
    }
}

/**
 * Génère un token sécurisé pour un GROUPE d'échantillons.
 *
 * ⚠️  RÈGLE DE COHÉRENCE ABSOLUE :
 *     Cette fonction insère dans labo_resultats_tokens — la MÊME table que
 *     les tokens individuels, lue par generer_pdf_resultat_unique.php.
 *     On stocke code_groupe dans la colonne code_echantillon et on laisse
 *     idresultat = NULL → le PDF détecte idresultat IS NULL → mode groupe.
 *
 *     NE PAS utiliser la stored proc sp_creer_token_resultat : elle valide
 *     que le code existe dans labo_echantillons (codes -NN), ce qui
 *     rejetterait un code groupe (sans suffixe).
 *
 * @param string      $code_groupe  Code du groupe (ex: LAB-20260306-0001)
 * @param string|null $email        Email du prescripteur (optionnel)
 * @param int         $duree_heures Durée de validité (168 h = 7 jours)
 * @return string|false             Token 64 caractères hex, ou false si erreur
 */
/**
 * Génère un token sécurisé pour un GROUPE d'échantillons.
 *
 * Insère dans labo_groupes_tokens — table dédiée sans contrainte FK,
 * conçue exactement pour les codes groupe (LAB-YYYYMMDD-XXXX).
 *
 * labo_resultats_tokens ne peut PAS être utilisée ici car :
 *   - idresultat INT NOT NULL → ne peut pas stocker NULL
 *   - FK code_echantillon → labo_echantillons : rejette les codes groupe
 *
 * generer_pdf_resultat_unique.php détecte le token dans labo_groupes_tokens
 * via verifyLaboGroupToken() et charge tous les sous-examens du groupe.
 */
function generateLaboGroupToken($code_groupe, $email = null, $duree_heures = 168) {
    $conn = laboGetServicesConnection();

    try {
        $token           = bin2hex(random_bytes(32)); // 64 hex chars
        $date_expiration = $duree_heures
            ? date('Y-m-d H:i:s', strtotime("+{$duree_heures} hours"))
            : null;

        $conn->prepare("
            INSERT INTO labo_groupes_tokens
                (token, code_groupe, email_destinataire,
                 date_creation, date_expiration, actif, nb_consultations, created_by)
            VALUES
                (:token, :code, :email,
                 NOW(), :exp, 1, 0, :uid)
        ")->execute([
            ':token' => $token,
            ':code'  => $code_groupe,
            ':email' => $email,
            ':exp'   => $date_expiration,
            ':uid'   => $_SESSION['user_id'] ?? null,
        ]);

        error_log("[TOKEN-GROUPE] ✅ Token créé pour groupe $code_groupe dans labo_groupes_tokens");
        return $token;

    } catch (Exception $e) {
        error_log("[TOKEN-GROUPE] ❌ Erreur : " . $e->getMessage());
        return false;
    }
}

/**
 * Vérifie si un token de groupe est valide.
 * Lit dans labo_resultats_tokens avec idresultat IS NULL (= token groupe).
 */
function verifyLaboGroupToken($token) {
    $conn = laboGetServicesConnection();

    try {
        $stmt = $conn->prepare("
            SELECT *
            FROM labo_groupes_tokens
            WHERE token  = :token
              AND actif  = 1
              AND (date_expiration IS NULL OR date_expiration > NOW())
        ");
        $stmt->execute([':token' => $token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("[TOKEN-GROUPE] Erreur vérification : " . $e->getMessage());
        return false;
    }
}

/**
 * Marque un token de groupe comme consulté.
 * Met à jour labo_resultats_tokens (même table).
 */
function markLaboGroupTokenAsViewed($token, $ip = null) {
    $conn = laboGetServicesConnection();
    $ip   = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    try {
        $conn->prepare("
            UPDATE labo_groupes_tokens
            SET vu_le                    = NOW(),
                nb_consultations         = nb_consultations + 1,
                ip_derniere_consultation = :ip
            WHERE token = :token
        ")->execute([':token' => $token, ':ip' => $ip]);
        return true;

    } catch (Exception $e) {
        error_log("[TOKEN-GROUPE] Erreur marquage : " . $e->getMessage());
        return false;
    }
}

/**
 * Génère l'URL publique pour consulter un résultat via token
 * 
 * @param string $token Token du résultat
 * @return string URL complète
 */
function getLaboResultPublicUrl($token) {
    // Déterminer l'URL de base de manière plus fiable
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base_url = $protocol . $host . '/';
    
    // 🔴 CORRECTION : Lien direct vers le générateur PDF, pas vers resultats.php
    return $base_url . 'public/generer_pdf_resultat_unique.php?token=' . urlencode($token);
}

/**
 * Envoie un email avec le lien du résultat
 * 
 * @param string $email_destinataire Email du prescripteur
 * @param string $prescripteur_nom Nom du prescripteur
 * @param string $patient_nom Nom du patient
 * @param string $examen_libelle Libellé de l'examen
 * @param string $interpretation Interprétation (normal, anormal, critique)
 * @param string $token Token pour accès public
 * @return bool Succès de l'envoi
 */
function sendLaboResultEmail($email_destinataire, $prescripteur_nom, $patient_nom, $examen_libelle, $interpretation, $token) {
    if (empty($email_destinataire)) {
        return false;
    }
    
    $lien_public = getLaboResultPublicUrl($token);
    
    // Label interprétation
    $interp_label = match($interpretation) {
        'critique' => '🔴 RÉSULTAT CRITIQUE',
        'anormal' => '⚠️ Résultat anormal',
        'normal' => '✅ Résultat normal',
        default => 'Résultat disponible'
    };
    
    $couleur_badge = match($interpretation) {
        'critique' => '#dc3545',
        'anormal' => '#ffc107',
        'normal' => '#198754',
        default => '#0d6efd'
    };
    
    // Email HTML
    $sujet = "Résultat d'analyse disponible - $examen_libelle";
    
    $html_email = "
<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
</head>
<body style='margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f5f5f5;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #f5f5f5; padding: 20px;'>
        <tr>
            <td align='center'>
                <table width='600' cellpadding='0' cellspacing='0' style='background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                    
                    <!-- En-tête -->
                    <tr>
                        <td style='background-color: #0d6efd; color: #ffffff; padding: 30px; text-align: center;'>
                            <h1 style='margin: 0; font-size: 24px;'>🔬 Résultat d'analyse</h1>
                            <p style='margin: 10px 0 0 0; font-size: 14px;'>Centre Hospitalier Monkole</p>
                        </td>
                    </tr>
                    
                    <!-- Contenu -->
                    <tr>
                        <td style='padding: 30px;'>
                            <p style='margin: 0 0 15px 0; font-size: 16px;'>Bonjour Dr. " . htmlspecialchars($prescripteur_nom) . ",</p>
                            
                            <p style='margin: 0 0 15px 0; font-size: 16px;'>Un résultat d'analyse est disponible pour votre patient <strong>" . htmlspecialchars($patient_nom) . "</strong>.</p>
                            
                            <table width='100%' cellpadding='15' cellspacing='0' style='background-color: #f8f9fa; border-radius: 8px; margin: 20px 0;'>
                                <tr>
                                    <td>
                                        <p style='margin: 0 0 10px 0;'><strong>Examen :</strong> " . htmlspecialchars($examen_libelle) . "</p>
                                        <p style='margin: 0;'><strong>Interprétation :</strong> <span style='background-color: $couleur_badge; color: white; padding: 5px 10px; border-radius: 5px; font-size: 14px; font-weight: bold;'>$interp_label</span></p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style='margin: 20px 0; font-size: 16px;'>Pour consulter le résultat complet, cliquez sur le bouton ci-dessous :</p>
                            
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='$lien_public' style='display: inline-block; background-color: #198754; color: #ffffff; text-decoration: none; padding: 15px 30px; border-radius: 5px; font-size: 16px; font-weight: bold;'>Voir le résultat</a>
                            </div>
                            
                            <p style='margin: 20px 0 0 0; font-size: 14px; color: #6c757d;'><em>Ce lien est valable 7 jours et sera désactivé automatiquement après expiration.</em></p>
                        </td>
                    </tr>
                    
                    <!-- Pied de page -->
                    <tr>
                        <td style='background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #dee2e6;'>
                            <p style='margin: 0; font-size: 14px; color: #6c757d;'>
                                <strong>Laboratoire - Centre Hospitalier Monkole</strong><br>
                                En cas de problème, contactez-nous au 081 14 52 125
                            </p>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
    ";
    
    // Headers email
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Laboratoire Monkole <noreply@monkole.cd>\r\n";
    
    // Envoi
    try {
        $result = mail($email_destinataire, $sujet, $html_email, $headers);
        
        if ($result) {
            error_log("[EMAIL] Email envoyé avec succès à $email_destinataire");
        } else {
            error_log("[EMAIL] Échec envoi email à $email_destinataire");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[EMAIL] Erreur envoi email : " . $e->getMessage());
        return false;
    }
}