<?php
/**
 * Module Imagerie - Resultats / Comptes-Rendus
 *
 * Vue liste : examens en statut compte_rendu_fait ou transmis (filtrable).
 * Vue detail/saisie : formulaire CR du radiologue avec upload multimedia,
 * generation PDF et preparation ZIP pour gravure CD.
 */

require_once __DIR__ . '/../../includes/imagerie_helpers.php';
require_once __DIR__ . '/../../includes/pdf_generator.php';

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

$code = isset($_GET['code']) ? trim($_GET['code']) : '';

// ==========================
// VUE DETAIL : saisie du CR
// ==========================
if (!empty($code)) {

    // Charger l'examen
    $stmt = $conn_services->prepare("
        SELECT ie.*,
               TIMESTAMPDIFF(MINUTE, ie.created_at, NOW()) as delai_min,
               p.nom as patient_nom, p.prenom as patient_prenom,
               p.sexe as patient_sexe, p.date_naissance as patient_dob,
               p.numero_dossier,
               a.libelle as acte_libelle, a.code as acte_code,
               ap.date_prescription,
               -- eq.nom as equipement_nom, -- IGNORE (pas de lien direct dans la base)
               u_tech.nom as technicien_nom, u_tech.prenom as technicien_prenom,
               u_rad.nom as radiologue_nom, u_rad.prenom as radiologue_prenom
        FROM imagerie_examens ie
        LEFT JOIN csk_base.patient p ON ie.idpatient = p.idpatient
        LEFT JOIN csk_base.actes_presc ap ON ie.idactes_presc = ap.idactes_presc
        LEFT JOIN csk_base.acte a ON ap.idacte = a.idacte
        -- LEFT JOIN csk_base.equipements_imagerie eq ON ie.idequipement = eq.idequipement -- IGNORE (pas de lien direct dans la base)
        LEFT JOIN csk_base.utilisateur u_tech ON ie.manipulateur = u_tech.idutilisateur
        LEFT JOIN csk_base.utilisateur u_rad ON ie.radiologue = u_rad.idutilisateur
        WHERE ie.code_examen = :code AND ie.deleted_at IS NULL
    ");
    $stmt->execute([':code' => $code]);
    $ex = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ex) {
        echo '<div class="alert alert-danger">Examen introuvable.</div>';
        return;
    }

    // Charger les fichiers media
    $stmt_f = $conn_services->prepare("
        SELECT f.*, u.nom as user_nom, u.prenom as user_prenom
        FROM imagerie_fichiers f
        LEFT JOIN csk_base.utilisateur u ON f.uploaded_by = u.idutilisateur
        WHERE f.idexamen = :id ORDER BY f.ordre ASC, f.created_at ASC
    ");
    $stmt_f->execute([':id' => $ex['idexamen']]);
    $fichiers = $stmt_f->fetchAll(PDO::FETCH_ASSOC);

    // Historique workflow
    $stmt_h = $conn_services->prepare("
        SELECT h.*, u.nom as user_nom, u.prenom as user_prenom
        FROM imagerie_workflow_history h
        LEFT JOIN csk_base.utilisateur u ON h.idutilisateur = u.idutilisateur
        WHERE h.idexamen = :id ORDER BY h.created_at ASC
    ");
    $stmt_h->execute([':id' => $ex['idexamen']]);
    $historique = $stmt_h->fetchAll(PDO::FETCH_ASSOC);

    // Compter par type
    $nb_images = count(array_filter($fichiers, fn($f) => $f['type_fichier'] === 'image'));
    $nb_videos = count(array_filter($fichiers, fn($f) => $f['type_fichier'] === 'video'));
    $nb_pdf    = count(array_filter($fichiers, fn($f) => $f['type_fichier'] === 'pdf'));
    $nb_dicom  = count(array_filter($fichiers, fn($f) => $f['type_fichier'] === 'dicom'));

    // Verifier si l'utilisateur peut editer le CR
    $can_edit_cr = in_array($user_profil_code, ['admin', 'radiologue', 'technicien_imagerie']);
    $statuts_editables = ['en_cours','acquisition_faite','compte_rendu_en_cours','compte_rendu_fait','validation_technique'];
    $can_edit = $can_edit_cr && in_array($ex['statut'], $statuts_editables);

    // Patient age
    $patient_age = '';
    if (!empty($ex['patient_dob'])) {
        $dob = new DateTime($ex['patient_dob']);
        $patient_age = $dob->diff(new DateTime())->y . ' ans';
    }
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0" style="font-size: 0.85rem;">
        <li class="breadcrumb-item"><a href="index.php?page=imagerie&action=resultats">Resultats</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($code) ?></li>
    </ol>
</nav>

<!-- En-tete examen -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <code><?= htmlspecialchars($ex['code_examen']) ?></code>
            <?= getImagerieStatutBadge($ex['statut']) ?>
            <?php if ($ex['priorite'] === 'urgente'): ?>
                <span class="badge bg-danger">URGENT</span>
            <?php endif; ?>
        </h5>
        <div class="text-muted" style="font-size: 0.85rem;">
            <?= htmlspecialchars($ex['acte_libelle'] ?? $ex['type_examen'] ?? '-') ?> |
            Patient : <strong><?= htmlspecialchars($ex['patient_nom'] . ' ' . $ex['patient_prenom']) ?></strong>
            (<?= htmlspecialchars($ex['patient_sexe'] ?? '-') ?>, <?= $patient_age ?>)
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($fichiers)): ?>
        <a href="api/imagerie_fichiers.php?action=download_all&idexamen=<?= $ex['idexamen'] ?>" 
           class="btn btn-sm btn-outline-primary" title="Telecharger ZIP pour gravure CD">
            <i class="bi bi-disc me-1"></i>Graver CD
        </a>
        <?php endif; ?>
        <a href="api/imagerie_fichiers.php?action=generate_pdf&code=<?= urlencode($code) ?>" 
           class="btn btn-sm btn-outline-danger" target="_blank" title="Generer le compte-rendu PDF">
            <i class="bi bi-file-pdf me-1"></i>PDF
        </a>
        <a href="index.php?page=imagerie&action=workflow&code=<?= urlencode($code) ?>" 
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-diagram-3 me-1"></i>Workflow
        </a>
    </div>
</div>

<div class="row g-3">

    <!-- COLONNE GAUCHE : Formulaire CR -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong><i class="bi bi-file-earmark-richtext me-2"></i>Compte-Rendu</strong>
            </div>
            <div class="card-body">
                <form method="POST" action="api/imagerie_fichiers.php?action=save_cr" id="formCR">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="code_examen" value="<?= htmlspecialchars($code) ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Technique utilisee</label>
                        <textarea name="technique" class="form-control" rows="3" 
                            <?= $can_edit ? '' : 'readonly' ?>
                            placeholder="Decrire la technique d'examen utilisee..."><?= htmlspecialchars($ex['technique'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Description et Resultats</label>
                        <textarea name="description_resultats" class="form-control" rows="6" 
                            <?= $can_edit ? '' : 'readonly' ?>
                            placeholder="Description detaillee des observations..."><?= htmlspecialchars($ex['description_resultats'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Conclusion</label>
                        <textarea name="conclusion" class="form-control" rows="3" 
                            <?= $can_edit ? '' : 'readonly' ?>
                            placeholder="Conclusion du radiologue..."><?= htmlspecialchars($ex['conclusion'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Recommandations</label>
                        <textarea name="recommandations" class="form-control" rows="2" 
                            <?= $can_edit ? '' : 'readonly' ?>
                            placeholder="Recommandations pour le medecin traitant..."><?= htmlspecialchars($ex['recommandations'] ?? '') ?></textarea>
                    </div>

                    <?php if ($can_edit): ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Enregistrer le compte-rendu
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- SECTION FICHIERS MEDIA -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex align-items-center justify-content-between">
                <strong>
                    <i class="bi bi-images me-2"></i>Fichiers Media
                    <span class="badge bg-secondary ms-1"><?= count($fichiers) ?></span>
                </strong>
                <div class="d-flex gap-2" style="font-size: 0.8rem;">
                    <?php if ($nb_images > 0): ?><span class="badge bg-info">Images: <?= $nb_images ?></span><?php endif; ?>
                    <?php if ($nb_videos > 0): ?><span class="badge bg-warning text-dark">Videos: <?= $nb_videos ?></span><?php endif; ?>
                    <?php if ($nb_dicom > 0): ?><span class="badge bg-dark">DICOM: <?= $nb_dicom ?></span><?php endif; ?>
                    <?php if ($nb_pdf > 0): ?><span class="badge bg-danger">PDF: <?= $nb_pdf ?></span><?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <!-- Upload zone -->
                <?php if ($can_edit): ?>
                <div class="border border-dashed rounded p-3 mb-3 text-center" id="uploadZone"
                     style="border: 2px dashed #adb5bd; background: #f8f9fa; cursor: pointer;">
                    <i class="bi bi-cloud-upload" style="font-size: 2rem; color: #6c757d;"></i>
                    <p class="mb-1 text-muted">Glissez vos fichiers ici ou cliquez pour selectionner</p>
                    <small class="text-muted">
                        Images (jpg, png, tiff, bmp, gif, webp, svg) | Videos (mp4, avi, mov, wmv, mkv, webm, flv) | PDF | DICOM (dcm)
                    </small>
                    <input type="file" id="fileInput" multiple accept=".jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.webp,.svg,.mp4,.avi,.mov,.wmv,.mkv,.webm,.flv,.pdf,.dcm,.dicom" 
                           style="display: none;">
                </div>
                <div id="uploadProgress" class="mb-3" style="display:none;">
                    <div class="progress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="uploadBar" style="width: 0%"></div>
                    </div>
                    <small class="text-muted" id="uploadStatus">Upload en cours...</small>
                </div>
                <?php endif; ?>

                <!-- Galerie des fichiers existants -->
                <?php if (empty($fichiers)): ?>
                    <div class="text-center text-muted py-3">Aucun fichier media pour cet examen</div>
                <?php else: ?>
                    <div class="row g-2" id="fichiersList">
                        <?php foreach ($fichiers as $f): ?>
                        <div class="col-md-4 col-sm-6 fichier-item" data-id="<?= $f['idfichier'] ?>">
                            <div class="card border h-100">
                                <?php if ($f['type_fichier'] === 'image'): ?>
                                    <a href="<?= htmlspecialchars($f['chemin_fichier']) ?>" target="_blank">
                                        <img src="<?= htmlspecialchars($f['chemin_fichier']) ?>" 
                                             class="card-img-top" style="height: 150px; object-fit: cover;" 
                                             alt="<?= htmlspecialchars($f['description'] ?? $f['nom_fichier']) ?>">
                                    </a>
                                <?php elseif ($f['type_fichier'] === 'video'): ?>
                                    <div class="card-img-top d-flex align-items-center justify-content-center" 
                                         style="height: 150px; background: #1a1a2e;">
                                        <video src="<?= htmlspecialchars($f['chemin_fichier']) ?>" 
                                               style="max-width:100%; max-height:150px;" controls preload="metadata"></video>
                                    </div>
                                <?php elseif ($f['type_fichier'] === 'dicom'): ?>
                                    <div class="card-img-top d-flex align-items-center justify-content-center" 
                                         style="height: 150px; background: #212529; color: #0dcaf0;">
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark-binary" style="font-size: 2.5rem;"></i>
                                            <div style="font-size: 0.75rem;">DICOM</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="card-img-top d-flex align-items-center justify-content-center" 
                                         style="height: 150px; background: #f8d7da; color: #842029;">
                                        <div class="text-center">
                                            <i class="bi bi-file-pdf" style="font-size: 2.5rem;"></i>
                                            <div style="font-size: 0.75rem;">PDF</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <div class="card-body p-2">
                                    <div style="font-size: 0.8rem; font-weight: 600;" class="text-truncate" title="<?= htmlspecialchars($f['nom_fichier']) ?>">
                                        <?= htmlspecialchars($f['nom_fichier']) ?>
                                    </div>
                                    <div style="font-size: 0.7rem;" class="text-muted">
                                        <?= number_format($f['taille_octets'] / 1024 / 1024, 2) ?> Mo
                                        | <?= htmlspecialchars($f['user_nom'] ?? '') ?>
                                    </div>
                                    <div class="d-flex gap-1 mt-1">
                                        <a href="<?= htmlspecialchars($f['chemin_fichier']) ?>" download 
                                           class="btn btn-sm btn-outline-secondary flex-grow-1" style="font-size: 0.7rem;">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <?php if ($can_edit): ?>
                                        <button class="btn btn-sm btn-outline-danger" style="font-size: 0.7rem;"
                                                onclick="deleteFichier(<?= $f['idfichier'] ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- COLONNE DROITE : Infos patient + Timeline -->
    <div class="col-lg-4">
        <!-- Infos patient -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong><i class="bi bi-person me-2"></i>Patient</strong>
            </div>
            <div class="card-body" style="font-size: 0.85rem;">
                <div class="mb-2"><span class="text-muted">Nom :</span> <strong><?= htmlspecialchars($ex['patient_nom'] . ' ' . $ex['patient_prenom']) ?></strong></div>
                <div class="mb-2"><span class="text-muted">Sexe :</span> <?= htmlspecialchars($ex['patient_sexe'] ?? '-') ?></div>
                <div class="mb-2"><span class="text-muted">Age :</span> <?= $patient_age ?></div>
                <div class="mb-2"><span class="text-muted">N. Dossier :</span> <?= htmlspecialchars($ex['numero_dossier'] ?? '-') ?></div>
                <hr>
                <div class="mb-2"><span class="text-muted">Examen :</span> <?= htmlspecialchars($ex['type_examen'] ?? '-') ?></div>
                <div class="mb-2"><span class="text-muted">Equipement :</span> <?= htmlspecialchars($ex['equipement_nom'] ?? '-') ?></div>
                <div class="mb-2"><span class="text-muted">Salle :</span> <?= htmlspecialchars($ex['salle'] ?? '-') ?></div>
                <div class="mb-2"><span class="text-muted">Technicien :</span> <?= htmlspecialchars(trim(($ex['technicien_nom'] ?? '') . ' ' . ($ex['technicien_prenom'] ?? '')) ?: '-') ?></div>
                <div class="mb-2"><span class="text-muted">Radiologue :</span> <?= htmlspecialchars(trim(($ex['radiologue_nom'] ?? '') . ' ' . ($ex['radiologue_prenom'] ?? '')) ?: '-') ?></div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <strong><i class="bi bi-clock-history me-2"></i>Timeline</strong>
            </div>
            <div class="card-body p-0">
                <?php if (empty($historique)): ?>
                    <div class="text-center text-muted py-3" style="font-size: 0.85rem;">Aucun historique</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($historique as $h): ?>
                        <div class="list-group-item py-2" style="font-size: 0.8rem;">
                            <div class="d-flex justify-content-between">
                                <strong><?= htmlspecialchars($h['action'] ?? '') ?></strong>
                                <small class="text-muted"><?= date('d/m H:i', strtotime($h['created_at'])) ?></small>
                            </div>
                            <div>
                                <?= getImagerieStatutBadge($h['ancien_statut'] ?? '') ?>
                                <i class="bi bi-arrow-right" style="font-size: 0.7rem;"></i>
                                <?= getImagerieStatutBadge($h['nouveau_statut'] ?? '') ?>
                            </div>
                            <?php if (!empty($h['user_nom'])): ?>
                            <small class="text-muted">par <?= htmlspecialchars($h['user_nom'] . ' ' . ($h['user_prenom'] ?? '')) ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- JS pour upload et suppression -->
<script>
const idexamen = <?= (int)$ex['idexamen'] ?>;
const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';

// Upload zone
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');

if (uploadZone && fileInput) {
    uploadZone.addEventListener('click', () => fileInput.click());
    uploadZone.addEventListener('dragover', e => { e.preventDefault(); uploadZone.style.borderColor = '#0dcaf0'; });
    uploadZone.addEventListener('dragleave', () => { uploadZone.style.borderColor = '#adb5bd'; });
    uploadZone.addEventListener('drop', e => {
        e.preventDefault();
        uploadZone.style.borderColor = '#adb5bd';
        handleFiles(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', () => handleFiles(fileInput.files));
}

function handleFiles(files) {
    if (!files.length) return;
    const formData = new FormData();
    formData.append('idexamen', idexamen);
    formData.append('csrf_token', csrfToken);
    for (let i = 0; i < files.length; i++) {
        formData.append('fichiers[]', files[i]);
    }

    const progressDiv = document.getElementById('uploadProgress');
    const bar = document.getElementById('uploadBar');
    const status = document.getElementById('uploadStatus');
    progressDiv.style.display = 'block';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/imagerie_fichiers.php?action=upload');
    xhr.upload.onprogress = e => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            bar.style.width = pct + '%';
            status.textContent = 'Upload en cours... ' + pct + '%';
        }
    };
    xhr.onload = () => {
        progressDiv.style.display = 'none';
        bar.style.width = '0%';
        try {
            const resp = JSON.parse(xhr.responseText);
            if (resp.success) {
                location.reload();
            } else {
                alert('Erreur: ' + (resp.error || 'Upload echoue'));
            }
        } catch(e) { alert('Erreur de communication avec le serveur.'); }
    };
    xhr.onerror = () => { progressDiv.style.display = 'none'; alert('Erreur reseau.'); };
    xhr.send(formData);
}

function deleteFichier(id) {
    if (!confirm('Supprimer ce fichier definitivement ?')) return;
    fetch('api/imagerie_fichiers.php?action=delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ idfichier: id, csrf_token: csrfToken })
    })
    .then(r => r.json())
    .then(resp => {
        if (resp.success) {
            const el = document.querySelector('.fichier-item[data-id="' + id + '"]');
            if (el) el.remove();
        } else {
            alert('Erreur: ' + (resp.error || 'Suppression echouee'));
        }
    })
    .catch(() => alert('Erreur reseau.'));
}
</script>

<?php
    return; // Fin de la vue detail
} // fin if (!empty($code))


// ==========================
// VUE LISTE : tous les CR
// ==========================

$filtre_statut = isset($_GET['statut']) ? sanitizeInput($_GET['statut']) : '';
$filtre_type   = isset($_GET['type'])   ? sanitizeInput($_GET['type'])   : '';
$search        = isset($_GET['q'])      ? trim($_GET['q'])              : '';
$page_num      = max(1, (int)($_GET['p'] ?? 1));
$per_page      = 15;
$offset        = ($page_num - 1) * $per_page;

// Statuts ou les resultats/CR sont pertinents
$statuts_cr = ['compte_rendu_en_cours','compte_rendu_fait','validation_technique','validation_radiologue','transmis'];

$where = ["ie.deleted_at IS NULL"];
$params = [];

if (!empty($filtre_statut) && in_array($filtre_statut, $statuts_cr)) {
    $where[] = "ie.statut = :statut";
    $params[':statut'] = $filtre_statut;
} else {
    $placeholders = [];
    foreach ($statuts_cr as $i => $s) {
        $placeholders[] = ":scr$i";
        $params[":scr$i"] = $s;
    }
    // Inclure aussi en_cours et acquisition_faite pour permettre la saisie
    $where[] = "(ie.statut IN (" . implode(',', $placeholders) . ") OR ie.statut IN ('en_cours','acquisition_faite'))";
}

if (!empty($filtre_type)) {
    $where[] = "ie.type_examen = :type";
    $params[':type'] = $filtre_type;
}

if (!empty($search)) {
    $where[] = "(ie.code_examen LIKE :q1 OR p.nom LIKE :q2 OR p.prenom LIKE :q3)";
    $params[':q1'] = "%$search%";
    $params[':q2'] = "%$search%";
    $params[':q3'] = "%$search%";
}

$where_sql = implode(' AND ', $where);

// Count
$stmt_c = $conn_services->prepare("
    SELECT COUNT(*) FROM imagerie_examens ie
    LEFT JOIN csk_base.patient p ON ie.idpatient = p.idpatient
    WHERE $where_sql
");
$stmt_c->execute($params);
$total = (int)$stmt_c->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

// Fetch
$stmt = $conn_services->prepare("
    SELECT ie.idexamen, ie.code_examen, ie.type_examen, ie.statut, ie.priorite,
           ie.conclusion, ie.date_examen, ie.created_at,
           p.nom as patient_nom, p.prenom as patient_prenom,
           a.libelle as acte_libelle,
           (SELECT COUNT(*) FROM imagerie_fichiers f WHERE f.idexamen = ie.idexamen) as nb_fichiers
    FROM imagerie_examens ie
    LEFT JOIN csk_base.patient p ON ie.idpatient = p.idpatient
    LEFT JOIN csk_base.actes_presc ap ON ie.idactes_presc = ap.idactes_presc
    LEFT JOIN csk_base.acte a ON ap.idacte = a.idacte
    WHERE $where_sql
    ORDER BY ie.priorite = 'urgente' DESC, ie.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$examens = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><i class="bi bi-file-earmark-richtext me-2" style="color: #0dcaf0;"></i>Resultats / Comptes-Rendus</h5>
    <span class="badge bg-secondary"><?= $total ?> examen(s)</span>
</div>

<!-- Filtres -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end">
            <input type="hidden" name="page" value="imagerie">
            <input type="hidden" name="action" value="resultats">
            <div>
                <label class="form-label mb-0" style="font-size: 0.75rem;">Statut</label>
                <select name="statut" class="form-select form-select-sm" style="width: 180px;">
                    <option value="">Tous les statuts CR</option>
                    <option value="en_cours" <?= $filtre_statut === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                    <option value="acquisition_faite" <?= $filtre_statut === 'acquisition_faite' ? 'selected' : '' ?>>Acquisition faite</option>
                    <option value="compte_rendu_en_cours" <?= $filtre_statut === 'compte_rendu_en_cours' ? 'selected' : '' ?>>CR en cours</option>
                    <option value="compte_rendu_fait" <?= $filtre_statut === 'compte_rendu_fait' ? 'selected' : '' ?>>CR fait</option>
                    <option value="validation_technique" <?= $filtre_statut === 'validation_technique' ? 'selected' : '' ?>>Valid. technique</option>
                    <option value="validation_radiologue" <?= $filtre_statut === 'validation_radiologue' ? 'selected' : '' ?>>Valid. radiologue</option>
                    <option value="transmis" <?= $filtre_statut === 'transmis' ? 'selected' : '' ?>>Transmis</option>
                </select>
            </div>
            <div>
                <label class="form-label mb-0" style="font-size: 0.75rem;">Type</label>
                <select name="type" class="form-select form-select-sm" style="width: 150px;">
                    <option value="">Tous types</option>
                    <option value="radiographie" <?= $filtre_type === 'radiographie' ? 'selected' : '' ?>>Radiographie</option>
                    <option value="echographie" <?= $filtre_type === 'echographie' ? 'selected' : '' ?>>Echographie</option>
                    <option value="scanner" <?= $filtre_type === 'scanner' ? 'selected' : '' ?>>Scanner</option>
                    <option value="irm" <?= $filtre_type === 'irm' ? 'selected' : '' ?>>IRM</option>
                </select>
            </div>
            <div>
                <label class="form-label mb-0" style="font-size: 0.75rem;">Recherche</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Code, patient..." 
                       value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="index.php?page=imagerie&action=resultats" class="btn btn-sm btn-outline-secondary">Reset</a>
        </form>
    </div>
</div>

<!-- Tableau resultats -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Patient</th>
                        <th>Type</th>
                        <th>Acte</th>
                        <th>Statut</th>
                        <th>Fichiers</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($examens)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-3">Aucun resultat trouve</td></tr>
                    <?php else: foreach ($examens as $e): ?>
                        <tr>
                            <td>
                                <code><?= htmlspecialchars($e['code_examen']) ?></code>
                                <?php if ($e['priorite'] === 'urgente'): ?>
                                    <span class="badge bg-danger" style="font-size: 0.6rem;">URG</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(($e['patient_nom'] ?? '') . ' ' . ($e['patient_prenom'] ?? '')) ?></td>
                            <td><?= htmlspecialchars(ucfirst($e['type_examen'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($e['acte_libelle'] ?? '-') ?></td>
                            <td><?= getImagerieStatutBadge($e['statut']) ?></td>
                            <td>
                                <?php if ((int)$e['nb_fichiers'] > 0): ?>
                                    <span class="badge bg-info"><?= $e['nb_fichiers'] ?> fichier(s)</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= date('d/m/Y', strtotime($e['date_examen'] ?? $e['created_at'])) ?></td>
                            <td>
                                <a href="index.php?page=imagerie&action=resultats&code=<?= urlencode($e['code_examen']) ?>" 
                                   class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Page <?= $page_num ?>/<?= $total_pages ?> (<?= $total ?> resultats)</small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($page_num > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= buildImagerieFilterUrl('resultats', ['p' => $page_num - 1]) ?>">Prec.</a>
                </li>
                <?php endif; ?>
                <?php for ($i = max(1, $page_num - 2); $i <= min($total_pages, $page_num + 2); $i++): ?>
                <li class="page-item <?= $i === $page_num ? 'active' : '' ?>">
                    <a class="page-link" href="<?= buildImagerieFilterUrl('resultats', ['p' => $i]) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($page_num < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= buildImagerieFilterUrl('resultats', ['p' => $page_num + 1]) ?>">Suiv.</a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
