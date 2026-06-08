<?php
/**
 * Module Imagerie - Visualiseur PACS
 * 
 * Interface de visualisation d'images médicales (DICOM) et vidéos
 */

require_once __DIR__ . '/../../includes/imagerie_helpers.php';

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base = $db->getBaseConnection();

// =============================================
// RÉCUPÉRATION DES EXAMENS AVEC IMAGES
// =============================================
$query = "SELECT 
    e.idexamen,
    e.code_examen,
    e.type_examen,
    e.date_rdv,
    e.date_examen,
    e.statut,
    e.radiologue,
    e.radiologue_validateur,
    e.compte_rendu_text,
    e.conclusion,
    e.chemin_images,
    e.fichier_pdf,
    e.taille_fichier_mo,
    e.qualite_images,
    e.artefacts,
    p.idpatient,
    p.nom as patient_nom,
    p.prenom as patient_prenom,
    p.numero_dossier,
    p.date_naissance,
    p.sexe,
    u.nom as radiologue_nom,
    u.prenom as radiologue_prenom,
    uv.nom as validateur_nom,
    uv.prenom as validateur_prenom
    FROM imagerie_examens e
    LEFT JOIN csk_base.patient p ON e.idpatient = p.idpatient
    LEFT JOIN csk_base.utilisateur u ON e.radiologue = u.idutilisateur
    LEFT JOIN csk_base.utilisateur uv ON e.radiologue_validateur = uv.idutilisateur
    WHERE (e.chemin_images IS NOT NULL OR e.fichier_pdf IS NOT NULL)
    ORDER BY e.date_rdv DESC
    LIMIT 50";

$stmt = $conn_services->prepare($query);
$stmt->execute();
$examens_images = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// RÉCUPÉRATION DES VIDÉOS (procédures)
// =============================================
$query_videos = "SELECT 
    e.idexamen,
    e.code_examen,
    e.type_examen,
    e.date_rdv,
    e.protocole_utilise,
    e.parametres_acquisition,
    p.nom as patient_nom,
    p.prenom as patient_prenom
    FROM imagerie_examens e
    LEFT JOIN csk_base.patient p ON e.idpatient = p.idpatient
    WHERE e.type_examen IN ('Échographie', 'Vidéofluoroscopie', 'Ciné-IRM')
    ORDER BY e.date_rdv DESC
    LIMIT 20";

$stmt_videos = $conn_services->prepare($query_videos);
$stmt_videos->execute();
$examens_videos = $stmt_videos->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// IDENTIFIANTS POUR FILTRAGE RAPIDE
// =============================================
$examens_ids = array_column($examens_images, 'idexamen');
$videos_ids = array_column($examens_videos, 'idexamen');
?>

<!-- ========================================= -->
<!-- EN-TÊTE DU VISUALISEUR -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-images me-2" style="color: #0dcaf0;"></i>
        Visualiseur PACS / Vidéos
    </h4>
    <div class="btn-group">
        <a href="index.php?page=imagerie&action=dashboard" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <button onclick="toggleFullscreen()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrows-fullscreen"></i> Plein écran
        </button>
    </div>
</div>

