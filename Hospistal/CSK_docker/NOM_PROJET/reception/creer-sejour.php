<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../models/Patient.php';
require_once '../models/Sejour.php';
require_once '../models/Urgence.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$patient_id = $_GET['patient_id'] ?? null;

if (!$patient_id) {
    redirect('recherche-patient.php');
}

// RÃ©cupÃ©rer les informations du patient
$patient = new Patient($db);
$patientData = $patient->getById($patient_id);

if (!$patientData) {
    redirect('recherche-patient.php?error=patient_not_found');
}

// RÃ©cupÃ©rer les donnÃ©es nÃ©cessaires
$unites = $db->query("SELECT * FROM unite_med WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$unites_hospitalisation = $db->query("SELECT * FROM unite_hospi WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$motifs_ambulatoire = $db->query("SELECT * FROM motif WHERE type_sejour = 'ambulatoire' ORDER BY libelle")->fetchAll(PDO::FETCH_ASSOC);
$motifs_hospitalisation = $db->query("SELECT * FROM motif WHERE type_sejour = 'hospitalisation' ORDER BY libelle")->fetchAll(PDO::FETCH_ASSOC);
$motifs_urgence = $db->query("SELECT * FROM motif WHERE type_sejour = 'urgence' ORDER BY libelle")->fetchAll(PDO::FETCH_ASSOC);
$origines = $db->query("SELECT * FROM origine WHERE actif = 1 ORDER BY libelle")->fetchAll(PDO::FETCH_ASSOC);

$success = '';
$error = '';

// Traitement du formulaire d'admission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sejour'])) {
    try {
        $type_sejour = $_POST['type_sejour'] ?? '';
        $idunite_med = $_POST['idunite_med'] ?? null;
        $idmotif_input = $_POST['idmotif'] ?? null; // Renommer pour Ã©viter la confusion
        $idorigine = $_POST['idorigine'] ?? null;
        $motif_autre = sanitizeInput($_POST['motif_autre'] ?? '');
        $observation = sanitizeInput($_POST['observation'] ?? '');
        $urgent = isset($_POST['urgent']) ? 1 : 0;
        $anciennete = $_POST['anciennete'] ?? 'nouveau';
        
        // Validation basique
        if (!$idunite_med) {
            throw new Exception("Veuillez sÃ©lectionner une unitÃ© mÃ©dicale.");
        }
        
        // GESTION DU MOTIF (REVISÃ‰E)
        $idmotif = null;
        
        if ($idmotif_input === 'autre') {
            // Si l'utilisateur a sÃ©lectionnÃ© "Autre motif..."
            if (empty($motif_autre)) {
                throw new Exception("Veuillez prÃ©ciser le motif.");
            }
            
            error_log("DEBUG: CrÃ©ation d'un nouveau motif 'Autre'");
            error_log("DEBUG: motif_autre = " . $motif_autre);
            error_log("DEBUG: type_sejour = " . $type_sejour);
            
            // VÃ©rifier d'abord si ce motif existe dÃ©jÃ 
            $query_check = "SELECT idmotif FROM motif WHERE motif_autre = :motif_autre AND type_sejour = :type_sejour LIMIT 1";
            $stmt_check = $db->prepare($query_check);
            $stmt_check->execute([
                ':motif_autre' => $motif_autre,
                ':type_sejour' => $type_sejour
            ]);
            
            $existing_motif = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_motif) {
                // Utiliser le motif existant
                $idmotif = $existing_motif['idmotif'];
                error_log("DEBUG: Utilisation motif existant idmotif = " . $idmotif);
            } else {
                // CrÃ©er un nouveau motif avec libelle = NULL et motif_autre rempli
                $query = "INSERT INTO motif (libelle, motif_autre, type_sejour, actif) 
                         VALUES (NULL, :motif_autre, :type_sejour, 1)";
                
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':motif_autre' => $motif_autre,
                    ':type_sejour' => $type_sejour
                ]);
                
                // RÃ©cupÃ©rer l'ID du nouveau motif crÃ©Ã©
                $idmotif = $db->lastInsertId();
                error_log("DEBUG: Nouveau motif crÃ©Ã© avec idmotif = " . $idmotif);
            }
            
        } else if (!empty($idmotif_input) && is_numeric($idmotif_input)) {
            // VÃ©rifier que le motif sÃ©lectionnÃ© existe
            $query_motif = "SELECT idmotif, libelle, motif_autre, type_sejour FROM motif 
                           WHERE idmotif = :idmotif AND actif = 1";
            $stmt_motif = $db->prepare($query_motif);
            $stmt_motif->execute([':idmotif' => $idmotif_input]);
            $motif_data = $stmt_motif->fetch(PDO::FETCH_ASSOC);
            
            if (!$motif_data) {
                throw new Exception("Motif invalide sÃ©lectionnÃ©.");
            }
            
            // VÃ©rifier que le type de sÃ©jour correspond
            if ($motif_data['type_sejour'] !== $type_sejour) {
                // Si le type ne correspond pas, crÃ©er un nouveau motif
                error_log("DEBUG: Type de sÃ©jour ne correspond pas, crÃ©ation d'un nouveau motif");
                
                // CrÃ©er un nouveau motif en copiant le libellÃ©
                $query_copy = "INSERT INTO motif (libelle, motif_autre, type_sejour, actif) 
                              VALUES (:libelle, NULL, :type_sejour, 1)";
                
                $stmt_copy = $db->prepare($query_copy);
                $stmt_copy->execute([
                    ':libelle' => $motif_data['libelle'],
                    ':type_sejour' => $type_sejour
                ]);
                
                $idmotif = $db->lastInsertId();
            } else {
                // Utiliser l'ID du motif existant
                $idmotif = (int)$idmotif_input;
            }
            
            error_log("DEBUG: Utilisation motif idmotif = " . $idmotif);
        } else {
            throw new Exception("Veuillez sÃ©lectionner un motif.");
        }
        // CrÃ©er le sÃ©jour selon le type
        $sejourModel = new Sejour($db);
        
        switch ($type_sejour) {
            case 'ambulatoire':
                $result = $sejourModel->createSejourAmbulatoire([
                    'idpatient' => $patient_id,
                    'idsite' => $_SESSION['site_id'],
                    'idunite_med' => $idunite_med,
                    'idmotif' => $idmotif, // ID du motif (existant ou nouvellement crÃ©Ã©)
                    'idorigine' => $idorigine,
                    'anciennete' => $anciennete,
                    'observation' => $observation,
                    'idutilisateur' => $_SESSION['user_id'],
                    'urgent' => $urgent
                ]);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = "SÃ©jour ambulatoire crÃ©Ã© avec succÃ¨s ! NumÃ©ro : {$result['numero_sejour']}. Patient envoyÃ© en salle d'attente.";
                    //redirect('consultation/salle-attente.php');
                }
                break;
                
            case 'hospitalisation':
                // VÃ©rifier si une unitÃ© d'hospitalisation est sÃ©lectionnÃ©e
                $idunitehospi = $_POST['idunitehospi'] ?? null;
                if (!$idunitehospi) {
                    throw new Exception("Veuillez sÃ©lectionner une unitÃ© d'hospitalisation.");
                }
                
                $result = $sejourModel->createSejourHospitalisation([
                    'idpatient' => $patient_id,
                    'idsite' => $_SESSION['site_id'],
                    'idunite_med' => $idunite_med,
                    'idunitehospi' => $idunitehospi,
                    'idmotif' => $idmotif,
                    'idorigine' => $idorigine,
                    'anciennete' => $anciennete,
                    'observation' => $observation,
                    'idutilisateur' => $_SESSION['user_id'],
                    'urgent' => $urgent
                ]);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = "SÃ©jour d'hospitalisation crÃ©Ã© avec succÃ¨s ! NumÃ©ro : {$result['numero_sejour']}. Patient admis en hospitalisation.";
                    //redirect('hospitalisation/index.php');
                }
                break;
                
            case 'urgence':
                // DonnÃ©es spÃ©cifiques aux urgences
                $niveau_urgence = $_POST['niveau_urgence'] ?? 'modere';
                $mode_arrivee = $_POST['mode_arrivee'] ?? 'marche';
                $accompagnant = sanitizeInput($_POST['accompagnant'] ?? '');
                $moyen_transport = $_POST['moyen_transport'] ?? 'autre';
                
                $result = $sejourModel->createSejourUrgence([
                    'idpatient' => $patient_id,
                    'idsite' => $_SESSION['site_id'],
                    'idunite_med' => $idunite_med,
                    'idmotif' => $idmotif,
                    'idorigine' => $idorigine,
                    'anciennete' => $anciennete,
                    'observation' => $observation,
                    'idutilisateur' => $_SESSION['user_id'],
                    'urgent' => 1, // Toujours urgent pour les urgences
                    'niveau_urgence' => $niveau_urgence,
                    'mode_arrivee' => $mode_arrivee,
                    'accompagnant' => $accompagnant,
                    'moyen_transport' => $moyen_transport
                ]);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = "SÃ©jour aux urgences crÃ©Ã© avec succÃ¨s ! NumÃ©ro : {$result['numero_sejour']}. Patient pris en charge aux urgences.";
                    //redirect('urgence/index.php');
                }
                break;
                
            default:
                throw new Exception("Type de sÃ©jour non valide.");
        }
        
    } catch (Exception $e) {
        $error = "Erreur lors de la crÃ©ation du sÃ©jour : " . $e->getMessage();
    }
}

