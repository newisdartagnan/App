<?php
/**
 * Générateur PDF – Résultat laboratoire (accès public via token)
 *
 * MODES :
 *  • Token individuel  (code_echantillon = LAB-YYYYMMDD-XXXX-NN)
 *      → 1 examen, comportement original
 *  • Token groupe      (code_echantillon = LAB-YYYYMMDD-XXXX, idresultat NULL)
 *      → TOUS les sous-examens du groupe sur UNE seule page PDF
 */

$root_path = dirname(__DIR__);
require_once $root_path . '/config/database.php';

$vendor_autoload = $root_path . '/vendor/autoload.php';
if (!file_exists($vendor_autoload)) {
    $token_param = htmlspecialchars($_GET['token'] ?? '');
    die('<div style="font-family:Arial;padding:20px;background:#f8d7da;color:#721c24;border-radius:5px;margin:20px;">
        <h3>📦 TCPDF non installé</h3>
        <p>Installez TCPDF : <code>composer require tecnickcom/tcpdf</code></p>
        <a href="javascript:window.print()" style="background:#0056b3;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px;">🖨️ Imprimer</a>
        <a href="resultats.php?token=' . $token_param . '" style="background:#6c757d;color:#fff;padding:8px 16px;text-decoration:none;border-radius:4px;margin-left:8px;">⬅️ Retour</a>
    </div>');
}

require_once $vendor_autoload;
if (!class_exists('TCPDF')) {
    $p = $root_path . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    file_exists($p) ? require_once $p : die('TCPDF non trouvé');
}

use setasign\Fpdi\Tcpdf\Fpdi;

$db            = new Database();
$conn_base     = $db->getBaseConnection();
$conn_services = $db->getServicesConnection();

$token = $_GET['token'] ?? '';
if (empty($token)) { die('Token manquant'); }

// ═══════════════════════════════════════════════════════════════════════════
// 1. LIRE LE TOKEN
//    Ordre de recherche :
//      A) labo_groupes_tokens   → token de groupe (code_groupe, sans FK)
//      B) labo_resultats_tokens → token individuel (code_echantillon + idresultat)
// ═══════════════════════════════════════════════════════════════════════════
$est_groupe = false;
$tok        = null;

try {
    // ── A. Chercher dans labo_groupes_tokens ──
    $stmt = $conn_services->prepare("
        SELECT token, code_groupe AS code_echantillon, NULL AS idresultat, email_destinataire
        FROM labo_groupes_tokens
        WHERE token = :token
          AND actif = 1
          AND (date_expiration IS NULL OR date_expiration > NOW())
    ");
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();

    if ($row) {
        $tok        = $row;
        $est_groupe = true;
        // Marquer consulté
        $conn_services->prepare("
            UPDATE labo_groupes_tokens
            SET vu_le = NOW(), nb_consultations = nb_consultations + 1
            WHERE token = :token
        ")->execute([':token' => $token]);
    } else {
        // ── B. Chercher dans labo_resultats_tokens (token individuel) ──
        $stmt = $conn_services->prepare("
            SELECT token, code_echantillon, idresultat, email_destinataire
            FROM labo_resultats_tokens
            WHERE token = :token
              AND actif = 1
              AND (date_expiration IS NULL OR date_expiration > NOW())
        ");
        $stmt->execute([':token' => $token]);
        $tok = $stmt->fetch();

        if (!$tok) { die('Lien invalide ou expiré'); }

        // Marquer consulté
        $conn_services->prepare("
            UPDATE labo_resultats_tokens
            SET vu_le = NOW(), nb_consultations = nb_consultations + 1
            WHERE token = :token
        ")->execute([':token' => $token]);
    }

} catch (Exception $e) {
    die('Erreur technique : ' . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. DÉTECTER : TOKEN GROUPE ou TOKEN INDIVIDUEL ?
//    Groupe    → $est_groupe = true  (trouvé dans labo_groupes_tokens)
//    Individuel → code avec suffixe -NN dans labo_resultats_tokens
// ═══════════════════════════════════════════════════════════════════════════
$code_ref = $tok['code_echantillon'];

$info_patient     = null; // données communes patient/prescripteur
$sous_examens_pdf = []; // liste de sous-examens à afficher
$numero_bon       = null;
$documents_annexes = [];
$premier_analyste = '';
$premiere_date    = '';
$premiere_machine = '';

if ($est_groupe) {
    // ── MODE GROUPE ──────────────────────────────────────────────────────
    try {
        // Info groupe + patient
        $stmt = $conn_services->prepare("
            SELECT
                lg.idgroupe, lg.code_groupe,
                p.nom            AS patient_nom,
                p.prenom         AS patient_prenom,
                p.sexe,
                p.date_naissance,
                p.idsociete,
                soc.nom          AS societe_nom,
                soc.sigle        AS societe_sigle,
                TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) AS age
            FROM labo_groupes_echantillons lg
            JOIN csk_base.patient  p   ON p.idpatient  = lg.idpatient
            LEFT JOIN csk_base.societe soc ON soc.idsociete = p.idsociete
            WHERE lg.code_groupe = :code
        ");
        $stmt->execute([':code' => $code_ref]);
        $grp = $stmt->fetch();
        if (!$grp) { die('Groupe introuvable'); }

        // Prescripteur : on prend le premier acte_presc du groupe
        $stmt_presc = $conn_services->prepare("
            SELECT CONCAT(u.prenom,' ',u.nom) AS prescripteur_nom
            FROM labo_echantillons le
            JOIN csk_base.actes_presc ap ON ap.idactes_presc = le.idactes_presc
            LEFT JOIN csk_base.utilisateur u ON u.idutilisateur = ap.prescripteur
            WHERE le.idgroupe = :id AND le.deleted_at IS NULL
            ORDER BY le.sous_numero ASC
            LIMIT 1
        ");
        $stmt_presc->execute([':id' => $grp['idgroupe']]);
        $prescripteur_nom = $stmt_presc->fetchColumn() ?: '—';

        $info_patient = [
            'patient_nom'      => $grp['patient_nom'],
            'patient_prenom'   => $grp['patient_prenom'],
            'sexe'             => $grp['sexe'],
            'age'              => $grp['age'],
            'societe_nom'      => $grp['societe_nom'],
            'societe_sigle'    => $grp['societe_sigle'],
            'prescripteur_nom' => $prescripteur_nom,
            'code_ref'         => $code_ref,
        ];

        // ── Numéro de bon GROUPE (idempotent, même compteur que les bons individuels) ──
        // Stratégie :
        //   1. Chercher si un des resultatslabo du groupe a déjà un numero_bon → réutiliser
        //   2. Sinon : incrémenter labo_numerotation_bons et stocker dans tous les resultatslabo du groupe
        // Aucun changement de schéma nécessaire (colonne numero_bon existe déjà dans resultatslabo)
        try {
            $conn_base->exec("CREATE TABLE IF NOT EXISTS labo_numerotation_bons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                annee YEAR NOT NULL,
                dernier_numero INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uk_annee (annee)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Chercher un numéro déjà assigné à ce groupe
            $sc = $conn_base->prepare("
                SELECT r.numero_bon
                FROM resultatslabo r
                JOIN csk_base.actes_presc ap ON ap.idactes_presc = r.idactes_presc
                JOIN csk_services.labo_echantillons le ON le.idactes_presc = ap.idactes_presc
                JOIN csk_services.labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
                WHERE lg.code_groupe = :code
                  AND r.numero_bon IS NOT NULL AND r.numero_bon != ''
                LIMIT 1
            ");
            $sc->execute([':code' => $code_ref]);
            $existing = $sc->fetchColumn();

            if ($existing) {
                $numero_bon = $existing; // déjà assigné → réutiliser
            } else {
                $annee = (int)date('Y');
                $conn_base->beginTransaction();

                // Incrémenter le compteur annuel
                $conn_base->prepare("
                    INSERT INTO labo_numerotation_bons (annee, dernier_numero)
                    VALUES (:a, 1)
                    ON DUPLICATE KEY UPDATE dernier_numero = dernier_numero + 1
                ")->execute([':a' => $annee]);

                $sn = $conn_base->prepare("SELECT dernier_numero FROM labo_numerotation_bons WHERE annee = :a");
                $sn->execute([':a' => $annee]);
                $seq = (int)$sn->fetchColumn();

                $numero_bon = 'LAB-' . $annee . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                // Stocker dans tous les resultatslabo du groupe
                $conn_base->prepare("
                    UPDATE resultatslabo r
                    JOIN csk_base.actes_presc ap ON ap.idactes_presc = r.idactes_presc
                    JOIN csk_services.labo_echantillons le ON le.idactes_presc = ap.idactes_presc
                    JOIN csk_services.labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
                    SET r.numero_bon = :nb
                    WHERE lg.code_groupe = :code
                ")->execute([':nb' => $numero_bon, ':code' => $code_ref]);

                $conn_base->commit();
                error_log("[PDF-GROUPE] N° bon attribué : $numero_bon → groupe $code_ref");
            }
        } catch (Exception $e) {
            if ($conn_base->inTransaction()) $conn_base->rollBack();
            error_log("[PDF-GROUPE] Numerotation échouée : " . $e->getMessage());
            $numero_bon = $code_ref; // fallback
        }

        // Charger TOUS les sous-examens avec résultats
        $stmt_se = $conn_services->prepare("
            SELECT
                le.idechantillon, le.sous_numero, le.code_echantillon, le.idresultat,
                a.libelle  AS acte_libelle
            FROM labo_echantillons le
            LEFT JOIN csk_base.actes_presc ap ON ap.idactes_presc = le.idactes_presc
            LEFT JOIN csk_base.acte         a  ON a.idacte         = ap.idacte
            WHERE le.idgroupe = :id AND le.deleted_at IS NULL
            ORDER BY le.sous_numero ASC
        ");
        $stmt_se->execute([':id' => $grp['idgroupe']]);
        $rows_se = $stmt_se->fetchAll();

        foreach ($rows_se as $se) {
            // Ligne d'en-tête examen
            $sous_examens_pdf[] = [
                'libelle'        => $se['acte_libelle'] ?: $se['code_echantillon'],
                'valeur_resultat'=> '',
                'valeur_normale' => '',
                'interpretation' => '',
                'is_header'      => true,
            ];

            if (empty($se['idresultat'])) {
                // Pas encore de résultat
                $sous_examens_pdf[] = [
                    'libelle'        => '  En attente de résultat',
                    'valeur_resultat'=> '—',
                    'valeur_normale' => '',
                    'interpretation' => '',
                    'is_header'      => false,
                ];
                continue;
            }

            // Résultat de cet échantillon
            $stmt_r = $conn_base->prepare("
                SELECT r.resultat, r.valeur_normale, r.interpretation,
                       r.date_analyse, r.observations,
                       CONCAT(u.prenom,' ',u.nom) AS analyste_nom,
                       m.nom AS machine_nom
                FROM resultatslabo r
                LEFT JOIN utilisateur  u ON u.idutilisateur = r.analyse_par
                LEFT JOIN machineslabo m ON m.idmachinelabo = r.idmachinelabo
                WHERE r.idresultat = :id
            ");
            $stmt_r->execute([':id' => $se['idresultat']]);
            $res = $stmt_r->fetch();

            if (!$res) {
                $sous_examens_pdf[] = [
                    'libelle'        => '  Résultat introuvable',
                    'valeur_resultat'=> '—',
                    'valeur_normale' => '',
                    'interpretation' => '',
                    'is_header'      => false,
                ];
                continue;
            }

            // Mémoriser le premier analyste/date/machine pour le pied de page
            if (empty($premier_analyste) && !empty($res['analyste_nom'])) $premier_analyste = $res['analyste_nom'];
            if (empty($premiere_date)    && !empty($res['date_analyse']))  $premiere_date    = $res['date_analyse'];
            if (empty($premiere_machine) && !empty($res['machine_nom']))   $premiere_machine = $res['machine_nom'];

            // Essayer les lignes détaillées (resultatslabo_lignes)
            $lignes = [];
            try {
                $sl = $conn_base->prepare("
                    SELECT libelle_examen, valeur_resultat, valeur_normale, interpretation
                    FROM resultatslabo_lignes WHERE idresultat = ? ORDER BY ordre ASC
                ");
                $sl->execute([$se['idresultat']]);
                $lignes = $sl->fetchAll();
            } catch (Exception $e) { $lignes = []; }

            if (!empty($lignes)) {
                foreach ($lignes as $l) {
                    $sous_examens_pdf[] = [
                        'libelle'        => '  ' . ($l['libelle_examen'] ?? ''),
                        'valeur_resultat'=> $l['valeur_resultat'] ?? '',
                        'valeur_normale' => $l['valeur_normale']  ?? '',
                        'interpretation' => $l['interpretation']  ?? '',
                        'is_header'      => false,
                    ];
                }
            } else {
                // Résultat brut (texte multilignes)
                $lignes_brutes = explode("\n", trim($res['resultat'] ?? ''));
                $vn_brutes     = explode("\n", trim($res['valeur_normale'] ?? ''));
                $nb = max(count($lignes_brutes), 1);
                for ($i = 0; $i < $nb; $i++) {
                    $sous_examens_pdf[] = [
                        'libelle'        => '  ' . trim($lignes_brutes[$i] ?? ''),
                        'valeur_resultat'=> trim($lignes_brutes[$i] ?? ''),
                        'valeur_normale' => trim($vn_brutes[$i] ?? ''),
                        'interpretation' => $i === 0 ? ($res['interpretation'] ?? '') : '',
                        'is_header'      => false,
                    ];
                }
            }

            // Observations de cet examen
            if (!empty($res['observations'])) {
                $sous_examens_pdf[] = [
                    'libelle'        => '  Observations : ' . $res['observations'],
                    'valeur_resultat'=> '',
                    'valeur_normale' => '',
                    'interpretation' => '',
                    'is_header'      => false,
                ];
            }

            // Documents annexes de cet examen
            try {
                $sd = $conn_base->prepare(
                    "SELECT chemin_fichier, fichier_original FROM resultatslabo_documents
                     WHERE idresultat = ? AND actif = 1 ORDER BY date_upload ASC"
                );
                $sd->execute([$se['idresultat']]);
                foreach ($sd->fetchAll() as $doc) {
                    if (file_exists($doc['chemin_fichier'])) {
                        $documents_annexes[] = $doc;
                    }
                }
            } catch (Exception $e) {}
        }

    } catch (Exception $e) {
        error_log('[PDF-GROUPE] ' . $e->getMessage());
        die('Erreur chargement groupe : ' . $e->getMessage());
    }

} else {
    // ── MODE INDIVIDUEL (comportement original) ──────────────────────────
    try {
        $stmt = $conn_services->prepare("
            SELECT
                lr.token, lr.code_echantillon, lr.idresultat,
                le.idechantillon, le.idactes_presc, le.couleur_tube,
                p.nom AS patient_nom, p.prenom AS patient_prenom,
                p.sexe, p.date_naissance, p.idsociete,
                s.nom AS societe_nom, s.sigle AS societe_sigle,
                TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) AS age,
                a.libelle AS examen_libelle, a.code AS examen_code,
                CONCAT(u_presc.prenom,' ',u_presc.nom) AS prescripteur_nom
            FROM labo_resultats_tokens lr
            JOIN labo_echantillons le ON lr.code_echantillon = le.code_echantillon
            LEFT JOIN csk_base.patient     p       ON le.idpatient    = p.idpatient
            LEFT JOIN csk_base.societe     s       ON p.idsociete     = s.idsociete
            LEFT JOIN csk_base.actes_presc ap      ON le.idactes_presc= ap.idactes_presc
            LEFT JOIN csk_base.acte        a       ON ap.idacte       = a.idacte
            LEFT JOIN csk_base.utilisateur u_presc ON ap.prescripteur = u_presc.idutilisateur
            WHERE lr.token = :token
        ");
        $stmt->execute([':token' => $token]);
        $info = $stmt->fetch();
        if (!$info) { die('Lien invalide ou expiré'); }

        $stmt_res = $conn_base->prepare("
            SELECT r.*,
                   CONCAT(u.prenom,' ',u.nom) AS analyste_nom,
                   m.nom AS machine_nom
            FROM resultatslabo r
            LEFT JOIN utilisateur  u ON r.analyse_par   = u.idutilisateur
            LEFT JOIN machineslabo m ON r.idmachinelabo = m.idmachinelabo
            WHERE r.idresultat = :id
        ");
        $stmt_res->execute([':id' => $info['idresultat']]);
        $resultat_principal = $stmt_res->fetch();
        if (!$resultat_principal) { die('Résultat non trouvé'); }

        $premier_analyste = $resultat_principal['analyste_nom'] ?? '';
        $premiere_date    = $resultat_principal['date_analyse'] ?? '';
        $premiere_machine = $resultat_principal['machine_nom']  ?? '';

        // Documents
        try {
            $sd = $conn_base->prepare(
                "SELECT chemin_fichier, fichier_original FROM resultatslabo_documents
                 WHERE idresultat = ? AND actif = 1 ORDER BY date_upload ASC"
            );
            $sd->execute([$info['idresultat']]);
            foreach ($sd->fetchAll() as $doc) {
                if (file_exists($doc['chemin_fichier'])) $documents_annexes[] = $doc;
            }
        } catch (Exception $e) {}

        $info_patient = [
            'patient_nom'      => $info['patient_nom'],
            'patient_prenom'   => $info['patient_prenom'],
            'sexe'             => $info['sexe'],
            'age'              => $info['age'],
            'societe_nom'      => $info['societe_nom'],
            'societe_sigle'    => $info['societe_sigle'],
            'prescripteur_nom' => $info['prescripteur_nom'],
            'code_ref'         => $code_ref,
        ];

        // Examens réels – stratégie A → B → C (identique à l'original)
        $examens_reels = [];
        if (!empty($info['idechantillon'])) {
            try {
                $stmt_actes = $conn_services->prepare("
                    SELECT lea.idresultat, lea.ordre,
                           a.libelle AS examen_libelle, a.code AS examen_code,
                           r.resultat, r.valeur_normale, r.interpretation,
                           r.date_analyse, r.observations,
                           CONCAT(u.prenom,' ',u.nom) AS analyste_nom,
                           m.nom AS machine_nom
                    FROM labo_echantillon_actes lea
                    JOIN csk_base.actes_presc  ap ON lea.idactes_presc= ap.idactes_presc
                    JOIN csk_base.acte         a  ON ap.idacte        = a.idacte
                    LEFT JOIN csk_base.resultatslabo r ON lea.idresultat   = r.idresultat
                    LEFT JOIN csk_base.utilisateur   u ON r.analyse_par   = u.idutilisateur
                    LEFT JOIN csk_base.machineslabo  m ON r.idmachinelabo = m.idmachinelabo
                    WHERE lea.idechantillon = :id ORDER BY lea.ordre ASC
                ");
                $stmt_actes->execute([':id' => $info['idechantillon']]);
                foreach ($stmt_actes->fetchAll() as $row) {
                    $examens_reels[] = ['libelle'=>$row['examen_libelle'].($row['examen_code']?' ('.$row['examen_code'].')':''),'valeur_resultat'=>'','valeur_normale'=>'','interpretation'=>'','is_header'=>true];
                    $lignes = [];
                    if (!empty($row['idresultat'])) {
                        try {
                            $s = $conn_base->prepare("SELECT libelle_examen,valeur_resultat,valeur_normale,interpretation FROM resultatslabo_lignes WHERE idresultat=? ORDER BY ordre ASC");
                            $s->execute([$row['idresultat']]); $lignes = $s->fetchAll();
                        } catch (Exception $e) {}
                    }
                    if (!empty($lignes)) {
                        foreach ($lignes as $l) $examens_reels[] = ['libelle'=>$l['libelle_examen'],'valeur_resultat'=>$l['valeur_resultat']??'','valeur_normale'=>$l['valeur_normale']??'','interpretation'=>$l['interpretation']??'','is_header'=>false];
                    } elseif (!empty($row['resultat'])) {
                        $examens_reels[] = ['libelle'=>'  Résultat','valeur_resultat'=>$row['resultat'],'valeur_normale'=>$row['valeur_normale']??'','interpretation'=>$row['interpretation']??'','is_header'=>false];
                    }
                }
            } catch (Exception $e) { error_log('[PDF] Strat A : '.$e->getMessage()); }
        }
        if (empty($examens_reels)) {
            try {
                $s = $conn_base->prepare("SELECT libelle_examen,valeur_resultat,valeur_normale,interpretation FROM resultatslabo_lignes WHERE idresultat=? ORDER BY ordre ASC");
                $s->execute([$info['idresultat']]);
                foreach ($s->fetchAll() as $l) $examens_reels[] = ['libelle'=>$l['libelle_examen'],'valeur_resultat'=>$l['valeur_resultat']??'','valeur_normale'=>$l['valeur_normale']??'','interpretation'=>$l['interpretation']??'','is_header'=>false];
            } catch (Exception $e) {}
        }
        if (empty($examens_reels)) {
            $examens_reels[] = ['libelle'=>$info['examen_libelle'],'valeur_resultat'=>$resultat_principal['resultat']??'','valeur_normale'=>$resultat_principal['valeur_normale']??'','interpretation'=>$resultat_principal['interpretation']??'','is_header'=>false];
        }
        $sous_examens_pdf = $examens_reels;

        // Numéro de bon (idempotent)
        try {
            $conn_base->exec("CREATE TABLE IF NOT EXISTS labo_numerotation_bons (id INT AUTO_INCREMENT PRIMARY KEY, annee YEAR NOT NULL, dernier_numero INT UNSIGNED NOT NULL DEFAULT 0, UNIQUE KEY uk_annee (annee)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $sc = $conn_base->prepare("SELECT numero_bon FROM resultatslabo WHERE idresultat=:id AND numero_bon IS NOT NULL AND numero_bon!=''");
            $sc->execute([':id'=>$info['idresultat']]);
            $existing = $sc->fetchColumn();
            if ($existing) {
                $numero_bon = $existing;
            } else {
                $annee = (int)date('Y');
                $conn_base->beginTransaction();
                $conn_base->prepare("INSERT INTO labo_numerotation_bons (annee,dernier_numero) VALUES (:a,1) ON DUPLICATE KEY UPDATE dernier_numero=dernier_numero+1")->execute([':a'=>$annee]);
                $sn = $conn_base->prepare("SELECT dernier_numero FROM labo_numerotation_bons WHERE annee=:a");
                $sn->execute([':a'=>$annee]);
                $seq = (int)$sn->fetchColumn();
                $numero_bon = 'LAB-'.$annee.'-'.str_pad($seq,4,'0',STR_PAD_LEFT);
                $conn_base->prepare("UPDATE resultatslabo SET numero_bon=:nb WHERE idresultat=:id")->execute([':nb'=>$numero_bon,':id'=>$info['idresultat']]);
                $conn_base->commit();
            }
        } catch (Exception $e) {
            if ($conn_base->inTransaction()) $conn_base->rollBack();
            $numero_bon = $code_ref;
        }

    } catch (Exception $e) {
        die('Erreur chargement résultat : ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. GÉNÉRATION DU PDF (commun aux deux modes)
// ═══════════════════════════════════════════════════════════════════════════
$societe_nom_complet = $info_patient['societe_nom'] ?? '';
if ($societe_nom_complet && !empty($info_patient['societe_sigle'])) {
    $societe_nom_complet .= ' (' . $info_patient['societe_sigle'] . ')';
}

try {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('CSK – Laboratoire');
    $pdf->SetAuthor('Laboratoire CSK');
    $pdf->SetTitle('Résultat ' . $numero_bon);
    $pdf->SetMargins(20, 15, 20);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->SetDrawColor(255, 255, 255);
    $pdf->SetLineWidth(0.01);
    $pdf->setHeaderFont(['helvetica', '', 10]);
    $pdf->setFooterFont(['helvetica', '', 10]);
    $pdf->setHeaderMargin(0);
    $pdf->setFooterMargin(0);
    $pdf->AddPage();
    $pdf->SetDrawColor(255, 255, 255);
    $pdf->SetLineWidth(0.01);

    // ── En-tête : texte à gauche, logo à droite ──────────────────────────
    $logo_path = $root_path . '/assets/images/logo_CSK.jpg';
    $logo_w    = 35;
    $logo_x    = 190 - $logo_w;
    $logo_y    = 20;

    $pdf->SetXY(20, $logo_y);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(120, 7, 'CLINIQUES SPÉCIALISÉES DE KINSHASA', 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(120, 5, 'Laboratoire d\'analyses médicales', 0, 1, 'L');
    $pdf->SetX(20);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(120, 4, 'Kinshasa – République Démocratique du Congo', 0, 1, 'L');

    if (file_exists($logo_path)) {
        $pdf->Image($logo_path, $logo_x, $logo_y, $logo_w, 0, 'JPG', '', 'T', false, 150, '', false, false, 0, false, false, false);
    } else {
        $pdf->SetDrawColor(0, 86, 179);
        $pdf->SetLineWidth(0.8);
        $pdf->Rect($logo_x, $logo_y, $logo_w, 16);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(0, 86, 179);
        $pdf->SetXY($logo_x, $logo_y + 5);
        $pdf->Cell($logo_w, 6, 'LOGO CSK', 0, 0, 'C');
        $pdf->SetDrawColor(255, 255, 255);
        $pdf->SetLineWidth(0.2);
    }

    $sep_y = $logo_y + 18;
    $pdf->SetDrawColor(0, 86, 179);
    $pdf->SetLineWidth(0.6);
    $pdf->Line(20, $sep_y, $logo_x - 3, $sep_y);
    $pdf->SetDrawColor(255, 255, 255);
    $pdf->SetLineWidth(0.2);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($sep_y + 4);

    // ── Bloc patient ──────────────────────────────────────────────────────
    $sexe_label = (strtoupper($info_patient['sexe'] ?? '') === 'M') ? 'M' : 'F';
    $date_bon   = $premiere_date
        ? date('d/m/Y', strtotime($premiere_date))
        : date('d/m/Y');

    $pdf->SetFont('helvetica', '', 9);
    $pdf->writeHTML('
    <table cellpadding="2" cellspacing="0">
        <tr><td width="130">Noms</td><td width="190">: ' . htmlspecialchars(strtoupper($info_patient['patient_nom'] . ' ' . $info_patient['patient_prenom'])) . '</td><td></td></tr>
        <tr><td>Age</td><td>: ' . ($info_patient['age'] ?? '—') . ' ans</td><td></td></tr>
        <tr><td>Sexe</td><td>: ' . $sexe_label . '</td><td></td></tr>
        <tr><td>Convention</td><td>: ' . htmlspecialchars($societe_nom_complet ?: '—') . '</td><td></td></tr>
        <tr><td>Médecin demandeur</td><td>: ' . htmlspecialchars($info_patient['prescripteur_nom'] ?? '—') . '</td><td></td></tr>
        <tr>
            <td>Bon établi le</td>
            <td>: ' . $date_bon . '</td>
            <td style="text-align:right"><b>N° bon : ' . htmlspecialchars($numero_bon) . '</b></td>
        </tr>
    </table>', true, false, false, false, '');

    $pdf->Ln(4);

    // ── Titre encadré ─────────────────────────────────────────────────────
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 9, 'Résultats des Examens de Laboratoire', 1, 1, 'C');
    $pdf->Ln(3);

    // ── Tableau 3 colonnes ────────────────────────────────────────────────
    $col1  = 85;
    $col2  = 40;
    $col3  = 45;
    $row_h = 5;

    $pdf->SetFont('helvetica', 'BI', 9);
    $pdf->Cell($col1, $row_h, 'Examens',          1, 0, 'C');
    $pdf->Cell($col2, $row_h, 'Résultats',        1, 0, 'C');
    $pdf->Cell($col3, $row_h, 'Valeurs normales', 1, 1, 'C');

    $draw_row = function (
        string $libelle,
        string $val_res,
        string $val_norm,
        bool   $is_header,
        string $interpretation = ''
    ) use ($pdf, $col1, $col2, $col3, $row_h): void {

        $pdf->SetFont('helvetica', $is_header ? 'B' : 'I', 9);
        $lines1 = max(1, $pdf->getNumLines($libelle,  $col1 - 3));
        $pdf->SetFont('helvetica', '', 9);
        $lines2 = max(1, $pdf->getNumLines($val_res,  $col2 - 3));
        $pdf->SetFont('helvetica', 'I', 9);
        $lines3 = max(1, $pdf->getNumLines($val_norm, $col3 - 3));

        $h = max($lines1, $lines2, $lines3) * $row_h;
        $x = $pdf->GetX();
        $y = $pdf->GetY();

        $txt_color = match($interpretation) {
            'critique' => [220, 53, 69],
            'anormal'  => [200, 100, 0],
            default    => [0, 0, 0],
        };

        $pdf->SetFont('helvetica', $is_header ? 'B' : '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell($col1, $h, $libelle,  1, 'L', false, 0, $x,                 $y);

        $pdf->SetFont('helvetica', $is_header ? 'B' : '', 9);
        $pdf->SetTextColor(...$txt_color);
        $pdf->MultiCell($col2, $h, $val_res,  1, 'L', false, 0, $x + $col1,         $y);

        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell($col3, $h, $val_norm, 1, 'L', false, 1, $x + $col1 + $col2, $y);
    };

    foreach ($sous_examens_pdf as $examen) {
        $draw_row(
            $examen['libelle'],
            $examen['valeur_resultat'],
            $examen['valeur_normale'],
            $examen['is_header'],
            $examen['interpretation']
        );
    }

    $pdf->SetTextColor(0, 0, 0);

    // ── Pied de page ──────────────────────────────────────────────────────
    $pdf->SetDrawColor(255, 255, 255);
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(80, 80, 80);

    if ($premier_analyste)  $pdf->Cell(0, 4, 'Analysé par : '    . $premier_analyste,                                           0, 1, 'L');
    if ($premiere_date)     $pdf->Cell(0, 4, "Date d'analyse : " . date('d/m/Y H:i', strtotime($premiere_date)),                0, 1, 'L');
    if ($premiere_machine)  $pdf->Cell(0, 4, 'Machine : '        . $premiere_machine,                                           0, 1, 'L');

    // ── Fusion avec documents annexes ─────────────────────────────────────
    $temp_file = $root_path . '/temp_main_' . uniqid() . '.pdf';
    $pdf->Output($temp_file, 'F');

    $final_pdf = new Fpdi();
    $n = $final_pdf->setSourceFile($temp_file);
    for ($p = 1; $p <= $n; $p++) {
        $tpl  = $final_pdf->importPage($p);
        $size = $final_pdf->getTemplateSize($tpl);
        $final_pdf->AddPage(($size['width'] > $size['height']) ? 'L' : 'P', [$size['width'], $size['height']]);
        $final_pdf->useTemplate($tpl);
    }
    foreach ($documents_annexes as $doc) {
        try {
            $n = $final_pdf->setSourceFile($doc['chemin_fichier']);
            for ($p = 1; $p <= $n; $p++) {
                $tpl  = $final_pdf->importPage($p);
                $size = $final_pdf->getTemplateSize($tpl);
                $final_pdf->AddPage(($size['width'] > $size['height']) ? 'L' : 'P', [$size['width'], $size['height']]);
                $final_pdf->useTemplate($tpl);
            }
        } catch (Exception $e) {
            error_log('[PDF] Annexe ' . ($doc['fichier_original'] ?? '?') . ' : ' . $e->getMessage());
        }
    }

    if (file_exists($temp_file)) { unlink($temp_file); }

    $nom_fichier = 'Resultat_' . preg_replace('/[^A-Za-z0-9\-_]/', '', $numero_bon) . '.pdf';
    $final_pdf->Output($nom_fichier, 'I');

} catch (Exception $e) {
    if (isset($temp_file) && file_exists($temp_file)) { unlink($temp_file); }
    die('Erreur génération PDF : ' . $e->getMessage());
}