<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();
$sous_sejour_id = $_GET['sous_sejour_id'] ?? null;

if (!$sous_sejour_id) {
    redirect('salle-attente.php');
}

// RÃ©cupÃ©rer les informations du patient et du sÃ©jour
$query = "SELECT p.*, s.*, ss.*, MAX(ap.urgent) as urgent,
    c.nom as categorie_nom,
    g.nom as groupe_sanguin,
    um.nom as unite_medicale
    FROM sous_sejour ss
    JOIN sejour s ON ss.idsejour = s.idsejour
    JOIN patient p ON s.idpatient = p.idpatient
    LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
    LEFT JOIN grsanguin g ON p.idgrsanguin = g.idgrsanguin
    LEFT JOIN unite_med um ON ss.idunite_med = um.idunite_med
    LEFT JOIN actes_presc ap ON ss.idsous_sejour = ap.idsous_sejour
    WHERE ss.idsous_sejour = :id
    GROUP BY ss.idsous_sejour";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $sous_sejour_id);
$stmt->execute();
$data = $stmt->fetch();

if (!$data) {
    redirect('salle-attente.php');
}

$consultation_model = new Consultation($db);

// RÃ©cupÃ©rer les signes vitaux
$signes_vitaux = $consultation_model->getSignesVitaux($sous_sejour_id);

// RÃ©cupÃ©rer les antÃ©cÃ©dents
try {
    $query_ant = "SELECT * FROM antecedents_patients WHERE idpatient = :idpatient ORDER BY date_creation DESC";
    $stmt_ant = $db->prepare($query_ant);
    $stmt_ant->bindParam(':idpatient', $data['idpatient']);
    $stmt_ant->execute();
    $antecedents = $stmt_ant->fetchAll();
} catch (Exception $e) {
    $antecedents = [];
    error_log("Table antecedents_patients non disponible: " . $e->getMessage());
}

// RÃ©cupÃ©rer les allergies
try {
    $query_all = "SELECT * FROM allergies_patients WHERE idpatient = :idpatient ORDER BY date_creation DESC";
    $stmt_all = $db->prepare($query_all);
    $stmt_all->bindParam(':idpatient', $data['idpatient']);
    $stmt_all->execute();
    $allergies = $stmt_all->fetchAll();
} catch (Exception $e) {
    $allergies = [];
    error_log("Table allergies_patients non disponible: " . $e->getMessage());
}

$success = '';
$error = '';

// Traitement de la sauvegarde de consultation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_consultation'])) {
    try {
        $db->beginTransaction();
        
        // Enregistrer la consultation
        $consultation_data = [
            'idsous_sejour' => $sous_sejour_id,
            'idutilisateur' => $_SESSION['user_id'],
            'motif_consultation' => sanitizeInput($_POST['motif_consultation'] ?? ''),
            'anamnese' => sanitizeInput($_POST['anamnese'] ?? ''),
            'examen_clinique' => sanitizeInput($_POST['examen_clinique'] ?? ''),
            'hypothese_diagnostique' => sanitizeInput($_POST['hypothese_diagnostique'] ?? ''),
            'conduite_tenir' => sanitizeInput($_POST['conduite_tenir'] ?? '')
        ];
        
        $idconsultation = $consultation_model->createConsultation($consultation_data);
        
        if ($idconsultation) {
            // Marquer les actes comme en cours d'exÃ©cution
            $query_update = "UPDATE actes_presc 
                SET statut_execution = 'en_cours',
                    date_execution = NOW(),
                    executeur = :executeur
                WHERE idsous_sejour = :idsous_sejour 
                AND statut_execution = 'en_attente'";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->bindParam(':executeur', $_SESSION['user_id']);
            $stmt_update->bindParam(':idsous_sejour', $sous_sejour_id);
            $stmt_update->execute();
            
            // Faire de mÃªme pour la pharmacie
            $query_update_pharma = "UPDATE pharma_presc 
                SET statut_execution = 'en_cours',
                    date_execution = NOW(),
                    executeur = :executeur
                WHERE idsous_sejour = :idsous_sejour 
                AND statut_execution = 'en_attente'";
            $stmt_update_pharma = $db->prepare($query_update_pharma);
            $stmt_update_pharma->bindParam(':executeur', $_SESSION['user_id']);
            $stmt_update_pharma->bindParam(':idsous_sejour', $sous_sejour_id);
            $stmt_update_pharma->execute();
            
            $db->commit();
            $success = "Consultation enregistrÃ©e avec succÃ¨s ! Les services concernÃ©s ont Ã©tÃ© notifiÃ©s.";
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Erreur : " . $e->getMessage();
    }
}