<!-- ========================================= -->
<!-- INTERFACE PRINCIPALE -->
<!-- ========================================= -->
<div class="pacs-container">
    <!-- Barre latérale gauche - Liste des examens -->
    <div class="pacs-sidebar">
        <div class="sidebar-header">
            <ul class="nav nav-tabs nav-fill" id="pacsTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="images-tab" data-bs-toggle="tab" data-bs-target="#images" type="button" role="tab">
                        <i class="bi bi-image"></i> Images
                        <span class="badge bg-primary ms-1"><?= count($examens_images) ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="videos-tab" data-bs-toggle="tab" data-bs-target="#videos" type="button" role="tab">
                        <i class="bi bi-camera-reels"></i> Vidéos
                        <span class="badge bg-info ms-1"><?= count($examens_videos) ?></span>
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="sidebar-filters p-3">
            <input type="text" class="form-control form-control-sm" id="filterExamen" 
                   placeholder="Filtrer par patient, examen...">
        </div>
        
        <div class="tab-content" id="pacsTabContent">
            <!-- Onglet Images -->
            <div class="tab-pane fade show active" id="images" role="tabpanel">
                <div class="examens-list" id="imagesList">
                    <?php if (empty($examens_images)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-image fs-1"></i>
                            <p class="mb-0">Aucune image disponible</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($examens_images as $ex): ?>
                        <div class="examen-item" onclick="chargerImages(<?= $ex['idexamen'] ?>, '<?= addslashes($ex['code_examen']) ?>')">
                            <div class="d-flex align-items-center">
                                <div class="examen-icon me-2">
                                    <i class="bi bi-file-earmark-image" style="color: #0dcaf0;"></i>
                                </div>
                                <div class="examen-info">
                                    <strong><?= htmlspecialchars($ex['patient_nom'] . ' ' . $ex['patient_prenom']) ?></strong>
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($ex['type_examen']) ?> - <?= formatDate($ex['date_rdv'], 'd/m/Y') ?>
                                    </div>
                                    <code class="small"><?= htmlspecialchars($ex['code_examen']) ?></code>
                                </div>
                                <?php if ($ex['qualite_images']): ?>
                                <span class="badge bg-<?= $ex['qualite_images'] === 'excellente' ? 'success' : 'info' ?> ms-auto">
                                    <?= $ex['qualite_images'] ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Onglet Vidéos -->
            <div class="tab-pane fade" id="videos" role="tabpanel">
                <div class="examens-list" id="videosList">
                    <?php if (empty($examens_videos)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-camera-reels fs-1"></i>
                            <p class="mb-0">Aucune vidéo disponible</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($examens_videos as $ex): ?>
                        <div class="examen-item" onclick="chargerVideo(<?= $ex['idexamen'] ?>, '<?= addslashes($ex['code_examen']) ?>')">
                            <div class="d-flex align-items-center">
                                <div class="examen-icon me-2">
                                    <i class="bi bi-camera-reels" style="color: #10b981;"></i>
                                </div>
                                <div class="examen-info">
                                    <strong><?= htmlspecialchars($ex['patient_nom'] . ' ' . $ex['patient_prenom']) ?></strong>
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($ex['type_examen']) ?> - <?= formatDate($ex['date_rdv'], 'd/m/Y') ?>
                                    </div>
                                    <code class="small"><?= htmlspecialchars($ex['code_examen']) ?></code>
                                </div>
                                <i class="bi bi-play-circle-fill text-success ms-auto" style="font-size: 1.2rem;"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Zone principale de visualisation -->
    <div class="pacs-viewer">
        <div class="viewer-header">
            <h5 id="viewer-title"><i class="bi bi-eye"></i> Sélectionnez un examen</h5>
            <div class="viewer-tools">
                <button class="btn btn-sm btn-outline-secondary" onclick="zoomIn()" title="Zoom avant">
                    <i class="bi bi-zoom-in"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="zoomOut()" title="Zoom arrière">
                    <i class="bi bi-zoom-out"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="resetZoom()" title="Réinitialiser">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="rotateImage()" title="Pivoter">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
                <div class="btn-group ms-2">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" onclick="exporterImage('jpg')">JPEG</a></li>
                        <li><a class="dropdown-item" href="#" onclick="exporterImage('png')">PNG</a></li>
                        <li><a class="dropdown-item" href="#" onclick="exporterImage('dicom')">DICOM</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="viewer-content" id="viewerContent">
            <div class="placeholder-viewer">
                <i class="bi bi-images"></i>
                <p>Sélectionnez un examen dans la liste de gauche</p>
            </div>
        </div>
        
        <div class="viewer-footer" id="viewerFooter" style="display: none;">
            <div class="row">
                <div class="col-md-8">
                    <div class="d-flex gap-2">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="changerContraste(0.1)">
                                <i class="bi bi-sun"></i> Contraste +
                            </button>
                            <button class="btn btn-outline-primary" onclick="changerLuminosite(0.1)">
                                <i class="bi bi-brightness-high"></i> Luminosité +
                            </button>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-secondary" onclick="inverserCouleurs()">
                                <i class="bi bi-palette"></i> Inverser
                            </button>
                            <button class="btn btn-outline-secondary" onclick="modeCinema()">
                                <i class="bi bi-camera-reels"></i> Cinéma
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-info" onclick="ajouterAnnotation()">
                            <i class="bi bi-pencil"></i> Annoter
                        </button>
                        <button class="btn btn-outline-danger" onclick="effacerAnnotations()">
                            <i class="bi bi-eraser"></i> Effacer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Panneau d'informations latéral droit -->
    <div class="pacs-info" id="pacsInfo" style="display: none;">
        <div class="info-header">
            <h6><i class="bi bi-info-circle"></i> Informations</h6>
            <button class="btn btn-sm btn-link" onclick="fermerInfos()">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="info-content" id="infoContent"></div>
        
        <div class="info-annotations mt-3">
            <h6><i class="bi bi-pencil-square"></i> Annotations</h6>
            <textarea class="form-control form-control-sm" rows="3" placeholder="Ajouter une annotation..."></textarea>
            <button class="btn btn-sm btn-primary w-100 mt-2">Sauvegarder</button>
        </div>
        
        <div class="info-series mt-3">
            <h6><i class="bi bi-layers"></i> Série d'images</h6>
            <div class="series-thumbnails" id="seriesThumbnails"></div>
        </div>
    </div>
</div>

<!-- Scripts pour la visualisation -->
<script>
let currentExamenId = null;
let currentType = null;
let zoomLevel = 1;
let rotationAngle = 0;
let contrasteLevel = 1;
let luminositeLevel = 1;

// =============================================
// CHARGEMENT DES IMAGES
// =============================================
function chargerImages(examenId, codeExamen) {
    currentExamenId = examenId;
    currentType = 'image';
    
    const viewer = document.getElementById('viewerContent');
    const footer = document.getElementById('viewerFooter');
    const info = document.getElementById('pacsInfo');
    const title = document.getElementById('viewer-title');
    
    title.innerHTML = `<i class="bi bi-image"></i> ${codeExamen}`;
    
    // Simulation de chargement d'images DICOM
    viewer.innerHTML = `
        <div class="image-viewer" id="imageViewer">
            <div class="dicom-viewer" style="transform: scale(${zoomLevel}) rotate(${rotationAngle}deg);">
                <img src="https://via.placeholder.com/800x600/0dcaf0/ffffff?text=DICOM+Image+${examenId}" 
                     class="img-fluid" alt="Image DICOM">
                <div class="dicom-overlay">
                    <div class="overlay-info">Patient: *** | Examen: ${codeExamen}</div>
                    <div class="overlay-window">
                        <span>WW: 400</span> | <span>WL: 40</span>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    footer.style.display = 'block';
    info.style.display = 'block';
    
    // Charger les informations
    chargerInformations(examenId);
    chargerSeries(examenId);
}

// =============================================
// CHARGEMENT DES VIDÉOS
// =============================================
function chargerVideo(examenId, codeExamen) {
    currentExamenId = examenId;
    currentType = 'video';
    
    const viewer = document.getElementById('viewerContent');
    const footer = document.getElementById('viewerFooter');
    const info = document.getElementById('pacsInfo');
    const title = document.getElementById('viewer-title');
    
    title.innerHTML = `<i class="bi bi-camera-reels"></i> ${codeExamen}`;
    
    // Simulation de vidéo avec contrôle
    viewer.innerHTML = `
        <div class="video-viewer">
            <video controls class="w-100" style="max-height: 500px;">
                <source src="https://sample-videos.com/video123/mp4/720/big_buck_bunny_720p_1mb.mp4" type="video/mp4">
                Votre navigateur ne supporte pas la lecture vidéo.
            </video>
            <div class="video-timeline mt-2">
                <div class="progress" style="height: 4px;">
                    <div class="progress-bar bg-info" style="width: 0%;" id="videoProgress"></div>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <small>Début</small>
                    <small id="videoTime">00:00 / 00:00</small>
                    <small>Fin</small>
                </div>
            </div>
        </div>
    `;
    
    footer.style.display = 'block';
    info.style.display = 'block';
    
    // Ajouter écouteurs vidéo
    const video = document.querySelector('video');
    if (video) {
        video.addEventListener('timeupdate', function() {
            const progress = (video.currentTime / video.duration) * 100;
            document.getElementById('videoProgress').style.width = progress + '%';
            document.getElementById('videoTime').innerHTML = 
                formatTime(video.currentTime) + ' / ' + formatTime(video.duration);
        });
    }
    
    chargerInformations(examenId);
}

// =============================================
// INFORMATIONS DE L'EXAMEN
// =============================================
function chargerInformations(examenId) {
    // Simulation de données
    const infoContent = document.getElementById('infoContent');
    infoContent.innerHTML = `
        <table class="table table-sm">
            <tr>
                <th>Patient:</th>
                <td>Jean Dupont</td>
            </tr>
            <tr>
                <th>N° Dossier:</th>
                <td>PAT-2024-00123</td>
            </tr>
            <tr>
                <th>Date naiss.:</th>
                <td>15/03/1985 (39 ans)</td>
            </tr>
            <tr>
                <th>Examen:</th>
                <td>Scanner abdominal</td>
            </tr>
            <tr>
                <th>Date examen:</th>
                <td>${new Date().toLocaleDateString('fr-FR')}</td>
            </tr>
            <tr>
                <th>Radiologue:</th>
                <td>Dr. Martin Dubois</td>
            </tr>
            <tr>
                <th>Qualité:</th>
                <td><span class="badge bg-success">Excellente</span></td>
            </tr>
            <tr>
                <th>Série:</th>
                <td>512 x 512 x 120</td>
            </tr>
            <tr>
                <th>Taille:</th>
                <td>124.5 MB</td>
            </tr>
        </table>
        
        <div class="mt-2">
            <strong>Conclusion:</strong>
            <p class="small">Pas d'anomalie détectée. Examen dans les limites de la normale.</p>
        </div>
    `;
}

// =============================================
// SÉRIE D'IMAGES (THUMBNAILS)
// =============================================
function chargerSeries(examenId) {
    const seriesDiv = document.getElementById('seriesThumbnails');
    seriesDiv.innerHTML = '';
    
    for (let i = 1; i <= 12; i++) {
        const thumb = document.createElement('div');
        thumb.className = 'series-thumb';
        thumb.innerHTML = `<img src="https://via.placeholder.com/60x60/0dcaf0/ffffff?text=${i}" alt="Série ${i}">`;
        thumb.onclick = function() { changerSerie(i); };
        seriesDiv.appendChild(thumb);
    }
}

function changerSerie(numero) {
    const viewer = document.getElementById('imageViewer');
    if (viewer) {
        viewer.innerHTML = `
            <img src="https://via.placeholder.com/800x600/0dcaf0/ffffff?text=Série+${numero}" 
                 class="img-fluid" alt="Série ${numero}">
        `;
    }
}

// =============================================
// OUTILS DE VISUALISATION
// =============================================
function zoomIn() {
    zoomLevel += 0.1;
    appliquerTransformations();
}

function zoomOut() {
    if (zoomLevel > 0.3) {
        zoomLevel -= 0.1;
        appliquerTransformations();
    }
}

function resetZoom() {
    zoomLevel = 1;
    rotationAngle = 0;
    contrasteLevel = 1;
    luminositeLevel = 1;
    appliquerTransformations();
}

function rotateImage() {
    rotationAngle += 90;
    appliquerTransformations();
}

function changerContraste(valeur) {
    contrasteLevel += valeur;
    appliquerTransformations();
}

function changerLuminosite(valeur) {
    luminositeLevel += valeur;
    appliquerTransformations();
}

function inverserCouleurs() {
    const viewer = document.querySelector('.dicom-viewer');
    if (viewer) {
        viewer.style.filter = viewer.style.filter === 'invert(1)' ? 'none' : 'invert(1)';
    }
}

function appliquerTransformations() {
    const viewer = document.querySelector('.dicom-viewer');
    if (viewer) {
        viewer.style.transform = `scale(${zoomLevel}) rotate(${rotationAngle}deg)`;
        viewer.style.filter = `contrast(${contrasteLevel}) brightness(${luminositeLevel})`;
    }
}

// =============================================
// MODE CINÉMA (DÉFILEMENT AUTOMATIQUE)
// =============================================
let cinemaInterval = null;
let cinemaIndex = 0;

function modeCinema() {
    if (cinemaInterval) {
        clearInterval(cinemaInterval);
        cinemaInterval = null;
        alert('Mode cinéma désactivé');
    } else {
        cinemaIndex = 0;
        cinemaInterval = setInterval(() => {
            cinemaIndex++;
            if (cinemaIndex > 12) cinemaIndex = 1;
            changerSerie(cinemaIndex);
        }, 500);
        alert('Mode cinéma activé');
    }
}

// =============================================
// ANNOTATIONS
// =============================================
function ajouterAnnotation() {
    alert('Outil d\'annotation : cliquez sur l\'image pour ajouter une annotation');
    // Ici, vous implémenteriez un système d'annotation interactif
}

function effacerAnnotations() {
    if (confirm('Effacer toutes les annotations ?')) {
        alert('Annotations effacées');
    }
}

// =============================================
// EXPORT
// =============================================
function exporterImage(format) {
    if (!currentExamenId) {
        alert('Veuillez d\'abord sélectionner un examen');
        return;
    }
    alert(`Export de l'image au format ${format} (simulation)`);
    // Implémenter l'export réel vers le serveur
}

// =============================================
// UTILITAIRES
// =============================================
function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return '00:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

function toggleFullscreen() {
    const container = document.querySelector('.pacs-container');
    if (!document.fullscreenElement) {
        container.requestFullscreen();
    } else {
        document.exitFullscreen();
    }
}

function fermerInfos() {
    document.getElementById('pacsInfo').style.display = 'none';
}

// =============================================
// FILTRAGE DE LA LISTE
// =============================================
document.getElementById('filterExamen').addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    
    document.querySelectorAll('#imagesList .examen-item').forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
    
    document.querySelectorAll('#videosList .examen-item').forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(search) ? 'block' : 'none';
    });
});
</script>

