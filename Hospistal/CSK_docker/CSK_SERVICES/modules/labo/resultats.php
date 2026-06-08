<?php
/**
 * Module Laboratoire — Résultats
 * Version groupée : 1 groupe = N sous-examens, saisie collective
 *
 * ?code=LAB-YYYYMMDD-XXXX      → saisie de tous les sous-examens du groupe
 * ?code=LAB-YYYYMMDD-XXXX-NN   → redirige vers le groupe parent
 * Sans code                    → liste des groupes prêts pour résultats
 */

require_once __DIR__ . '/../../includes/labo_helpers.php';

$current_user     = getCurrentUser();
$user_id          = $current_user['id'];
$user_profil_code = $current_user['profil_code'];
$user_nom_complet = trim(($current_user['prenom'] ?? '') . ' ' . ($current_user['nom'] ?? ''));

$statut_labels = $GLOBALS['labo_statut_labels'];
$tube_colors   = $GLOBALS['labo_tube_colors'];

$db            = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

$GLOBALS['conn_services'] = $conn_services;

// Profils autorisés
$profils_saisie       = ['admin', 'biologiste', 'technicien_labo'];
$profils_modification = ['admin', 'biologiste'];

// =============================================
// TRAITEMENT POST - ENREGISTREMENT RÉSULTATS GROUPE
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_resultats_groupe'])) {
    $code_grp  = sanitizeInput($_POST['code_groupe'] ?? '');
    $machine_g = !empty($_POST['machine_globale']) ? (int)$_POST['machine_globale'] : null;

    if (empty($code_grp)) {
        setFlash('error', 'Code groupe manquant.');
        header("Location: " . BASE_URL . "index.php?page=labo&action=resultats");
        exit();
    }
    if (!in_array($user_profil_code, $profils_saisie)) {
        setFlash('error', 'Votre profil ne permet pas la saisie des résultats.');
        header("Location: " . BASE_URL . "index.php?page=labo&action=resultats");
        exit();
    }

    $nb_ok         = 0;
    $liste_examens = [];
    $ech_updates   = []; // collecte pour conn_services — appliquée APRÈS commit conn_base

    try {
        // ══════════════════════════════════════════════════════════════════════
        // ÉTAPE 1 — Charger les sous-examens du groupe
        // ══════════════════════════════════════════════════════════════════════
        $stmt = $conn_services->prepare("
            SELECT
                le.idechantillon, le.idactes_presc, le.idresultat, le.statut,
                le.code_echantillon,
                a.libelle AS acte_libelle
            FROM labo_echantillons le
            JOIN labo_groupes_echantillons lg ON lg.idgroupe       = le.idgroupe
            LEFT JOIN csk_base.actes_presc  ap ON ap.idactes_presc  = le.idactes_presc
            LEFT JOIN csk_base.acte         a  ON a.idacte          = ap.idacte
            WHERE lg.code_groupe = :code AND le.deleted_at IS NULL
            ORDER BY le.sous_numero
        ");
        $stmt->execute([':code' => $code_grp]);
        $sous_examens = $stmt->fetchAll();

        // ══════════════════════════════════════════════════════════════════════
        // ÉTAPE 2 — Charger notification_info UNE FOIS, AVANT la boucle
        //           (pas conditionnel à nb_ok — évite le piège "0 résultats")
        // ══════════════════════════════════════════════════════════════════════
        $notification_info = null;
        $premier_idap      = null;
        foreach ($sous_examens as $_se) {
            if (!empty($_se['idactes_presc'])) { $premier_idap = $_se['idactes_presc']; break; }
        }
        if ($premier_idap) {
            try {
                $stmt_info = $conn_base->prepare("
                    SELECT
                        ap.prescripteur                AS idprescripteur,
                        u.nom                         AS prescripteur_nom,
                        NULL                           AS prescripteur_email,
                        CONCAT(p.nom,' ',p.prenom)   AS patient_nom
                    FROM actes_presc ap
                    JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                    JOIN sejour       s  ON ss.idsejour     = s.idsejour
                    JOIN patient      p  ON s.idpatient     = p.idpatient
                    LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                    WHERE ap.idactes_presc = :id
                    LIMIT 1
                ");
                $stmt_info->execute([':id' => $premier_idap]);
                $notification_info = $stmt_info->fetch();
            } catch (Exception $e) {
                error_log("[Résultats] Info prescripteur: " . $e->getMessage());
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        // ÉTAPE 3 — UNE SEULE transaction pour TOUS les resultatslabo
        //           Les UPDATE labo_echantillons (conn_services) sont collectés
        //           et appliqués APRÈS le commit — évite toute interférence
        // ══════════════════════════════════════════════════════════════════════
        $conn_base->beginTransaction();

        foreach ($sous_examens as $se) {
            $id_ech        = $se['idechantillon'];
            $resultat_text = trim($_POST["resultat_{$id_ech}"] ?? '');

            // ── Règle 0 : champ vide → skip (rien à sauvegarder) ──────────────
            if ($resultat_text === '') continue;

            // ══ VÉRIFICATION INDIVIDUELLE PAR SOUS-EXAMEN ════════════════════
            // Chaque examen est jugé sur SES PROPRES données.
            // Le statut des voisins n'a AUCUNE influence.

            // ── Règle 1 : Paiement vérifié dans workflow.php — pas besoin de recheck ici

                        // ── Règle 2 : STATUT INDIVIDUEL ──────────────────────────────────
            if (!$se['idresultat']) {
                // Nouveau résultat : examen doit être prélevé ou déjà validé
                if (!in_array($se['statut'], ['preleve','validation_technique','analyse_terminee'])) {
                    error_log("[Résultats] Skip #{$id_ech} statut={$se['statut']} — non prêt pour saisie");
                    continue;
                }
                if (!in_array($user_profil_code, $profils_saisie)) {
                    error_log("[Résultats] Skip #{$id_ech} — profil $user_profil_code insuffisant pour saisie");
                    continue;
                }
            } else {
                // Modification d'un résultat existant
                if (!in_array($user_profil_code, $profils_modification)) {
                    error_log("[Résultats] Skip #{$id_ech} — profil $user_profil_code insuffisant pour modification");
                    continue;
                }
            }

            $valeur_normale = trim($_POST["valeur_normale_{$id_ech}"] ?? '');
            $interpretation = sanitizeInput($_POST["interpretation_{$id_ech}"] ?? '');
            $observations   = trim($_POST["observations_{$id_ech}"] ?? '');
            $machine_id     = !empty($_POST["machine_{$id_ech}"]) ? (int)$_POST["machine_{$id_ech}"] : $machine_g;

            if ($se['idresultat']) {
                // ── Modification ──
                $conn_base->prepare("
                    UPDATE resultatslabo SET
                        resultat         = :res,
                        valeur_normale   = :vn,
                        interpretation   = :interp,
                        idmachinelabo    = :mac,
                        Observations     = :obs,
                        analyse_par      = :user,
                        date_analyse         = NOW()
                    WHERE idresultat     = :id
                ")->execute([
                    ':res'    => $resultat_text,
                    ':vn'     => $valeur_normale ?: null,
                    ':interp' => $interpretation ?: null,
                    ':mac'    => $machine_id ?: null,
                    ':obs'    => $observations ?: null,
                    ':user'   => $user_id,
                    ':id'     => $se['idresultat'],
                ]);
                // Collecte pour conn_services (après commit)
                $ech_updates[] = [
                    'mode' => 'update',
                    'id'   => $id_ech,
                    'idr'  => $se['idresultat'],
                    'mac'  => $machine_id,
                ];
            } else {
                // ── Insertion ──
                $conn_base->prepare("
                    INSERT INTO resultatslabo
                        (idactes_presc, resultat, valeur_normale, interpretation,
                         analyse_par, Observations, idmachinelabo, date_analyse)
                    VALUES (:ap, :res, :vn, :interp, :user, :obs, :mac, NOW())
                ")->execute([
                    ':ap'     => $se['idactes_presc'],
                    ':res'    => $resultat_text,
                    ':vn'     => $valeur_normale ?: null,
                    ':interp' => $interpretation ?: null,
                    ':user'   => $user_id,
                    ':obs'    => $observations ?: null,
                    ':mac'    => $machine_id ?: null,
                ]);
                $idresultat_new = (int)$conn_base->lastInsertId();
                // Collecte pour conn_services (après commit)
                $ech_updates[] = [
                    'mode' => 'insert',
                    'id'   => $id_ech,
                    'idr'  => $idresultat_new,
                    'mac'  => $machine_id,
                ];
            }

            $nb_ok++;
            $action_log = $se['idresultat'] ? 'UPDATE' : 'INSERT';
            error_log("[Résultats] ✅ #{$id_ech} {$action_log} — examen: " . ($se['acte_libelle'] ?? '?'));
            if (!empty($se['acte_libelle'])) {
                $liste_examens[] = $se['acte_libelle'];
            }
        }

        // ── Un seul commit pour tous les examens ──
        $conn_base->commit();

        // ══════════════════════════════════════════════════════════════════════
        // ÉTAPE 4 — Mettre à jour labo_echantillons (conn_services)
        //           Hors transaction, APRÈS le commit conn_base
        // ══════════════════════════════════════════════════════════════════════
        foreach ($ech_updates as $upd) {
            try {
                if ($upd['mode'] === 'insert') {
                    $conn_services->prepare("
                        UPDATE labo_echantillons
                        SET idresultat = :idr, idmachinelabo = :mac, statut = 'validation_technique'
                        WHERE idechantillon = :id
                    ")->execute([':idr' => $upd['idr'], ':mac' => $upd['mac'], ':id' => $upd['id']]);
                } else {
                    $conn_services->prepare("
                        UPDATE labo_echantillons
                        SET statut = 'validation_technique', idmachinelabo = :mac
                        WHERE idechantillon = :id
                    ")->execute([':mac' => $upd['mac'], ':id' => $upd['id']]);
                }
            } catch (Exception $e) {
                error_log("[Résultats] UPDATE labo_echantillons #{$upd['id']}: " . $e->getMessage());
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        // ÉTAPE 5 — Token de groupe + notification unique + email
        //           Exécuté si au moins 1 résultat traité ET prescripteur trouvé
        // ══════════════════════════════════════════════════════════════════════
        if ($nb_ok > 0 && $notification_info && !empty($notification_info['idprescripteur'])) {

            $examens_string = implode(', ', array_unique($liste_examens));
            $titre   = "🔬 Résultats laboratoire disponibles — Groupe {$code_grp}";
            $message = "Patient : {$notification_info['patient_nom']} | Examens : {$examens_string} | Saisi par : {$user_nom_complet}";

            // ── 5a. Générer le token de groupe EN PREMIER ──
            //        INSERT dans labo_resultats_tokens (idresultat = NULL = signal groupe)
            //        generer_pdf_resultat_unique.php lit idresultat IS NULL → mode groupe
            $lien_final = BASE_URL . "index.php?page=labo&action=resultats&code=" . urlencode($code_grp);
            $token      = null;

            if (function_exists('generateLaboGroupToken')) {
                $token = generateLaboGroupToken(
                    $code_grp,
                    $notification_info['prescripteur_email'] ?: null,
                    168 // 7 jours
                );
                if ($token) {
                    $lien_final = getLaboResultPublicUrl($token);
                    error_log("[Token] ✅ Token groupe créé → lien PDF: $lien_final");
                } else {
                    error_log("[Token] ❌ generateLaboGroupToken a retourné false pour $code_grp — vérifier error_log ci-dessus");
                }
            } else {
                error_log("[Token] ❌ Fonction generateLaboGroupToken() introuvable — labo_helpers.php bien inclus ?");
            }

            // ── 5b. Notification csk_base — lien inséré directement (jamais d'UPDATE après) ──
            try {
                $conn_base->prepare("
                    INSERT INTO notifications
                        (idutilisateur, Type, Titre, Message, Lien, Priorite, Date_notification)
                    VALUES (:uid, 'success', :titre, :msg, :lien, 'haute', NOW())
                ")->execute([
                    ':uid'   => $notification_info['idprescripteur'],
                    ':titre' => $titre,
                    ':msg'   => $message,
                    ':lien'  => $lien_final,
                ]);
                error_log("[Résultats] ✅ Notification csk_base insérée — lien: $lien_final");
            } catch (Exception $e_n1) {
                error_log("[Résultats] Notif csk_base: " . $e_n1->getMessage());
            }

            // ── 5c. Notification services ──
            try {
                $conn_services->prepare("
                    INSERT INTO services_notifications
                        (service, type_notification, id_reference, table_reference, code_reference,
                         titre, message, id_destinataire, groupe_destinataire, priorite, created_at)
                    VALUES ('labo', 'info', 0, 'labo_groupes_echantillons', :code_ref,
                            :titre, :msg, :destinataire, NULL, 'haute', NOW())
                ")->execute([
                    ':code_ref'     => $code_grp,
                    ':titre'        => $titre,
                    ':msg'          => $message,
                    ':destinataire' => $notification_info['idprescripteur'],
                ]);
            } catch (Exception $e_n2) {
                error_log("[Résultats] Notif services: " . $e_n2->getMessage());
            }

            // ── 5d. Email au prescripteur ──
            if ($token && !empty($notification_info['prescripteur_email']) && function_exists('sendLaboResultEmail')) {
                try {
                    sendLaboResultEmail(
                        $notification_info['prescripteur_email'],
                        $notification_info['prescripteur_nom'],
                        $notification_info['patient_nom'],
                        $examens_string,
                        'disponible',
                        $token
                    );
                } catch (Exception $e_mail) {
                    error_log("[Email] " . $e_mail->getMessage());
                }
            }
        }

        logAction('RESULTAT_LABO', "Groupe $code_grp : $nb_ok résultat(s) traité(s)");
        error_log("[Résultats] ── Bilan groupe {$code_grp} : {$nb_ok} résultat(s) traité(s)");
        if ($nb_ok > 0) {
            setFlash('success', "$nb_ok résultat(s) enregistré(s) pour le groupe <strong>$code_grp</strong>. Le prescripteur a été notifié.");
        } else {
            setFlash('warning', 'Aucun résultat sauvegardé — vérifiez que les champs ne sont pas vides.');
        }

    } catch (Exception $e) {
        if ($conn_base->inTransaction()) $conn_base->rollBack();
        setFlash('error', 'Erreur : ' . $e->getMessage());
        error_log("[Résultats] Erreur principale: " . $e->getMessage());
    }

    header("Location: " . BASE_URL . "index.php?page=labo&action=resultats&code=" . urlencode($code_grp));
    exit();
}

// =============================================
// TRAITEMENT UPLOAD DOCUMENT
// =============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_upload_document'])) {
    $idresultat  = (int)($_POST['idresultat'] ?? 0);
    $code_grp    = sanitizeInput($_POST['code_groupe'] ?? '');
    $description = sanitizeInput($_POST['description_document'] ?? '');
    $error_upload = '';

    if ($idresultat <= 0) {
        $error_upload = 'ID résultat invalide';
    } elseif (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $error_upload = "Erreur lors de l'upload du fichier";
    } else {
        $file      = $_FILES['document_file'];
        $file_name = $file['name'];
        $file_tmp  = $file['tmp_name'];
        $file_size = $file['size'];
        $file_type = $file['type'];

        if (!in_array($file_type, ['application/pdf'])) {
            $error_upload = 'Seuls les fichiers PDF sont acceptés';
        } elseif ($file_size > 10 * 1024 * 1024) {
            $error_upload = 'Le fichier ne doit pas dépasser 10 Mo';
        } else {
            try {
                $upload_dir = __DIR__ . '/../../uploads/resultats_documents/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $extension          = pathinfo($file_name, PATHINFO_EXTENSION);
                $nom_unique         = uniqid('doc_' . $idresultat . '_') . '.' . $extension;
                $chemin_destination = $upload_dir . $nom_unique;

                if (move_uploaded_file($file_tmp, $chemin_destination)) {
                    $conn_base->prepare("
                        INSERT INTO resultatslabo_documents
                            (idresultat, nom_fichier, fichier_original, chemin_fichier,
                             Taille, type_mime, Description, upload_par, date_upload)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([$idresultat, $nom_unique, $file_name, $chemin_destination,
                                 $file_size, $file_type, $description ?: null, $user_id]);
                    setFlash('success', 'Document ajouté avec succès');
                } else {
                    $error_upload = 'Erreur lors de la sauvegarde du fichier';
                }
            } catch (Exception $e) {
                $error_upload = 'Erreur: ' . $e->getMessage();
                error_log("[UPLOAD] " . $e->getMessage());
            }
        }
    }
    if ($error_upload) setFlash('error', $error_upload);
    header("Location: " . BASE_URL . "index.php?page=labo&action=resultats" . (!empty($code_grp) ? "&code=" . urlencode($code_grp) : ""));
    exit();
}

// =============================================
// CHARGEMENT DU GROUPE (?code=LAB-YYYYMMDD-XXXX)
// =============================================

$code_param = isset($_GET['code']) ? sanitizeInput($_GET['code']) : '';
$groupe     = null;
$sous_eches = [];
$machines   = [];

try {
    $stmt     = $conn_base->query("SELECT idmachinelabo AS idmachinelabo, Nom AS nom, Marque AS marque, Statut AS statut FROM machineslabo WHERE Actif=1 ORDER BY Nom");
    $machines = $stmt->fetchAll();
} catch (Exception $e) {}

if ($code_param) {
    // Si code sous-examen (LAB-YYYYMMDD-XXXX-NN) → rediriger vers le groupe
    if (preg_match('/^(LAB-\d{8}-\d{4})-\d+$/', $code_param, $m)) {
        header("Location: " . BASE_URL . "index.php?page=labo&action=resultats&code=" . urlencode($m[1]));
        exit();
    }

    // Pas de filtre lg.deleted_at (aligné sur echantillons.php)
    try {
        $stmt = $conn_services->prepare("
            SELECT lg.*,
                   p.nom AS patient_nom, p.prenom AS patient_prenom,
                   p.sexe AS patient_sexe, p.date_naissance AS patient_dob,
                   p.idpatient, p.type_patient, p.idsociete
            FROM labo_groupes_echantillons lg
            JOIN csk_base.patient p ON p.idpatient = lg.idpatient
            WHERE lg.code_groupe = :code
        ");
        $stmt->execute([':code' => $code_param]);
        $groupe = $stmt->fetch();
    } catch (Exception $e) {
        error_log("[Résultats] Groupe: " . $e->getMessage());
    }

    if ($groupe) {
        try {
            $stmt = $conn_services->prepare("
                SELECT le.idechantillon, le.code_echantillon, le.sous_numero,
                       le.statut, le.couleur_tube, le.tube_type, le.type_prelevement,
                       le.idresultat, le.idactes_presc, le.urgence,
                       le.date_prelevement, le.date_fin_analyse, le.date_validation,
                       a.libelle AS examen_libelle, a.idacte AS idacte,
                       ap.statut_validation AS ap_statut_validation,
                       ap.mode_paiement     AS ap_mode_paiement
                FROM labo_echantillons le
                LEFT JOIN csk_base.actes_presc ap ON ap.actes_presc = le.idactes_presc
                LEFT JOIN csk_base.acte         a ON a.idacte         = ap.idacte
                WHERE le.idgroupe = :id AND le.deleted_at IS NULL
                ORDER BY le.sous_numero
            ");
            $stmt->execute([':id' => $groupe['idgroupe']]);
            $rows = $stmt->fetchAll();
        } catch (Exception $e) {
            $rows = [];
            error_log("[Résultats] Sous-examens: " . $e->getMessage());
        }

        $sexe_patient = strtoupper($groupe['patient_sexe'] ?? 'H');
        $age_patient  = !empty($groupe['patient_dob'])
            ? (int)date_diff(date_create($groupe['patient_dob']), date_create('today'))->y : 30;
        $est_enfant   = $age_patient < 15;

        foreach ($rows as $row) {
            $item                            = $row;
            $item['resultat_existant']       = null;
            $item['valeurs_normales_badges'] = [];
            $item['prefill_resultat']        = '';
            $item['prefill_valeur_normale']  = '';
            $item['documents']               = [];

            // Résultat existant
            if ($row['idresultat']) {
                try {
                    $stmt = $conn_base->prepare("
                        SELECT r.*,
                               r.valeur_normale   AS valeur_normale,
                               r.interpretation   AS interpretation,
                               r.observations     AS observations,
                               r.analyse_par      AS analyse_par,
                               r.date_analyse         AS date_analyse,
                               r.idmachinelabo    AS idmachinelabo,
                               m.nom AS machine_nom,
                               u.nom AS analyste_nom, NULL AS analyste_prenom
                        FROM resultatslabo r
                        LEFT JOIN machineslabo m ON m.idmachinelabo = r.idmachinelabo
                        LEFT JOIN utilisateur u ON u.idutilisateur = r.analyse_par
                        WHERE r.idresultat = :id
                    ");
                    $stmt->execute([':id' => $row['idresultat']]);
                    $item['resultat_existant'] = $stmt->fetch();
                } catch (Exception $e) {}

                // Documents joints
                try {
                    $stmt_docs = $conn_base->prepare(
                        "SELECT *, idresultat AS idresultat, nom_fichier AS nom_fichier, fichier_original AS fichier_original, chemin_fichier AS chemin_fichier, date_upload AS date_upload, description FROM resultatslabo_documents WHERE idresultat = ? AND actif = 1 ORDER BY date_upload DESC"
                    );
                    $stmt_docs->execute([$row['idresultat']]);
                    $item['documents'] = $stmt_docs->fetchAll();
                } catch (Exception $e) {}
            }

            // Valeurs normales
            if ($row['idacte']) {
                try {
                    $stmt = $conn_base->prepare("
                        SELECT * FROM labo_valeurs_normales
                        WHERE idacte = :idacte AND actif = 1 ORDER BY ordre ASC
                    ");
                    $stmt->execute([':idacte' => $row['idacte']]);
                    foreach ($stmt->fetchAll() as $vn) {
                        if ($est_enfant && $vn['valeur_min_enfant'] !== null) {
                            $min = $vn['valeur_min_enfant']; $max = $vn['valeur_max_enfant']; $tag = 'Enfant';
                        } elseif ($sexe_patient === 'F' && $vn['valeur_min_femme'] !== null) {
                            $min = $vn['valeur_min_femme']; $max = $vn['valeur_max_femme']; $tag = 'F';
                        } else {
                            $min = $vn['valeur_min_homme']; $max = $vn['valeur_max_homme']; $tag = 'H';
                        }
                        $texte_vn = $vn['valeur_normale_texte'];
                        if (empty($texte_vn) && $min !== null && $max !== null)
                            $texte_vn = $vn['parametre'] . ' : ' . $min . ' – ' . $max . ' ' . ($vn['unite'] ?? '');

                        $item['prefill_resultat']       .= ($vn['format_resultat'] ?: $vn['parametre'] . ' : ') . "\n";
                        $item['prefill_valeur_normale'] .= $texte_vn . "\n";
                        $item['valeurs_normales_badges'][] = [
                            'parametre' => $vn['parametre'], 'unite' => $vn['unite'],
                            'min' => $min, 'max' => $max, 'tag' => $tag,
                        ];
                    }
                    $item['prefill_resultat']       = trim($item['prefill_resultat']);
                    $item['prefill_valeur_normale'] = trim($item['prefill_valeur_normale']);
                } catch (Exception $e) {
                    error_log("[Résultats] Valeurs normales idacte={$row['idacte']}: " . $e->getMessage());
                }
            }
            $sous_eches[] = $item;
        }
    }
}

// =============================================
// LISTE des groupes prêts (si pas de code)
// Alignée sur echantillons.php : pas de filtre lg.deleted_at
// =============================================

$liste_groupes        = [];
$filtre_recherche_res = isset($_GET['q'])       ? sanitizeInput($_GET['q'])       : '';
$filtre_date_du_res   = isset($_GET['date_du']) ? sanitizeInput($_GET['date_du']) : '';
$filtre_date_au_res   = isset($_GET['date_au']) ? sanitizeInput($_GET['date_au']) : '';

if (!$code_param) {
    $where_res  = [
        "le.deleted_at IS NULL",
        "le.statut IN ('preleve','analyse_terminee','validation_technique')",
    ];
    $params_res = [];

    if ($filtre_recherche_res !== '') {
        $where_res[]       = "(lg.code_groupe LIKE :q OR p.nom LIKE :q2 OR p.prenom LIKE :q3 OR a.libelle LIKE :q4)";
        $params_res[':q']  = "%$filtre_recherche_res%";
        $params_res[':q2'] = "%$filtre_recherche_res%";
        $params_res[':q3'] = "%$filtre_recherche_res%";
        $params_res[':q4'] = "%$filtre_recherche_res%";
    }
    if ($filtre_date_du_res !== '') { $where_res[] = "DATE(lg.date_creation) >= :date_du"; $params_res[':date_du'] = $filtre_date_du_res; }
    if ($filtre_date_au_res !== '') { $where_res[] = "DATE(lg.date_creation) <= :date_au"; $params_res[':date_au'] = $filtre_date_au_res; }

    $where_sql = implode(' AND ', $where_res);

    try {
        $stmt = $conn_services->prepare("
            SELECT
                lg.idgroupe, lg.code_groupe, lg.date_creation,
                p.nom AS patient_nom, p.prenom AS patient_prenom,
                MAX(le.urgence) AS urgence,
                COUNT(le.idechantillon) AS nb_examens,
                SUM(le.idresultat IS NOT NULL) AS nb_resultats,
                GROUP_CONCAT(a.libelle ORDER BY le.sous_numero SEPARATOR ' | ') AS examens_liste
            FROM labo_groupes_echantillons lg
            JOIN labo_echantillons le ON le.idgroupe = lg.idgroupe
            JOIN csk_base.patient p ON p.idpatient = lg.idpatient
            LEFT JOIN csk_base.actes_presc ap ON ap.idactes_presc = le.idactes_presc
            LEFT JOIN csk_base.acte a ON a.idacte = ap.idacte
            WHERE $where_sql
            GROUP BY lg.idgroupe
            ORDER BY MAX(le.urgence) DESC, lg.date_creation DESC
        ");
        $stmt->execute($params_res);
        $liste_groupes = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("[Résultats] Liste groupes: " . $e->getMessage());
    }
}
?>

<?php if ($groupe && !empty($sous_eches)): ?>
<!-- ============================================ -->
<!-- VUE SAISIE RÉSULTATS DU GROUPE               -->
<!-- ============================================ -->

<?php
$nb_total    = count($sous_eches);
$nb_saisi    = count(array_filter($sous_eches, fn($s) => $s['idresultat']));
$nb_pret     = count(array_filter($sous_eches, fn($s) => in_array($s['statut'], ['preleve','validation_technique','analyse_terminee'])));
$peut_saisir = in_array($user_profil_code, $profils_saisie);
?>

<div class="mb-3 d-flex gap-2">
    <a href="index.php?page=labo&action=resultats" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Retour à la liste
    </a>
    <a href="index.php?page=labo&action=workflow&code=<?= urlencode($code_param) ?>" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-diagram-3 me-1"></i> Workflow
    </a>
</div>

<!-- En-tête groupe -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex align-items-center justify-content-between" style="background:#d1e7dd;border:none;">
        <div>
            <strong style="font-size:1.05rem;">
                <i class="bi bi-clipboard2-check me-2" style="color:#198754;"></i>
                <?= htmlspecialchars($groupe['code_groupe']) ?>
            </strong>
            <span class="badge ms-2" style="background:#ede8f5;color:#5a3e9e;"><?= $nb_total ?> examen(s)</span>
            <?php if ($nb_saisi > 0): ?>
                <span class="badge bg-success ms-1"><?= $nb_saisi ?> saisi(s)</span>
            <?php endif; ?>
            <?php if ($nb_pret - $nb_saisi > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?= $nb_pret - $nb_saisi ?> à saisir</span>
            <?php endif; ?>
        </div>
        <div class="text-end" style="font-size:.85rem;">
            <div class="fw-semibold"><?= htmlspecialchars(trim($groupe['patient_nom'] . ' ' . $groupe['patient_prenom'])) ?></div>
            <div class="text-muted">
                <?= $sexe_patient ?>
                <?= !empty($groupe['patient_dob']) ? ' âge ' . calculateAge($groupe['patient_dob']) . ' ans' : '' ?>
            </div>
            <?php if (($groupe['type_patient'] ?? 1) > 1): ?>
            <span class="badge mt-1" style="background:#cfe2ff;color:#0a3880;font-size:.7rem;">
                <i class="bi bi-building me-1"></i>Conventionné
            </span>
            <?php else: ?>
            <span class="badge mt-1" style="background:#fff3cd;color:#664d03;font-size:.7rem;">
                <i class="bi bi-person me-1"></i>Privé
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// Peut-on modifier les résultats existants ? (biologiste/admin seulement)
$peut_modifier_tout = in_array($user_profil_code, $profils_modification) && $nb_saisi > 0;
// Mode modification pure : tous les examens prêts ont un résultat saisi
$mode_modification  = $peut_modifier_tout && $nb_saisi >= $nb_pret && $nb_pret > 0;
// Afficher le form si : peut saisir de nouveaux résultats OU peut modifier des existants
$afficher_form      = ($peut_saisir && $nb_pret > $nb_saisi) || $mode_modification;
?>
<?php if ($afficher_form): ?>
<!-- Machine globale -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <div class="row align-items-center g-2">
            <div class="col-auto text-muted" style="font-size:.85rem;"><i class="bi bi-cpu me-1"></i>Machine pour tous :</div>
            <div class="col-md-3">
                <select id="machine_globale_select" class="form-select form-select-sm">
                    <option value="">-- Sélectionner une machine --</option>
                    <?php foreach ($machines as $m): ?>
                    <option value="<?= $m['idmachinelabo'] ?>" <?= $m['statut'] !== 'operationnelle' ? 'disabled' : '' ?>>
                        <?= htmlspecialchars($m['nom'] . ($m['marque'] ? ' - ' . $m['marque'] : '') . ($m['statut'] !== 'operationnelle' ? ' (Hors service)' : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="propaguerMachine()">
                    <i class="bi bi-arrow-down me-1"></i>Appliquer à tous
                </button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="form-resultats-groupe">
    <input type="hidden" name="action_resultats_groupe" value="1">
    <input type="hidden" name="code_groupe" value="<?= htmlspecialchars($groupe['code_groupe']) ?>">
    <input type="hidden" name="machine_globale" id="machine_globale_hidden" value="">
<?php endif; ?>

<?php
$modals_html = []; // Modals d'upload — rendus APRÈS </form> pour éviter les forms imbriqués
?>
<?php foreach ($sous_eches as $idx => $se):
    $tc              = $tube_colors[$se['couleur_tube'] ?? ''] ?? null;
    $si              = $statut_labels[$se['statut'] ?? 'attente_prelevement'] ?? ['label'=>'-','color'=>'#6c757d','bg'=>'#e9ecef'];
    $res_ex          = $se['resultat_existant'];
    $type_patient_se  = $groupe['type_patient'] ?? 'prive';
    $statut_val_se    = $se['ap_statut_validation'] ?? 'rien';
    $mode_paiem_se    = $se['ap_mode_paiement'] ?? 'rien';

    // Paiement validé pour CE sous-examen
    // Dans csk_base : Acte_presc.Statut = 1 (prescrit), 2 (caisse validée), 3 (achevé)
    // IDConvention=1 = privé, >1 = conventionné
    $statut_int      = (int)$se['ap_statut_validation'];
    $type_patient_se = ($groupe['type_patient'] > 1) ? 'conventionne' : 'prive';
    $paiement_valide = ($statut_int >= 2); // caisse validée ou achevé

    // Badge paiement (pour affichage dans l'en-tête de la carte)
    $badge_paiement = '';
    if ($paiement_valide) {
        $label_mode = $statut_int >= 3 ? 'Achevé' : 'Caisse validée';
        $badge_paiement = '<span class="badge ms-1" style="background:#d1e7dd;color:#0a3622;font-size:.72rem;">'
            . '<i class="bi bi-check-circle me-1"></i>' . $label_mode . '</span>';
    } else {
        $label_bloq = ($type_patient_se === 'prive')
            ? 'Paiement requis'
            : 'Validation en attente';
        $badge_paiement = '<span class="badge ms-1" style="background:#f8d7da;color:#842029;font-size:.72rem;">'
            . '<i class="bi bi-lock me-1"></i>' . $label_bloq . '</span>';
    }

    $peut_modif      = $res_ex && in_array($user_profil_code, $profils_modification);
    $peut_saisir_cet = $peut_saisir && !$res_ex
        && in_array($se['statut'], ['preleve','validation_technique','analyse_terminee'])
        && $paiement_valide;
    $nb_rows         = max(4, count($se['valeurs_normales_badges']));
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex align-items-center justify-content-between"
         style="background:<?= $si['bg'] ?>;border:none;">
        <div class="d-flex align-items-center gap-2">
            <?php if ($tc): ?><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?= $tc ?>;"></span><?php endif; ?>
            <strong><?= $se['sous_numero'] ?>. <?= htmlspecialchars($se['examen_libelle'] ?? '—') ?></strong>
            <code style="font-size:.78rem;color:#555;"><?= htmlspecialchars($se['code_echantillon']) ?></code>
            <?php if ($se['urgence']): ?><span class="badge bg-danger">URGENT</span><?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge" style="background:<?= $si['color'] ?>;color:#fff;font-size:.75rem;"><?= htmlspecialchars($si['label']) ?></span>
            <?php if ($res_ex): ?><span class="badge bg-success">Résultat saisi</span><?php endif; ?>
            <?= $badge_paiement ?>
        </div>
    </div>
    <div class="card-body">

        <?php
        $statut_bloque = !in_array($se['statut'], ['preleve','validation_technique','analyse_terminee']);
        ?>
        <?php if ($statut_bloque && !$res_ex): ?>
        <div class="alert alert-secondary py-2 mb-0" style="font-size:.85rem;">
            <i class="bi bi-hourglass-split me-1"></i>
            Statut <strong><?= htmlspecialchars($si['label']) ?></strong> — Avancez le workflow avant de saisir un résultat.
        </div>

        <?php elseif (!$paiement_valide && !$res_ex): ?>
        <div class="alert alert-warning py-2 mb-0" style="font-size:.85rem;border-left:4px solid #ffc107;">
            <?php if ($type_patient_se === 'prive'): ?>
            <i class="bi bi-cash-coin me-1"></i>
            <strong>Patient privé — Paiement en attente de validation.</strong>
            La réception doit d'abord valider le paiement à la caisse dans le <a href="index.php?page=labo&action=workflow&code=<?= urlencode($groupe['code_groupe']) ?>">workflow</a>.
            <?php else: ?>
            <i class="bi bi-building me-1"></i>
            <strong>Patient conventionné — Prise en charge en attente.</strong>
            La réception doit valider la prise en charge dans le <a href="index.php?page=labo&action=workflow&code=<?= urlencode($groupe['code_groupe']) ?>">workflow</a>.
            <?php endif; ?>
        </div>

        <?php elseif ($peut_saisir_cet || $peut_modif): ?>

        <?php if (!empty($se['valeurs_normales_badges']) && !$res_ex): ?>
        <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.82rem;border-left:4px solid #0d6efd;">
            <div class="fw-semibold mb-1">
                <i class="bi bi-magic me-1"></i>Valeurs de référence — <?= strtoupper($se['valeurs_normales_badges'][0]['tag'] ?? 'H') ?> · <?= $age_patient ?> ans
            </div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($se['valeurs_normales_badges'] as $b): ?>
                <span class="badge bg-white text-dark border" style="font-size:.75rem;">
                    <?= htmlspecialchars($b['parametre']) ?>
                    <?php if ($b['min'] !== null && $b['max'] !== null): ?>
                        : <?= $b['min'] ?>–<?= $b['max'] ?><?= $b['unite'] ? ' ' . $b['unite'] : '' ?>
                        <span class="text-muted">(<?= $b['tag'] ?>)</span>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Résultat <span class="text-danger">*</span></label>
                <textarea name="resultat_<?= $se['idechantillon'] ?>"
                          class="form-control font-monospace" rows="<?= $nb_rows ?>"
                          placeholder="Saisir le résultat..."
                ><?= htmlspecialchars($res_ex['resultat'] ?? $se['prefill_resultat']) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Valeur(s) normale(s)</label>
                <textarea name="valeur_normale_<?= $se['idechantillon'] ?>"
                          class="form-control" rows="<?= $nb_rows ?>"
                          placeholder="Valeurs de référence..."
                ><?= htmlspecialchars($res_ex['valeur_normale'] ?? $se['prefill_valeur_normale']) ?></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Interprétation</label>
                <select name="interpretation_<?= $se['idechantillon'] ?>" class="form-select">
                    <option value="">-- Sélectionner --</option>
                    <?php foreach (['normal' => '✅ Normal', 'anormal' => '⚠️ Anormal', 'critique' => '🔴 Critique'] as $v => $l): ?>
                    <option value="<?= $v ?>" <?= ($res_ex['interpretation'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Machine utilisée</label>
                <select name="machine_<?= $se['idechantillon'] ?>" class="machine-individuelle form-select">
                    <option value="">-- Aucune --</option>
                    <?php foreach ($machines as $m): ?>
                    <option value="<?= $m['idmachinelabo'] ?>"
                            <?= ($res_ex['idmachinelabo'] ?? '') == $m['idmachinelabo'] ? 'selected' : '' ?>
                            <?= $m['statut'] !== 'operationnelle' ? 'disabled' : '' ?>>
                        <?= htmlspecialchars($m['nom'] . ($m['statut'] !== 'operationnelle' ? ' (HS)' : '')) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Observations</label>
                <textarea name="observations_<?= $se['idechantillon'] ?>"
                          class="form-control" rows="2"
                          placeholder="Remarques, commentaires..."
                ><?= htmlspecialchars($res_ex['observations'] ?? '') ?></textarea>
            </div>
        </div>

        <?php elseif ($res_ex): ?>
        <!-- Lecture seule -->
        <div class="row g-3" style="font-size:0.9rem;">
            <div class="col-md-6">
                <strong class="text-muted d-block mb-1">Résultat</strong>
                <div class="p-3 rounded" style="background:#f8f9fa;white-space:pre-wrap;"><?= htmlspecialchars($res_ex['resultat']) ?></div>
            </div>
            <?php if ($res_ex['valeur_normale']): ?>
            <div class="col-md-6">
                <strong class="text-muted d-block mb-1">Valeur(s) normale(s)</strong>
                <div class="p-2 rounded" style="background:#f8f9fa;white-space:pre-wrap;"><?= htmlspecialchars($res_ex['valeur_normale']) ?></div>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <strong class="text-muted d-block mb-1">Interprétation</strong>
                <?php $ic = match($res_ex['interpretation'] ?? '') {
                    'normal'=>'#198754','anormal'=>'#fd7e14','critique'=>'#dc3545',default=>'#6c757d'
                }; ?>
                <span style="color:<?= $ic ?>;font-weight:700;font-size:1rem;"><?= htmlspecialchars(ucfirst($res_ex['interpretation'] ?? '-')) ?></span>
            </div>
            <div class="col-md-4">
                <strong class="text-muted d-block mb-1">Machine</strong>
                <?= htmlspecialchars($res_ex['machine_nom'] ?? '-') ?>
            </div>
            <div class="col-md-4">
                <strong class="text-muted d-block mb-1">Analysé par</strong>
                <?= htmlspecialchars(trim(($res_ex['analyste_prenom'] ?? '') . ' ' . ($res_ex['analyste_nom'] ?? '-'))) ?>
                <div class="text-muted" style="font-size:.78rem;"><?= formatDateTime($res_ex['date_analyse'] ?? '', 'd/m/Y H:i') ?></div>
            </div>
            <?php if ($res_ex['observations']): ?>
            <div class="col-12">
                <strong class="text-muted d-block mb-1">Observations</strong>
                <em><?= htmlspecialchars($res_ex['observations']) ?></em>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="text-center py-3 text-muted">
            <i class="bi bi-lock me-1"></i>
            Votre profil ne permet pas la saisie, ou l'examen n'est pas encore prêt.
        </div>
        <?php endif; ?>

        <!-- Documents joints -->
        <?php if (!empty($se['documents']) || (($peut_saisir_cet || $peut_modif) && $res_ex)): ?>
        <div class="mt-3 border-top pt-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <strong style="font-size:.85rem;"><i class="bi bi-paperclip me-1"></i>Documents joints</strong>
                <?php if (($peut_saisir_cet || $peut_modif) && $res_ex): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal" data-bs-target="#uploadModal_<?= $se['idechantillon'] ?>">
                    <i class="bi bi-upload me-1"></i>Ajouter
                </button>
                <?php endif; ?>
            </div>
            <?php if (empty($se['documents'])): ?>
                <p class="text-muted mb-0" style="font-size:.82rem;">Aucun document joint</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                <?php foreach ($se['documents'] as $doc): ?>
                    <div class="list-group-item py-2 px-0 d-flex align-items-center justify-content-between" style="font-size:.82rem;">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-file-pdf text-danger fs-5 me-2"></i>
                            <div>
                                <div class="fw-semibold"><?= htmlspecialchars($doc['fichier_original']) ?></div>
                                <small class="text-muted">
                                    <?= date('d/m/Y H:i', strtotime($doc['date_upload'])) ?>
                                    <?php if ($doc['description']): ?> — <?= htmlspecialchars($doc['description']) ?><?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // Stocker le modal HORS du form principal (les modals sont rendus après </form>)
        if (($peut_saisir_cet || $peut_modif) && $res_ex):
            ob_start();
        ?>
        <div class="modal fade" id="uploadModal_<?= $se['idechantillon'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" enctype="multipart/form-data"
                          action="index.php?page=labo&action=resultats&code=<?= urlencode($groupe['code_groupe']) ?>">
                        <input type="hidden" name="action_upload_document" value="1">
                        <input type="hidden" name="idresultat" value="<?= $res_ex['idresultat'] ?>">
                        <input type="hidden" name="code_groupe" value="<?= htmlspecialchars($groupe['code_groupe']) ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-paperclip me-2"></i>
                                <?= htmlspecialchars($se['examen_libelle'] ?? $se['code_echantillon']) ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info py-2" style="font-size:.82rem;">
                                <i class="bi bi-info-circle me-1"></i>
                                Le document sera ajouté au PDF existant sans modifier les résultats déjà saisis.
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Fichier PDF <span class="text-danger">*</span></label>
                                <input type="file" name="document_file" class="form-control" accept=".pdf,application/pdf" required>
                                <small class="text-muted">Format PDF uniquement, max 10 Mo</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Description (optionnel)</label>
                                <textarea name="description_document" class="form-control" rows="2"
                                          placeholder="Ex: Courbe de calibration, image annexe..."></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload me-1"></i>Ajouter le document
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
            $modals_html[] = ob_get_clean();
        endif;
        ?>
        <?php endif; ?>

    </div>
</div>
<?php endforeach; ?>

<?php if ($afficher_form): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body d-flex align-items-center gap-3">
        <?php if ($mode_modification): ?>
        <button type="submit" class="btn btn-warning btn-lg"
                onclick="return confirm('Modifier les résultats et re-notifier le prescripteur ?')">
            <i class="bi bi-pencil-square me-2"></i>Enregistrer les modifications
        </button>
        <?php elseif ($nb_pret > $nb_saisi): ?>
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-lg me-2"></i>Enregistrer tous les résultats
        </button>
        <?php else: ?>
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-check-lg me-2"></i>Enregistrer
        </button>
        <?php endif; ?>
        <a href="index.php?page=labo&action=resultats" class="btn btn-outline-secondary">Annuler</a>
        <small class="text-muted ms-auto">
            <i class="bi bi-bell me-1"></i>
            <?= $mode_modification ? 'Le prescripteur sera re-notifié de la modification.' : 'Le prescripteur sera notifié.' ?>
        </small>
    </div>
</div>
</form>

<?php
// ── Modals upload — HORS du form principal (HTML valide, enctype multipart fonctionnel) ──
foreach ($modals_html as $_modal) echo $_modal;
?>

<?php endif; ?>

<?php elseif ($code_param && !$groupe): ?>
<div class="alert alert-warning">
    Groupe <strong><?= htmlspecialchars($code_param) ?></strong> introuvable.
    <a href="index.php?page=labo&action=resultats">Retour</a>
</div>

<?php else: ?>
<!-- ============================================ -->
<!-- VUE LISTE DES GROUPES                        -->
<!-- ============================================ -->

<form method="GET" class="card border-0 shadow-sm mb-4">
    <input type="hidden" name="page" value="labo">
    <input type="hidden" name="action" value="resultats">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" style="font-size:0.8rem;">Recherche</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Code groupe, patient, examen..."
                       value="<?= htmlspecialchars($filtre_recherche_res) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:0.8rem;">Du</label>
                <input type="date" name="date_du" class="form-control form-control-sm" value="<?= htmlspecialchars($filtre_date_du_res) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:0.8rem;">Au</label>
                <input type="date" name="date_au" class="form-control form-control-sm" value="<?= htmlspecialchars($filtre_date_au_res) ?>">
            </div>
            <div class="col-md-3 d-flex gap-1">
                <button type="submit" class="btn btn-sm w-100" style="background:#198754;color:#fff;">
                    <i class="bi bi-search"></i> Filtrer
                </button>
                <a href="index.php?page=labo&action=resultats" class="btn btn-sm btn-outline-secondary" title="Reset">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </div>
</form>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div style="font-size:.9rem;"><strong><?= count($liste_groupes) ?></strong> groupe(s) en attente de résultats</div>
    <a href="index.php?page=labo&action=echantillons" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-list-ul me-1"></i>Tous les groupes
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.83rem;">
                <thead class="table-light">
                    <tr>
                        <th>Code groupe</th>
                        <th>Patient</th>
                        <th>Examens</th>
                        <th>Progression</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($liste_groupes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">
                        <i class="bi bi-clipboard2-check fs-2 d-block mb-2"></i>Aucun groupe en attente de résultats.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($liste_groupes as $lg):
                    $nb_ex  = (int)$lg['nb_examens'];
                    $nb_res = (int)$lg['nb_resultats'];
                    $pct    = $nb_ex > 0 ? round($nb_res / $nb_ex * 100) : 0;
                ?>
                <tr class="<?= $lg['urgence'] ? 'border-start border-3 border-danger' : '' ?>">
                    <td>
                        <?php if ($lg['urgence']): ?><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i><?php endif; ?>
                        <code style="font-weight:700;color:#198754;"><?= htmlspecialchars($lg['code_groupe']) ?></code>
                    </td>
                    <td><?= htmlspecialchars(trim($lg['patient_nom'] . ' ' . $lg['patient_prenom'])) ?></td>
                    <td style="max-width:200px;" class="text-truncate" title="<?= htmlspecialchars($lg['examens_liste'] ?? '') ?>">
                        <?= htmlspecialchars(mb_strimwidth($lg['examens_liste'] ?? '', 0, 60, '...')) ?>
                    </td>
                    <td style="min-width:100px;">
                        <div style="font-size:.72rem;color:#6c757d;margin-bottom:3px;"><?= $nb_res ?>/<?= $nb_ex ?> résultat(s)</div>
                        <div class="progress" style="height:5px;">
                            <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $pct ?>%;"></div>
                        </div>
                    </td>
                    <td class="text-muted"><?= formatDateTime($lg['date_creation'], 'd/m/Y H:i') ?></td>
                    <td>
                        <a href="index.php?page=labo&action=resultats&code=<?= urlencode($lg['code_groupe']) ?>"
                           class="btn btn-sm <?= $nb_res > 0 ? 'btn-outline-primary' : 'btn-success' ?>">
                            <i class="bi bi-<?= $nb_res > 0 ? 'eye' : 'pencil-square' ?> me-1"></i>
                            <?= $nb_res > 0 ? 'Voir / compléter' : 'Saisir résultats' ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function propaguerMachine() {
    var val = document.getElementById('machine_globale_select').value;
    document.getElementById('machine_globale_hidden').value = val;
    document.querySelectorAll('.machine-individuelle').forEach(function(sel) {
        if (!sel.disabled) sel.value = val;
    });
}
</script>