$pageTitle = "Admission Patient - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-hospital-user"></i> Admission Patient</h1>
    <a href="dossier-patient.php?id=<?php echo $patient_id; ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour au dossier
    </a>
</div>

<!-- Info patient -->
<div class="patient-info-card">
    <div class="patient-avatar">
        <?php echo strtoupper(substr($patientData['nom'], 0, 1) . substr($patientData['prenom'], 0, 1)); ?>
    </div>
    <div class="patient-info-content">
        <h3><?php echo htmlspecialchars($patientData['nom'] . ' ' . $patientData['prenom']); ?></h3>
        <div class="patient-meta">
            <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($patientData['numero_dossier']); ?></span>
            <span><i class="fas fa-birthday-cake"></i> 
                <?php 
                $birthDate = new DateTime($patientData['date_naissance']);
                $today = new DateTime();
                $age = $today->diff($birthDate);
                echo $age->y . ' ans';
                ?>
            </span>
            <span><i class="fas fa-venus-mars"></i> <?php echo $patientData['sexe'] === 'M' ? 'Masculin' : 'FÃ©minin'; ?></span>
            <span class="badge <?php echo $patientData['type_patient'] === 'prive' ? 'badge-warning' : 'badge-success'; ?>">
                <?php echo $patientData['type_patient'] === 'prive' ? 'PrivÃ©' : 'ConventionnÃ©'; ?>
            </span>
        </div>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Formulaire d'admission -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-file-medical"></i> Formulaire d'Admission</h3>
    </div>
    <div class="card-body">
        <form method="POST" id="admissionForm">
            
            <!-- SÃ©lection du type de sÃ©jour -->
            <div class="form-section">
                <h4><i class="fas fa-stethoscope"></i> Type de SÃ©jour</h4>
                <div class="sejour-type-selector">
                    <div class="type-option">
                        <input type="radio" name="type_sejour" value="ambulatoire" id="type_ambulatoire" checked>
                        <label for="type_ambulatoire" class="type-card">
                            <div class="type-icon">
                                <i class="fas fa-user-md"></i>
                            </div>
                            <div class="type-info">
                                <h5>Consultation Ambulatoire</h5>
                                <p>Consultation externe sans hospitalisation</p>
                                <ul>
                                    <li>Consultation mÃ©dicale standard</li>
                                    <li>Examen et prescriptions</li>
                                    <li>Retour Ã  domicile</li>
                                </ul>
                            </div>
                        </label>
                    </div>
                    
                    <div class="type-option">
                        <input type="radio" name="type_sejour" value="hospitalisation" id="type_hospitalisation">
                        <label for="type_hospitalisation" class="type-card">
                            <div class="type-icon">
                                <i class="fas fa-bed"></i>
                            </div>
                            <div class="type-info">
                                <h5>Hospitalisation</h5>
                                <p>SÃ©jour avec hÃ©bergement mÃ©dicalisÃ©</p>
                                <ul>
                                    <li>Surveillance mÃ©dicale continue</li>
                                    <li>Soins infirmiers 24h/24</li>
                                    <li>Traitement en milieu hospitalier</li>
                                </ul>
                            </div>
                        </label>
                    </div>
                    
                    <div class="type-option">
                        <input type="radio" name="type_sejour" value="urgence" id="type_urgence">
                        <label for="type_urgence" class="type-card">
                            <div class="type-icon">
                                <i class="fas fa-ambulance"></i>
                            </div>
                            <div class="type-info">
                                <h5>Urgences</h5>
                                <p>Prise en charge mÃ©dicale urgente</p>
                                <ul>
                                    <li>Triage et Ã©valuation immÃ©diate</li>
                                    <li>Soins d'urgence</li>
                                    <li>Orientation vers spÃ©cialitÃ©</li>
                                </ul>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Informations communes -->
            <div class="form-section">
                <h4><i class="fas fa-info-circle"></i> Informations d'Admission</h4>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="idunite_med">UnitÃ© MÃ©dicale *</label>
                        <select id="idunite_med" name="idunite_med" class="form-control" required>
                            <option value="">-- SÃ©lectionner le service --</option>
                            <?php foreach ($unites as $unite): ?>
                            <option value="<?php echo $unite['idunite_med']; ?>">
                                <?php echo htmlspecialchars($unite['nom']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="idorigine">Origine</label>
                        <select id="idorigine" name="idorigine" class="form-control">
                            <option value="">-- SÃ©lectionner l'origine --</option>
                            <?php foreach ($origines as $origine): ?>
                            <option value="<?php echo $origine['idorigine']; ?>">
                                <?php echo htmlspecialchars($origine['libelle']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="anciennete">Type de patient</label>
                        <select id="anciennete" name="anciennete" class="form-control">
                            <option value="nouveau">Nouveau patient</option>
                            <option value="ancien">Ancien patient</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Section Motif (dynamique selon le type) -->
            <div class="form-section">
                <h4><i class="fas fa-comment-medical"></i> Motif de Consultation</h4>
                
                <div class="form-group">
                    <label for="idmotif">Motif Principal *</label>
                    <select id="idmotif" name="idmotif" class="form-control" required onchange="toggleAutreMotif(this)">
                        <option value="">-- SÃ©lectionner le motif --</option>
                        <!-- Les options seront chargÃ©es dynamiquement -->
                    </select>
                </div>

                <!-- Champ "Autre motif" -->
                <div class="form-group" id="autreMotifGroup" style="display: none;">
                    <label for="motif_autre">PrÃ©cisez le motif *</label>
                    <textarea id="motif_autre" name="motif_autre" class="form-control" rows="3" 
                        placeholder="DÃ©crivez le motif de consultation..."></textarea>
                </div>

                <div class="form-group">
                    <label for="observation">Observations complÃ©mentaires</label>
                    <textarea id="observation" name="observation" class="form-control" rows="3" 
                        placeholder="Informations complÃ©mentaires..."></textarea>
                </div>
            </div>

            <!-- Section spÃ©cifique Ã  l'hospitalisation -->
            <div class="form-section" id="section_hospitalisation" style="display: none;">
                <h4><i class="fas fa-procedures"></i> Informations d'Hospitalisation</h4>
                
                <div class="form-group">
                    <label for="idunitehospi">UnitÃ© d'Hospitalisation *</label>
                    <select id="idunitehospi" name="idunitehospi" class="form-control">
                        <option value="">-- SÃ©lectionner l'unitÃ© --</option>
                        <?php foreach ($unites_hospitalisation as $unite): ?>
                        <option value="<?php echo $unite['idunitehospi']; ?>">
                            <?php echo htmlspecialchars($unite['nom']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Service oÃ¹ le patient sera hospitalisÃ©</small>
                </div>
            </div>

            <!-- Section spÃ©cifique aux urgences -->
            <div class="form-section" id="section_urgence" style="display: none;">
                <h4><i class="fas fa-ambulance"></i> Informations Urgences</h4>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="niveau_urgence">Niveau d'urgence *</label>
                        <select id="niveau_urgence" name="niveau_urgence" class="form-control">
                            <option value="mineur">Mineur</option>
                            <option value="modere" selected>ModÃ©rÃ©</option>
                            <option value="grave">Grave</option>
                            <option value="critique">Critique</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mode_arrivee">Mode d'arrivÃ©e</label>
                        <select id="mode_arrivee" name="mode_arrivee" class="form-control">
                            <option value="marche">Marche</option>
                            <option value="ambulance">Ambulance</option>
                            <option value="vsl">VSL</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="moyen_transport">Moyen de transport</label>
                        <select id="moyen_transport" name="moyen_transport" class="form-control">
                            <option value="prive">VÃ©hicule privÃ©</option>
                            <option value="ambulance">Ambulance SMUR</option>
                            <option value="pompiers">Pompiers</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="accompagnant">Personne accompagnante</label>
                    <input type="text" id="accompagnant" name="accompagnant" class="form-control" 
                        placeholder="Nom et lien de parentÃ©...">
                </div>
            </div>

            <!-- Section urgence -->
            <div class="form-section" id="section_urgence_checkbox">
                <div class="form-group">
                    <label class="urgent-checkbox">
                        <input type="checkbox" name="urgent" value="1" id="urgentCheck">
                        <div class="urgent-content">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <strong>CAS URGENT</strong>
                                <p>Cocher si le patient nÃ©cessite une prise en charge immÃ©diate</p>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <a href="dossier-patient.php?id=<?php echo $patient_id; ?>" class="btn btn-outline btn-lg">
                    <i class="fas fa-times"></i> Annuler
                </a>
                <button type="submit" name="create_sejour" class="btn btn-primary btn-lg" id="submitBtn">
                    <i class="fas fa-paper-plane"></i> <span id="submitText">Enregistrer en Ambulatoire</span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.patient-info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: var(--shadow-lg);
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
}

.patient-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: bold;
    border: 3px solid rgba(255,255,255,0.3);
    flex-shrink: 0;
}

.patient-info-content h3 {
    margin: 0 0 10px 0;
    font-size: 24px;
}

.patient-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    font-size: 14px;
    opacity: 0.95;
}