// Traitement ajout signe vital
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_signe_vital'])) {
    $signe_data = [
        'idpatient' => $data['idpatient'],
        'idsous_sejour' => $sous_sejour_id,
        'idtypeparamvitaux' => $_POST['idtypeparamvitaux'],
        'valeur' => $_POST['valeur'],
        'idutilisateur' => $_SESSION['user_id']
    ];
    
    if ($consultation_model->addSigneVital($signe_data)) {
        $success = "Signe vital ajoutÃ© !";
        $signes_vitaux = $consultation_model->getSignesVitaux($sous_sejour_id);
    }
}

$pageTitle = "Consultation - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="consultation-interface">
    <!-- En-tÃªte patient -->
    <div class="patient-header">
        <div class="patient-identity">
            <div class="patient-avatar">
                <?php echo strtoupper(substr($data['nom'], 0, 1) . substr($data['prenom'], 0, 1)); ?>
            </div>
            <div class="patient-details">
                <h2><?php echo htmlspecialchars($data['nom'] . ' ' . $data['prenom']); ?></h2>
                <div class="patient-meta">
                    <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($data['numero_dossier']); ?></span>
                    <span><i class="fas fa-birthday-cake"></i> 
                        <?php 
                        $birthDate = new DateTime($data['date_naissance']);
                        $today = new DateTime();
                        $age = $today->diff($birthDate);
                        echo $age->y . ' ans (' . formatDate($data['date_naissance']) . ')';
                        ?>
                    </span>
                    <span><i class="fas fa-venus-mars"></i> <?php echo $data['sexe'] === 'M' ? 'Masculin' : 'FÃ©minin'; ?></span>
                    <?php if ($data['groupe_sanguin']): ?>
                    <span><i class="fas fa-tint"></i> <?php echo htmlspecialchars($data['groupe_sanguin']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="patient-alerts">
            <?php if (!empty($allergies)): ?>
            <div class="alert-badge alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>ALLERGIES</strong>
                    <small><?php echo count($allergies); ?> allergie(s)</small>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($data['urgent']): ?>
            <div class="alert-badge alert-urgent">
                <i class="fas fa-bolt"></i>
                <div>
                    <strong>URGENT</strong>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <div class="consultation-container">
        <!-- Sidebar gauche -->
        <div class="consultation-sidebar">
            <!-- Signes vitaux -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-heartbeat"></i> Signes Vitaux</h3>
                    <button class="btn btn-sm btn-primary" onclick="openSigneVitalModal()">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($signes_vitaux)): ?>
                    <p class="text-muted">Aucun signe vital enregistrÃ©</p>
                    <?php else: ?>
                    <div class="signes-list">
                        <?php foreach (array_slice($signes_vitaux, 0, 5) as $signe): ?>
                        <div class="signe-item">
                            <span class="signe-label"><?php echo htmlspecialchars($signe['type_parametre']); ?></span>
                            <span class="signe-value"><?php echo htmlspecialchars($signe['valeur']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AntÃ©cÃ©dents -->
            <div class="card mt-2">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-history"></i> AntÃ©cÃ©dents</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($antecedents)): ?>
                    <p class="text-muted">Aucun antÃ©cÃ©dent</p>
                    <?php else: ?>
                    <ul class="antecedents-list">
                        <?php foreach ($antecedents as $ant): ?>
                        <li><?php echo htmlspecialchars($ant['libelle']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Allergies -->
            <div class="card mt-2">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-allergies"></i> Allergies</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($allergies)): ?>
                    <p class="text-muted">Aucune allergie connue</p>
                    <?php else: ?>
                    <ul class="allergies-list">
                        <?php foreach ($allergies as $all): ?>
                        <li class="allergie-item">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($all['libelle']); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Formulaire de consultation principal -->
        <div class="consultation-main">
            <form method="POST" class="consultation-form">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-medical"></i> Fiche de Consultation</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-section">
                            <h4><i class="fas fa-comment-medical"></i> Motif de Consultation</h4>
                            <textarea name="motif_consultation" class="form-control" rows="2" 
                                placeholder="Raison de la visite..." required></textarea>
                        </div>

                        <div class="form-section">
                            <h4><i class="fas fa-notes-medical"></i> AnamnÃ¨se</h4>
                            <textarea name="anamnese" class="form-control" rows="4" 
                                placeholder="Histoire de la maladie, symptÃ´mes, Ã©volution..." required></textarea>
                        </div>

                        <div class="form-section">
                            <h4><i class="fas fa-stethoscope"></i> Examen Clinique</h4>
                            <textarea name="examen_clinique" class="form-control" rows="5" 
                                placeholder="Observations de l'examen physique..." required></textarea>
                        </div>

                        <div class="form-section">
                            <h4><i class="fas fa-diagnoses"></i> HypothÃ¨se Diagnostique</h4>
                            <textarea name="hypothese_diagnostique" class="form-control" rows="3" 
                                placeholder="Diagnostic probable..." required></textarea>
                        </div>

                        <div class="form-section">
                            <h4><i class="fas fa-procedures"></i> Conduite Ã  Tenir</h4>
                            <textarea name="conduite_tenir" class="form-control" rows="4" 
                                placeholder="Traitement, examens complÃ©mentaires, suivi..." required></textarea>
                        </div>
                    </div>
                </div>

                <div class="consultation-actions">
                    <a href="salle-attente.php" class="btn btn-outline btn-lg">
                        <i class="fas fa-times"></i> Annuler
                    </a>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="openPrescriptionModal()">
                        <i class="fas fa-prescription"></i> Prescrire
                    </button>
                    <button type="submit" name="save_consultation" class="btn btn-primary btn-lg">
                        <i class="fas fa-save"></i> Enregistrer Consultation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Signes Vitaux -->
<div id="signeVitalModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Ajouter un Signe Vital</h3>
            <button class="modal-close" onclick="closeSigneVitalModal()">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label>Type de paramÃ¨tre</label>
                    <select name="idtypeparamvitaux" id="idtypeparamvitaux" class="form-control" required onchange="updateValeurPlaceholder()">
                        <option value="">-- SÃ©lectionner --</option>
                        <?php
                        // RÃ©cupÃ©rer les types de paramÃ¨tres vitaux
                        $query_types = "SELECT * FROM typeparamvitaux ORDER BY nom";
                        $stmt_types = $db->prepare($query_types);
                        $stmt_types->execute();
                        $types_param = $stmt_types->fetchAll();
                        
                        foreach ($types_param as $type): 
                        ?>
                        <option value="<?php echo $type['idtypeparamvitaux']; ?>" 
                                data-min="<?php echo $type['valeur_min']; ?>" 
                                data-max="<?php echo $type['valeur_max']; ?>"
                                data-unite="<?php echo htmlspecialchars($type['unite']); ?>">
                            <?php echo htmlspecialchars($type['nom'] . ' (' . $type['unite'] . ') - Normale: ' . $type['valeur_min'] . ' Ã  ' . $type['valeur_max']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small id="rangeInfo" class="text-muted">Plage normale: -</small>
                </div>
                <div class="form-group">
                    <label>Valeur</label>
                    <input type="text" name="valeur" id="valeurInput" class="form-control" 
                        placeholder="SÃ©lectionnez d'abord un paramÃ¨tre" required>
                    <small id="uniteInfo" class="text-muted"></small>
                </div>
                <div class="alert alert-info" id="valeurAlert" style="display: none;">
                    <i class="fas fa-info-circle"></i>
                    <span id="alertText"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeSigneVitalModal()">Annuler</button>
                <button type="submit" name="add_signe_vital" class="btn btn-primary" id="submitBtn">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<style>
.consultation-interface {
    max-width: 1600px;
    margin: 0 auto;
}

.patient-header {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: var(--shadow);
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.patient-identity {
    display: flex;
    gap: 20px;
    align-items: center;
}

.patient-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: bold;
}

.patient-details h2 {
    margin: 0 0 10px 0;
    font-size: 24px;
    color: var(--dark);
}

.patient-meta {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    font-size: 14px;
    color: #64748b;
}

.patient-meta span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.patient-alerts {
    display: flex;
    gap: 15px;
}

.alert-badge {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 20px;
    border-radius: 8px;
    font-weight: 600;
}

.alert-badge.alert-danger {
    background: #fee2e2;
    color: #991b1b;
    border: 2px solid #dc2626;
}

.alert-badge.alert-urgent {
    background: #fef3c7;
    color: #92400e;
    border: 2px solid #f59e0b;
    animation: pulse 2s infinite;
}

.alert-badge i {
    font-size: 20px;
}

.consultation-container {
    display: grid;
    grid-template-columns: 350px 1fr;
    gap: 25px;
}

.consultation-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.signes-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.signe-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: var(--light);
    border-radius: 6px;
}

.signe-label {
    font-size: 13px;
    color: #64748b;
}

.signe-value {
    font-weight: 600;
    color: var(--primary);
    font-size: 15px;
}

.antecedents-list,
.allergies-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.antecedents-list li {
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
}

.antecedents-list li:last-child {
    border-bottom: none;
}

.allergie-item {
    padding: 8px 12px;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 6px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

.consultation-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-section {
    margin-bottom: 25px;
}

.form-section h4 {
    font-size: 16px;
    color: var(--primary);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-section textarea {
    font-size: 14px;
    line-height: 1.6;
}

.consultation-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    padding: 20px;
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
}

.text-muted {
    color: #94a3b8;
    font-size: 14px;
    font-style: italic;
}

/* Styles pour les alertes de validation */
.alert {
    padding: 12px;
    border-radius: 6px;
    margin-top: 10px;
    font-size: 13px;
}

.alert-warning {
    background: #fef3c7;
    color: #92400e;
    border-left: 4px solid #f59e0b;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #dc2626;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-info {
    background: #dbeafe;
    color: #1e40af;
    border-left: 4px solid #3b82f6;
}

/* Modal styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #64748b;
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

@media (max-width: 1200px) {
    .consultation-container {
        grid-template-columns: 1fr;
    }
    
    .consultation-sidebar {
        order: 2;
    }
}
</style>

<script>
function updateValeurPlaceholder() {
    const select = document.getElementById('idtypeparamvitaux');
    const valeurInput = document.getElementById('valeurInput');
    const rangeInfo = document.getElementById('rangeInfo');
    const uniteInfo = document.getElementById('uniteInfo');
    const valeurAlert = document.getElementById('valeurAlert');
    const alertText = document.getElementById('alertText');
    const submitBtn = document.getElementById('submitBtn');
    
    if (select.value) {
        const selectedOption = select.options[select.selectedIndex];
        const min = selectedOption.getAttribute('data-min');
        const max = selectedOption.getAttribute('data-max');
        const unite = selectedOption.getAttribute('data-unite');
        const nom = selectedOption.text.split(' - ')[0];
        
        // Mettre Ã  jour les informations
        rangeInfo.textContent = `Plage normale: ${min} Ã  ${max} ${unite}`;
        uniteInfo.textContent = `UnitÃ©: ${unite}`;
        valeurInput.placeholder = `Ex: ${(parseFloat(min) + parseFloat(max)) / 2} ${unite}`;
        
        // RÃ©initialiser l'alerte
        valeurAlert.style.display = 'none';
        submitBtn.disabled = false;
        
        // Ajouter la validation en temps rÃ©el
        valeurInput.oninput = function() {
            validateValeur(this.value, min, max, unite, nom);
        };
    } else {
        rangeInfo.textContent = 'Plage normale: -';
        uniteInfo.textContent = '';
        valeurInput.placeholder = 'SÃ©lectionnez d\'abord un paramÃ¨tre';
        valeurAlert.style.display = 'none';
    }
}

function validateValeur(valeur, min, max, unite, nom) {
    const valeurAlert = document.getElementById('valeurAlert');
    const alertText = document.getElementById('alertText');
    const submitBtn = document.getElementById('submitBtn');
    
    if (!valeur) {
        valeurAlert.style.display = 'none';
        submitBtn.disabled = false;
        return;
    }
    
    const numValeur = parseFloat(valeur);
    
    if (isNaN(numValeur)) {
        valeurAlert.style.display = 'block';
        valeurAlert.className = 'alert alert-warning';
        alertText.textContent = 'Veuillez entrer une valeur numÃ©rique';
        submitBtn.disabled = true;
        return;
    }
    
    if (numValeur < parseFloat(min)) {
        valeurAlert.style.display = 'block';
        valeurAlert.className = 'alert alert-danger';
        alertText.textContent = `âš ï¸ VALEUR BASSE: ${valeur} ${unite} (min: ${min} ${unite}) - ${nom}`;
        submitBtn.disabled = false;
    } else if (numValeur > parseFloat(max)) {
        valeurAlert.style.display = 'block';
        valeurAlert.className = 'alert alert-danger';
        alertText.textContent = `âš ï¸ VALEUR Ã‰LEVÃ‰E: ${valeur} ${unite} (max: ${max} ${unite}) - ${nom}`;
        submitBtn.disabled = false;
    } else {
        valeurAlert.style.display = 'block';
        valeurAlert.className = 'alert alert-success';
        alertText.textContent = `âœ… VALEUR NORMALE: ${valeur} ${unite} (normale: ${min}-${max} ${unite})`;
        submitBtn.disabled = false;
    }
}

function openSigneVitalModal() {
    document.getElementById('signeVitalModal').classList.add('active');
    // RÃ©initialiser le formulaire
    document.getElementById('idtypeparamvitaux').value = '';
    document.getElementById('valeurInput').value = '';
    document.getElementById('rangeInfo').textContent = 'Plage normale: -';
    document.getElementById('uniteInfo').textContent = '';
    document.getElementById('valeurAlert').style.display = 'none';
}

function closeSigneVitalModal() {
    document.getElementById('signeVitalModal').classList.remove('active');
}

function openPrescriptionModal() {
    window.open('prescription-quick.php?sous_sejour_id=<?php echo $sous_sejour_id; ?>', 
        'Prescription', 
        'width=1000,height=700,scrollbars=yes');
}

// Confirmation avant de quitter si formulaire modifiÃ©
let formModified = false;
document.querySelectorAll('textarea, input').forEach(element => {
    element.addEventListener('input', () => {
        formModified = true;
    });
});

window.addEventListener('beforeunload', (e) => {
    if (formModified) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.querySelector('.consultation-form').addEventListener('submit', () => {
    formModified = false;
});
</script>

<?php include '../views/includes/footer.php'; ?>