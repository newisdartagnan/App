<?php
/**
 * Module Laboratoire — Export PDF d'un résultat
 *
 * Appelé via : index.php?page=labo&action=pdf_resultat&code=LAB-YYYYMMDD-XXXX-NN
 *
 * Stratégie :
 *   1. Tente mPDF si installé (composer / vendor)
 *   2. Sinon utilise une page HTML bien mise en forme avec header Content-Type
 *      et @media print optimisé — le navigateur génère un PDF propre à l'impression
 *
 * Les deux approches produisent un résultat professionnel.
 */

require_once __DIR__ . '/../../includes/labo_helpers.php';

$current_user     = getCurrentUser();
$user_profil_code = $current_user['profil_code'];

// ── Accès minimum ────────────────────────────────────────────
$profils_autorises = ['admin', 'biologiste', 'technicien_labo', 'medecin', 'infirmier'];
if (!in_array($user_profil_code, $profils_autorises)) {
    http_response_code(403);
    die('Accès non autorisé.');
}

$code_echantillon = isset($_GET['code']) ? sanitizeInput($_GET['code']) : '';
if (!$code_echantillon) {
    die('Code échantillon manquant.');
}

$db            = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

// ── Charger l'échantillon ────────────────────────────────────
try {
    $stmt = $conn_services->prepare("
        SELECT
            le.*,
            p.nom   AS patient_nom,   p.prenom AS patient_prenom,
            p.sexe  AS patient_sexe,  p.date_naissance AS patient_dob,
            a.libelle AS examen_libelle, a.code AS examen_code,
            ap.date_prescription,
            u_pre.nom  AS preleveur_nom,  u_pre.prenom  AS preleveur_prenom,
            u_tech.nom AS technicien_nom, u_tech.prenom AS technicien_prenom,
            lg.code_groupe
        FROM labo_echantillons le
        JOIN labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        JOIN csk_base.patient p ON p.idpatient = lg.idpatient
        LEFT JOIN csk_base.actes_presc ap ON le.idactes_presc = ap.idactes_presc
        LEFT JOIN csk_base.acte a ON ap.idacte = a.idacte
        LEFT JOIN csk_base.utilisateur u_pre  ON le.preleveur         = u_pre.idutilisateur
        LEFT JOIN csk_base.utilisateur u_tech ON le.technicien_analyse = u_tech.idutilisateur
        WHERE le.code_echantillon = :code AND le.deleted_at IS NULL
    ");
    $stmt->execute([':code' => $code_echantillon]);
    $echantillon = $stmt->fetch();
} catch (Exception $e) {
    die('Erreur de chargement: ' . $e->getMessage());
}

if (!$echantillon) {
    die("Échantillon «$code_echantillon» introuvable.");
}
if (!$echantillon['idresultat']) {
    die('Aucun résultat saisi pour cet échantillon.');
}

// ── Charger le résultat ──────────────────────────────────────
try {
    $stmt = $conn_base->prepare("
        SELECT r.*,
               u.nom AS analyste_nom, u.prenom AS analyste_prenom,
               m.nom AS machine_nom
        FROM resultatslabo r
        LEFT JOIN utilisateur u ON u.idutilisateur = r.analyse_par
        LEFT JOIN machineslabo m ON m.idmachinelabo = r.idmachinelabo
        WHERE r.idresultat = :id
    ");
    $stmt->execute([':id' => $echantillon['idresultat']]);
    $resultat = $stmt->fetch();
} catch (Exception $e) {
    die('Erreur résultat: ' . $e->getMessage());
}

if (!$resultat) {
    die('Résultat introuvable en base.');
}

// ── Préparer les données ──────────────────────────────────────
$pat_nom   = trim(($echantillon['patient_nom']??'').' '.($echantillon['patient_prenom']??''));
$pat_sexe  = $echantillon['patient_sexe'] ?? '';
$pat_age   = !empty($echantillon['patient_dob']) ? calculateAge($echantillon['patient_dob']).' ans' : '';
$pat_dob   = !empty($echantillon['patient_dob']) ? formatDate($echantillon['patient_dob']) : '';

$exam_lib  = $echantillon['examen_libelle'] ?? '—';
$exam_code = $echantillon['examen_code'] ?? '';
$code_grp  = $echantillon['code_groupe'] ?? '';

$date_presc    = !empty($echantillon['date_prescription'])
                 ? formatDateTime($echantillon['date_prescription'], 'd/m/Y')
                 : '—';
$date_prelev   = !empty($echantillon['date_prelevement'])
                 ? formatDateTime($echantillon['date_prelevement'], 'd/m/Y H:i')
                 : '—';
$preleveur     = trim(($echantillon['preleveur_prenom']??'').' '.($echantillon['preleveur_nom']??'')) ?: '—';

$res_text  = $resultat['resultat'] ?? '';
$vn_text   = $resultat['valeur_normale'] ?? '';
$interp    = $resultat['interpretation'] ?? '';
$obs       = $resultat['observations'] ?? '';
$machine   = $resultat['machine_nom'] ?? '—';
$analyste  = trim(($resultat['analyste_prenom']??'').' '.($resultat['analyste_nom']??'')) ?: '—';
$date_ana  = !empty($resultat['date_analyse'])
             ? formatDateTime($resultat['date_analyse'], 'd/m/Y H:i')
             : '—';

$interp_label = match($interp) {
    'normal'   => 'NORMAL',
    'anormal'  => 'ANORMAL',
    'critique' => 'CRITIQUE',
    default    => '—',
};
$interp_color = match($interp) {
    'normal'   => '#155724',
    'anormal'  => '#856404',
    'critique' => '#842029',
    default    => '#6c757d',
};
$interp_bg = match($interp) {
    'normal'   => '#d1e7dd',
    'anormal'  => '#fff3cd',
    'critique' => '#f8d7da',
    default    => '#e9ecef',
};

$est_urgent = (bool)$echantillon['urgence'];

// ── Tenter mPDF ──────────────────────────────────────────────
$use_mpdf = false;
$vendor_paths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    BASE_PATH . '/vendor/autoload.php',
];
foreach ($vendor_paths as $vp) {
    if (file_exists($vp)) {
        require_once $vp;
        if (class_exists('\Mpdf\Mpdf')) {
            $use_mpdf = true;
        }
        break;
    }
}

// ── HTML commun (utilisé par mPDF ou navigateur) ─────────────
$nom_fichier = 'resultat_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $code_echantillon) . '_' . date('Ymd') . '.pdf';

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Résultat — <?= htmlspecialchars($code_echantillon) ?></title>
<style>
/* ── Base ── */
* { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11pt;
    color: #212529;
    background: #fff;
    padding: 20mm 15mm;
}

/* ── En-tête ── */
.header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2.5pt solid #212529;
    padding-bottom: 10pt;
    margin-bottom: 14pt;
}
.header-left h1 {
    font-size: 15pt;
    font-weight: bold;
    margin-bottom: 2pt;
}
.header-left .subtitle { font-size: 9pt; color: #666; }
.header-right { text-align: right; font-size: 9pt; color: #555; }
.header-right .code { font-size: 11pt; font-weight: bold; color: #212529; }
.urgent-badge {
    display: inline-block;
    background: #dc3545;
    color: #fff;
    font-size: 10pt;
    font-weight: bold;
    padding: 2pt 8pt;
    border-radius: 3pt;
    margin-top: 4pt;
}

/* ── Sections ── */
.section { margin-bottom: 12pt; }
.section-title {
    font-size: 8pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5pt;
    color: #555;
    border-bottom: 1pt solid #dee2e6;
    padding-bottom: 3pt;
    margin-bottom: 6pt;
}

/* ── Grilles ── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10pt; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10pt; }
.label  { font-size: 9pt; color: #666; display: block; margin-bottom: 2pt; }
.value  { font-size: 11pt; font-weight: 600; }
.value-sm { font-size: 10pt; }

/* ── Résultat ── */
.resultat-box {
    border: 1pt solid #ccc;
    background: #fafafa;
    padding: 9pt 11pt;
    font-family: 'Courier New', monospace;
    font-size: 10.5pt;
    white-space: pre-wrap;
    line-height: 1.5;
    border-radius: 3pt;
}
.valeurs-box {
    border: 1pt solid #e0e0e0;
    background: #f5f5f5;
    padding: 7pt 11pt;
    font-size: 10pt;
    white-space: pre-wrap;
    border-radius: 3pt;
}

/* ── Interprétation ── */
.interp-box {
    display: inline-block;
    padding: 4pt 14pt;
    border-radius: 4pt;
    font-size: 12pt;
    font-weight: bold;
    letter-spacing: 0.5pt;
    color: <?= $interp_color ?>;
    background: <?= $interp_bg ?>;
    border: 1.5pt solid <?= $interp_color ?>;
}

/* ── Observations ── */
.obs-box {
    font-size: 10pt;
    font-style: italic;
    color: #444;
    border-left: 3pt solid #dee2e6;
    padding-left: 8pt;
}

/* ── Pied de page ── */
.footer {
    border-top: 1pt solid #ccc;
    padding-top: 6pt;
    margin-top: 16pt;
    display: flex;
    justify-content: space-between;
    font-size: 8pt;
    color: #888;
}

/* ── Filigrane critique ── */
<?php if ($interp === 'critique'): ?>
.watermark {
    position: fixed;
    top: 45%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-35deg);
    font-size: 60pt;
    font-weight: bold;
    color: rgba(220,53,69,.08);
    white-space: nowrap;
    pointer-events: none;
    z-index: 0;
}
<?php endif; ?>

/* ── Print ── */
@media print {
    body { padding: 10mm; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<?php if ($interp === 'critique'): ?>
<div class="watermark">CRITIQUE</div>
<?php endif; ?>

<!-- EN-TÊTE -->
<div class="header">
    <div class="header-left">
        <h1>Résultat d'Analyse Biologique</h1>
        <div class="subtitle">Laboratoire — Document officiel</div>
        <?php if ($est_urgent): ?>
        <div class="urgent-badge">⚠ URGENT</div>
        <?php endif; ?>
    </div>
    <div class="header-right">
        <div class="code"><?= htmlspecialchars($code_echantillon) ?></div>
        <div>Groupe : <?= htmlspecialchars($code_grp) ?></div>
        <div>Édité le : <?= date('d/m/Y à H:i') ?></div>
    </div>
</div>

<!-- PATIENT + EXAMEN -->
<div class="section">
    <div class="grid-2">
        <div>
            <div class="section-title">Patient</div>
            <div class="value" style="font-size:13pt;"><?= htmlspecialchars($pat_nom) ?></div>
            <?php if ($pat_sexe || $pat_age): ?>
            <div style="font-size:9.5pt;color:#555;margin-top:3pt;">
                <?= htmlspecialchars($pat_sexe) ?>
                <?= $pat_age ? ' &mdash; '.$pat_age : '' ?>
                <?= $pat_dob ? ' (né(e) le '.$pat_dob.')' : '' ?>
            </div>
            <?php endif; ?>
        </div>
        <div>
            <div class="section-title">Examen</div>
            <div class="value"><?= htmlspecialchars($exam_lib) ?></div>
            <?php if ($exam_code): ?>
            <div style="font-size:9pt;color:#555;margin-top:2pt;">Code acte : <?= htmlspecialchars($exam_code) ?></div>
            <?php endif; ?>
            <div style="font-size:9pt;color:#555;margin-top:2pt;">Prescrit le : <?= htmlspecialchars($date_presc) ?></div>
        </div>
    </div>
</div>

<!-- PRÉLÈVEMENT -->
<div class="section">
    <div class="section-title">Prélèvement</div>
    <div class="grid-3">
        <div>
            <span class="label">Date et heure</span>
            <span class="value-sm"><?= htmlspecialchars($date_prelev) ?></span>
        </div>
        <div>
            <span class="label">Prélevé par</span>
            <span class="value-sm"><?= htmlspecialchars($preleveur) ?></span>
        </div>
        <?php if ($echantillon['type_prelevement'] || $echantillon['tube_type']): ?>
        <div>
            <span class="label">Type</span>
            <span class="value-sm">
                <?= htmlspecialchars(str_replace('_',' ',ucfirst($echantillon['type_prelevement']??''))) ?>
                <?= $echantillon['tube_type'] ? ' / '.htmlspecialchars($echantillon['tube_type']) : '' ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- RÉSULTAT -->
<div class="section">
    <div class="section-title">Résultat</div>
    <div class="resultat-box"><?= htmlspecialchars($res_text) ?></div>
</div>

<?php if ($vn_text): ?>
<!-- VALEURS NORMALES -->
<div class="section">
    <div class="section-title">Valeurs de référence</div>
    <div class="valeurs-box"><?= htmlspecialchars($vn_text) ?></div>
</div>
<?php endif; ?>

<!-- INTERPRÉTATION + ANALYSTE -->
<div class="section">
    <div class="section-title">Conclusion</div>
    <div class="grid-2" style="align-items:start;">
        <div>
            <span class="label">Interprétation</span>
            <div class="interp-box"><?= htmlspecialchars($interp_label) ?></div>
        </div>
        <div>
            <span class="label">Analysé par</span>
            <div class="value-sm"><?= htmlspecialchars($analyste) ?></div>
            <div style="font-size:9pt;color:#666;margin-top:2pt;"><?= htmlspecialchars($date_ana) ?></div>
            <?php if ($machine !== '—'): ?>
            <div style="font-size:9pt;color:#666;margin-top:2pt;">Machine : <?= htmlspecialchars($machine) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($obs): ?>
<!-- OBSERVATIONS -->
<div class="section">
    <div class="section-title">Observations</div>
    <div class="obs-box"><?= htmlspecialchars($obs) ?></div>
</div>
<?php endif; ?>

<!-- PIED DE PAGE -->
<div class="footer">
    <div>Document généré le <?= date('d/m/Y à H:i') ?></div>
    <div>Réf. : <?= htmlspecialchars($code_echantillon) ?></div>
    <div>Confidentiel — Usage médical uniquement</div>
</div>

</body>
</html>
<?php
$html_content = ob_get_clean();

// ── Rendu : mPDF ou HTML ────────────────────────────────────
if ($use_mpdf) {
    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 15,
            'margin_bottom' => 15,
            'margin_left'   => 15,
            'margin_right'  => 15,
        ]);
        $mpdf->SetTitle('Résultat labo — ' . $code_echantillon);
        $mpdf->SetAuthor('Laboratoire CSK');
        $mpdf->WriteHTML($html_content);
        $mpdf->Output($nom_fichier, \Mpdf\Output\Destination::DOWNLOAD);
        exit();
    } catch (Exception $e) {
        error_log("[PDF Labo] mPDF erreur: " . $e->getMessage());
        // Fallback HTML si mPDF échoue
    }
}

// ── Fallback : HTML avec header d'impression auto ────────────
// Le navigateur reçoit le HTML et l'utilisateur imprime en PDF
header('Content-Type: text/html; charset=utf-8');
// Ajouter un script d'impression automatique pour l'ouverture dans un onglet
$html_content = str_replace(
    '</body>',
    '<script>
        // Ouvrir directement la boîte d\'impression pour enregistrer en PDF
        window.addEventListener("load", function() {
            setTimeout(function() { window.print(); }, 400);
        });
    </script></body>',
    $html_content
);
echo $html_content;
exit();