.patient-meta span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.form-section {
    margin-bottom: 30px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    border-left: 4px solid var(--primary);
}

.form-section h4 {
    margin: 0 0 20px 0;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.sejour-type-selector {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.type-option {
    position: relative;
}

.type-option input[type="radio"] {
    display: none;
}

.type-card {
    display: block;
    background: white;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
    height: 100%;
}

.type-card:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.type-option input[type="radio"]:checked + .type-card {
    border-color: var(--primary);
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: var(--shadow-lg);
}

.type-option input[type="radio"]:checked + .type-card .type-icon {
    background: rgba(255,255,255,0.2);
    color: white;
}

.type-option input[type="radio"]:checked + .type-card h5,
.type-option input[type="radio"]:checked + .type-card p,
.type-option input[type="radio"]:checked + .type-card li {
    color: white;
}

.type-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: var(--light);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: var(--primary);
    margin-bottom: 15px;
}

.type-info h5 {
    margin: 0 0 10px 0;
    font-size: 18px;
    color: var(--dark);
}

.type-info p {
    margin: 0 0 15px 0;
    color: #64748b;
    font-weight: 500;
}

.type-info ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.type-info li {
    padding: 5px 0;
    color: #64748b;
    font-size: 14px;
    position: relative;
    padding-left: 20px;
}

.type-info li:before {
    content: 'âœ“';
    position: absolute;
    left: 0;
    color: var(--primary);
    font-weight: bold;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.urgent-checkbox {
    display: block;
    cursor: pointer;
}

.urgent-checkbox input[type="checkbox"] {
    display: none;
}

.urgent-content {
    border: 2px solid #fbbf24;
    background: #fef3c7;
    border-radius: 8px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 15px;
    transition: all 0.2s;
}

.urgent-content i {
    font-size: 32px;
    color: #f59e0b;
}

.urgent-content strong {
    color: #92400e;
    display: block;
    margin-bottom: 3px;
}

.urgent-content p {
    margin: 0;
    color: #78350f;
    font-size: 13px;
}

.urgent-checkbox input[type="checkbox"]:checked + .urgent-content {
    border-color: #dc2626;
    background: #fee2e2;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
}

.urgent-checkbox input[type="checkbox"]:checked + .urgent-content i {
    color: #dc2626;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.form-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid var(--border);
}

@media (max-width: 768px) {
    .patient-info-card {
        flex-direction: column;
        text-align: center;
    }
    
    .sejour-type-selector {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
    }
}
</style>

<script>
// DonnÃ©es des motifs par type de sÃ©jour
const motifsData = {
    ambulatoire: <?php echo json_encode($motifs_ambulatoire); ?>,
    hospitalisation: <?php echo json_encode($motifs_hospitalisation); ?>,
    urgence: <?php echo json_encode($motifs_urgence); ?>
};

// Ã‰lÃ©ments DOM
const typeRadios = document.querySelectorAll('input[name="type_sejour"]');
const motifSelect = document.getElementById('idmotif');
const autreMotifGroup = document.getElementById('autreMotifGroup');
const sectionHospitalisation = document.getElementById('section_hospitalisation');
const sectionUrgence = document.getElementById('section_urgence');
const sectionUrgenceCheckbox = document.getElementById('section_urgence_checkbox');
const submitBtn = document.getElementById('submitBtn');
const submitText = document.getElementById('submitText');
const urgentCheck = document.getElementById('urgentCheck');

// Mettre Ã  jour les motifs selon le type de sÃ©jour
function updateMotifs(type) {
    const motifs = motifsData[type] || [];
    let html = '<option value="">-- SÃ©lectionner le motif --</option>';
    
    motifs.forEach(motif => {
        html += `<option value="${motif.idmotif}">${motif.libelle}</option>`;
    });
    html += '<option value="autre">Autre motif...</option>';
    
    motifSelect.innerHTML = html;
    toggleAutreMotif(motifSelect);
}

// Toggle champ "Autre motif"
function toggleAutreMotif(select) {
    if (select.value === 'autre') {
        autreMotifGroup.style.display = 'block';
        document.getElementById('motif_autre').required = true;
    } else {
        autreMotifGroup.style.display = 'none';
        document.getElementById('motif_autre').required = false;
        document.getElementById('motif_autre').value = '';
    }
}

// GÃ©rer le changement de type de sÃ©jour
typeRadios.forEach(radio => {
    radio.addEventListener('change', function() {
        const type = this.value;
        
        // Mettre Ã  jour les motifs
        updateMotifs(type);
        
        // Afficher/masquer les sections spÃ©cifiques
        sectionHospitalisation.style.display = type === 'hospitalisation' ? 'block' : 'none';
        sectionUrgence.style.display = type === 'urgence' ? 'block' : 'none';
        sectionUrgenceCheckbox.style.display = type === 'urgence' ? 'none' : 'block';
        
        // Mettre Ã  jour le texte du bouton
        const texts = {
            'ambulatoire': 'Enregistrer en Ambulatoire',
            'hospitalisation': 'Admettre en Hospitalisation',
            'urgence': 'Admettre aux Urgences'
        };
        submitText.textContent = texts[type] || 'Enregistrer';
        
        // Pour les urgences, cocher automatiquement "urgent"
        if (type === 'urgence') {
            urgentCheck.checked = true;
            urgentCheck.disabled = true;
        } else {
            urgentCheck.disabled = false;
        }
    });
});

// Validation du formulaire
document.getElementById('admissionForm').addEventListener('submit', function(e) {
    const type = document.querySelector('input[name="type_sejour"]:checked').value;
    const unite = document.getElementById('idunite_med').value;
    const motif = document.getElementById('idmotif').value;
    
    if (!unite) {
        e.preventDefault();
        alert('Veuillez sÃ©lectionner une unitÃ© mÃ©dicale.');
        return false;
    }
    
    if (!motif) {
        e.preventDefault();
        alert('Veuillez sÃ©lectionner un motif.');
        document.getElementById('idmotif').focus();
        return false;
    }
    
    // VÃ©rification spÃ©cifique Ã  l'hospitalisation
    if (type === 'hospitalisation') {
        const uniteHospi = document.getElementById('idunitehospi').value;
        if (!uniteHospi) {
            e.preventDefault();
            alert('Veuillez sÃ©lectionner une unitÃ© d\'hospitalisation.');
            document.getElementById('idunitehospi').focus();
            return false;
        }
    }
    
    // Si "Autre" est sÃ©lectionnÃ©, vÃ©rifier que le champ est rempli
    if (motif === 'autre') {
        const autreMotif = document.getElementById('motif_autre').value.trim();
        if (!autreMotif || autreMotif.length < 5) {
            e.preventDefault();
            alert('Veuillez prÃ©ciser le motif (minimum 5 caractÃ¨res).');
            document.getElementById('motif_autre').focus();
            return false;
        }
    }
    
    // Confirmation
    const typeText = {
        'ambulatoire': 'Consultation Ambulatoire',
        'hospitalisation': 'Hospitalisation',
        'urgence': 'Urgences'
    }[type];
    
    const uniteName = document.getElementById('idunite_med').options[document.getElementById('idunite_med').selectedIndex].text;
    const motifText = document.getElementById('idmotif').options[document.getElementById('idmotif').selectedIndex].text;
    const urgentText = urgentCheck.checked ? ' (CAS URGENT)' : '';
    
    if (!confirm(`Confirmer l'admission du patient :\n\nType : ${typeText}\nService : ${uniteName}\nMotif : ${motifText}${urgentText}`)) {
        e.preventDefault();
        return false;
    }
    
    return true;
});

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    // DÃ©clencher l'Ã©vÃ©nement change sur le type sÃ©lectionnÃ© par dÃ©faut
    document.getElementById('type_ambulatoire').dispatchEvent(new Event('change'));
    
    // Animation sur le check urgent
    urgentCheck.addEventListener('change', function() {
        if (this.checked) {
            document.querySelector('.urgent-content').style.transform = 'scale(1.02)';
            setTimeout(() => {
                document.querySelector('.urgent-content').style.transform = 'scale(1)';
            }, 200);
        }
    });
});
</script>

<?php include '../views/includes/footer.php'; ?>