<style>
.pacs-container {
    display: grid;
    grid-template-columns: 300px 1fr 250px;
    gap: 15px;
    height: calc(100vh - 200px);
}

.pacs-sidebar {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.sidebar-header .nav-tabs {
    border-bottom: 2px solid #e9ecef;
}

.examens-list {
    flex: 1;
    overflow-y: auto;
    padding: 10px;
}

.examen-item {
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 5px;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.examen-item:hover {
    background: #f8f9fa;
    border-color: #0dcaf0;
}

.examen-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.pacs-viewer {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.viewer-header {
    padding: 15px;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.viewer-content {
    flex: 1;
    min-height: 400px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    position: relative;
    overflow: hidden;
}

.placeholder-viewer {
    text-align: center;
    color: #adb5bd;
}

.placeholder-viewer i {
    font-size: 4rem;
    margin-bottom: 1rem;
}

.dicom-viewer {
    transition: transform 0.2s, filter 0.2s;
    position: relative;
}

.dicom-overlay {
    position: absolute;
    bottom: 10px;
    left: 10px;
    right: 10px;
    display: flex;
    justify-content: space-between;
    color: white;
    text-shadow: 1px 1px 2px black;
    background: rgba(0,0,0,0.5);
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
}

.viewer-footer {
    padding: 15px;
    border-top: 1px solid #e9ecef;
}

.pacs-info {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 15px;
    overflow-y: auto;
}

.info-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.series-thumbnails {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 5px;
    margin-top: 10px;
}

.series-thumb {
    cursor: pointer;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    padding: 2px;
    transition: all 0.2s;
}

.series-thumb:hover {
    border-color: #0dcaf0;
    transform: scale(1.05);
}

.series-thumb img {
    width: 100%;
    height: auto;
    border-radius: 2px;
}

.video-viewer video {
    width: 100%;
    max-height: 500px;
    border-radius: 8px;
}

@media (max-width: 1200px) {
    .pacs-container {
        grid-template-columns: 250px 1fr 200px;
    }
}

@media (max-width: 992px) {
    .pacs-container {
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr auto;
    }
}
</style>