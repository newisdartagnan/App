<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();
$prescription = new PrescriptionMedicale($db);

$sous_sejour_id = $_GET['sous_sejour_id'] ?? null;

if (!$sous_sejour_id) {
    redirect('salle-attente.php');
}

// RÃ©cupÃ©rer infos patient
$query = "SELECT p.*, s.*, ss.*, um.nom as unite_medicale
    FROM sous_sejour ss
    JOIN sejour s ON ss.idsejour = s.idsejour  
    JOIN patient p ON s.idpatient = p.idpatient
    LEFT JOIN unite_med um ON ss.idunite_med = um.idunite_med
    WHERE ss.idsous_sejour = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $sous_sejour_id);
$stmt->execute();
$patient_data = $stmt->fetch();

if (!$patient_data) {
    die("Patient non trouvÃ©");
}

// RÃ©cupÃ©rer les donnÃ©es pour les prescriptions
$actes_labo = $db->query("SELECT * FROM acte WHERE idcategorie_acte = 6 AND actif = 1 ORDER BY libelle")->fetchAll();
$actes_imagerie = $db->query("SELECT * FROM acte WHERE idcategorie_acte = 5 AND actif = 1 ORDER BY libelle")->fetchAll();
$produits_pharma = $db->query("SELECT * FROM prodpharma WHERE actif = 1 AND disponibilite = 'disponible' ORDER BY libelle")->fetchAll();
$categories_actes = $db->query("SELECT * FROM categorie_acte WHERE actif = 1 AND idcategorie_acte NOT IN (5,6) ORDER BY nom")->fetchAll();

$success = '';
$error = '';

// Traitement des prescriptions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $type_prescription = $_POST['type_prescription'];
        
        switch ($type_prescription) {
            case 'laboratoire':
                $data = [
                    'idsous_sejour' => $sous_sejour_id,
                    'idacte' => $_POST['idacte'],
                    'idsite' => $_SESSION['site_id'],
                    'idspecialite' => $_POST['idspecialite'] ?? null,
                    'prix' => $_POST['prix'],
                    'prescripteur' => $_SESSION['user_id'],
                    'urgent' => isset($_POST['urgent']) ? 1 : 0,
                    'indication' => $_POST['indication'],
                    'acte_libelle' => $_POST['acte_libelle'],
                    'type_externe' => $_POST['type_externe'] ?? 'interne'
                ];
                $prescription->prescrireLaboratoire($data);
                $success = "Analyse de laboratoire prescrite avec succÃ¨s !";
                break;
                
            case 'imagerie':
                $data = [
                    'idsous_sejour' => $sous_sejour_id,
                    'idacte' => $_POST['idacte'],
                    'idsite' => $_SESSION['site_id'],
                    'idspecialite' => $_POST['idspecialite'] ?? null,
                    'prix' => $_POST['prix'],
                    'prescripteur' => $_SESSION['user_id'],
                    'urgent' => isset($_POST['urgent']) ? 1 : 0,
                    'indication' => $_POST['indication'],
                    'type_externe' => $_POST['type_externe'],
                    'acte_libelle' => $_POST['acte_libelle'],
                    'centre_externe' => $_POST['centre_externe'] ?? null
                ];
                $prescription->prescrireImagerie($data);
                $success = "Examen d'imagerie prescrit avec succÃ¨s !";
                break;
                
            case 'pharmacie':
                $data = [
                    'idsous_sejour' => $sous_sejour_id,
                    'idprodpharma' => $_POST['idprodpharma'],
                    'idsociete' => $patient_data['idsociete'],
                    'quantite' => $_POST['quantite'],
                    'posologie' => $_POST['posologie'],
                    'prix_unitaire' => $_POST['prix_unitaire'],
                    'prescripteur' => $_SESSION['user_id'],
                    'urgent' => isset($_POST['urgent']) ? 1 : 0,
                    'produit_libelle' => $_POST['produit_libelle']
                ];
                $prescription->prescrirePharmacie($data);
                $success = "MÃ©dicament prescrit avec succÃ¨s !";
                break;
                
            case 'acte_medical':
                $data = [
                    'idsous_sejour' => $sous_sejour_id,
                    'idacte' => $_POST['idacte_medical'],
                    'idsite' => $_SESSION['site_id'],
                    'idspecialite' => $_POST['idspecialite_medical'] ?? null,
                    'prix' => $_POST['prix_medical'],
                    'prescripteur' => $_SESSION['user_id'],
                    'urgent' => isset($_POST['urgent_medical']) ? 1 : 0,
                    'indication' => $_POST['indication_medical'],
                    'acte_libelle' => $_POST['acte_libelle_medical'],
                    'quantite' => $_POST['quantite_medical'] ?? 1
                ];
                $prescription->prescrireActeMedical($data);
                $success = "Acte mÃ©dical prescrit avec succÃ¨s !";
                break;
        }
    } catch (Exception $e) {
        $error = "Erreur : " . $e->getMessage();
    }
}

$pageTitle = "Prescrire - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="prescription-interface">
    <div class="page-header">
        <h1><i class="fas fa-prescription"></i> Prescriptions MÃ©dicales</h1>
        <div class="header-actions">
            <button onclick="refreshPrescriptions()" class="btn btn-secondary">
                <i class="fas fa-sync"></i> Actualiser
            </button>
            <button onclick="window.close()" class="btn btn-outline">
                <i class="fas fa-times"></i> Fermer
            </button>
        </div>
    </div>

    <!-- Info patient amÃ©liorÃ©e -->
    <div class="patient-info-card">
        <div class="patient-avatar">
            <?php echo strtoupper(substr($patient_data['nom'], 0, 1) . substr($patient_data['prenom'], 0, 1)); ?>
        </div>
        <div class="patient-details">
            <h3><?php echo htmlspecialchars($patient_data['nom'] . ' ' . $patient_data['prenom']); ?></h3>
            <div class="patient-meta">
                <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($patient_data['numero_dossier']); ?></span>
                <span><i class="fas fa-hospital"></i> <?php echo htmlspecialchars($patient_data['unite_medicale']); ?></span>
                <span><i class="fas fa-calendar"></i> <?php echo formatDate($patient_data['date_entree']); ?></span>
                <span class="badge <?php echo $patient_data['type_patient'] === 'prive' ? 'badge-warning' : 'badge-success'; ?>">
                    <?php echo $patient_data['type_patient'] === 'prive' ? 'PrivÃ©' : 'ConventionnÃ©'; ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <div class="alert-content">
            <i class="fas fa-check-circle"></i>
            <div>
                <strong>SuccÃ¨s !</strong>
                <p><?php echo $success; ?></p>
            </div>
        </div>
        <button onclick="setTimeout(() => location.reload(), 500)" class="btn btn-sm btn-primary">
            Nouvelle prescription
        </button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <!-- Navigation amÃ©liorÃ©e -->
    <div class="prescription-navigation">
        <div class="prescription-tabs">
            <button class="tab-btn active" data-tab="laboratoire">
                <i class="fas fa-flask"></i> Laboratoire
            </button>
            <button class="tab-btn" data-tab="imagerie">
                <i class="fas fa-x-ray"></i> Imagerie
            </button>
            <button class="tab-btn" data-tab="pharmacie">
                <i class="fas fa-pills"></i> Pharmacie
            </button>
            <button class="tab-btn" data-tab="actes">
                <i class="fas fa-procedures"></i> Actes MÃ©dicaux
            </button>
        </div>
        
        <div class="prescription-stats">
            <div class="stat-badge" id="statEnAttente">
                <i class="fas fa-clock"></i>
                <span>Chargement...</span>
            </div>
            <div class="stat-badge" id="statValides">
                <i class="fas fa-check"></i>
                <span>Chargement...</span>
            </div>
        </div>
    </div>

    <!-- TAB LABORATOIRE -->
    <div class="tab-content active" id="tab-laboratoire">
        <div class="prescription-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-flask"></i> Prescrire une Analyse de Laboratoire</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="prescription-form">
                    <input type="hidden" name="type_prescription" value="laboratoire">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="acte_labo">Analyse *</label>
                            <select id="acte_labo" name="idacte" class="form-control" required 
                                onchange="loadActeInfo(this, 'labo')">
                                <option value="">-- SÃ©lectionner --</option>
                                <?php foreach ($actes_labo as $acte): ?>
                                <option value="<?php echo $acte['idacte']; ?>"
                                    data-prix="<?php echo $acte['prix_vente'] ?? '5000'; ?>"
                                    data-libelle="<?php echo htmlspecialchars($acte['libelle']); ?>">
                                    <?php echo htmlspecialchars($acte['libelle']); ?> (<?php echo $acte['code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Lieu d'exÃ©cution *</label>
                            <select name="type_externe" class="form-control" required onchange="toggleCentreExterne(this, 'labo')">
                                <option value="interne">Laboratoire interne (CHM)</option>
                                <option value="externe">Laboratoire externe (RÃ©fÃ©rence)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Prix</label>
                            <input type="text" id="prix_labo" name="prix" class="form-control" readonly>
                            <input type="hidden" name="acte_libelle" id="acte_libelle_labo">
                        </div>
                    </div>

                    <div class="form-group" id="centre_externe_labo" style="display: none;">
                        <label>Centre externe</label>
                        <input type="text" name="centre_externe" class="form-control" 
                            placeholder="Nom du laboratoire externe">
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label urgent-checkbox">
                            <input type="checkbox" name="urgent" value="1">
                            <div class="urgent-content">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>CAS URGENT</strong>
                                    <p>Cocher si l'analyse nÃ©cessite une exÃ©cution immÃ©diate</p>
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="indication_labo">Indication clinique *</label>
                        <textarea id="indication_labo" name="indication" class="form-control" rows="3"
                            placeholder="Renseignements cliniques, suspicion diagnostique..." required></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-prescription"></i> Prescrire l'Analyse
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB IMAGERIE -->
    <div class="tab-content" id="tab-imagerie">
        <div class="prescription-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-x-ray"></i> Prescrire un Examen d'Imagerie</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="prescription-form">
                    <input type="hidden" name="type_prescription" value="imagerie">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="acte_imagerie">Examen *</label>
                            <select id="acte_imagerie" name="idacte" class="form-control" required 
                                onchange="loadActeInfo(this, 'imagerie')">
                                <option value="">-- SÃ©lectionner --</option>
                                <?php foreach ($actes_imagerie as $acte): ?>
                                <option value="<?php echo $acte['idacte']; ?>"
                                    data-prix="<?php echo $acte['prix_vente'] ?? '10000'; ?>"
                                    data-libelle="<?php echo htmlspecialchars($acte['libelle']); ?>">
                                    <?php echo htmlspecialchars($acte['libelle']); ?> (<?php echo $acte['code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Lieu d'exÃ©cution *</label>
                            <select name="type_externe" class="form-control" required onchange="toggleCentreExterne(this, 'imagerie')">
                                <option value="interne">Imagerie interne (CHM)</option>
                                <option value="externe">Centre externe (RÃ©fÃ©rence)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Prix</label>
                            <input type="text" id="prix_imagerie" name="prix" class="form-control" readonly>
                            <input type="hidden" name="acte_libelle" id="acte_libelle_imagerie">
                        </div>
                    </div>

                    <div class="form-group" id="centre_externe_imagerie" style="display: none;">
                        <label>Centre externe *</label>
                        <input type="text" name="centre_externe" class="form-control" 
                            placeholder="Nom du centre d'imagerie externe">
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label urgent-checkbox">
                            <input type="checkbox" name="urgent" value="1">
                            <div class="urgent-content">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>CAS URGENT</strong>
                                    <p>Cocher si l'examen nÃ©cessite une exÃ©cution immÃ©diate</p>
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="indication_imagerie">Indication / Renseignements cliniques *</label>
                        <textarea id="indication_imagerie" name="indication" class="form-control" rows="3"
                            placeholder="Suspicion diagnostique, zone Ã  explorer..." required></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-prescription"></i> Prescrire l'Examen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB PHARMACIE -->
    <div class="tab-content" id="tab-pharmacie">
        <div class="prescription-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-pills"></i> Prescrire des MÃ©dicaments</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="prescription-form">
                    <input type="hidden" name="type_prescription" value="pharmacie">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="produit_pharma">MÃ©dicament *</label>
                            <select id="produit_pharma" name="idprodpharma" class="form-control" required 
                                onchange="loadProduitInfo(this)">
                                <option value="">-- SÃ©lectionner --</option>
                                <?php foreach ($produits_pharma as $produit): ?>
                                <option value="<?php echo $produit['idprodpharma']; ?>"
                                    data-prix="<?php echo $produit['prix_vente_externe']; ?>"
                                    data-libelle="<?php echo htmlspecialchars($produit['libelle']); ?>">
                                    <?php echo htmlspecialchars($produit['libelle']); ?> (<?php echo $produit['code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="quantite">QuantitÃ© *</label>
                            <input type="number" id="quantite" name="quantite" class="form-control" 
                                min="1" required value="1" onchange="calculateTotal()">
                        </div>
                        
                        <div class="form-group">
                            <label>Prix Unit.</label>
                            <input type="text" id="prix_pharma" name="prix_unitaire" class="form-control" readonly>
                            <input type="hidden" name="produit_libelle" id="produit_libelle">
                        </div>
                        
                        <div class="form-group">
                            <label>Total</label>
                            <input type="text" id="total_pharma" class="form-control" readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="posologie">Posologie *</label>
                        <textarea id="posologie" name="posologie" class="form-control" rows="2"
                            placeholder="Ex: 1 comprimÃ© 3 fois par jour pendant 7 jours" required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label urgent-checkbox">
                            <input type="checkbox" name="urgent" value="1">
                            <div class="urgent-content">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>CAS URGENT</strong>
                                    <p>Cocher si le mÃ©dicament nÃ©cessite une dÃ©livrance immÃ©diate</p>
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-prescription"></i> Prescrire le MÃ©dicament
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB ACTES MÃ‰DICAUX -->
    <div class="tab-content" id="tab-actes">
        <div class="prescription-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-procedures"></i> Prescrire un Acte MÃ©dical</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="prescription-form">
                    <input type="hidden" name="type_prescription" value="acte_medical">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>CatÃ©gorie d'acte</label>
                            <select id="categorie_acte" class="form-control" onchange="loadActesMedicaux(this.value)">
                                <option value="">-- SÃ©lectionner --</option>
                                <?php foreach ($categories_actes as $cat): ?>
                                <option value="<?php echo $cat['idcategorie_acte']; ?>">
                                    <?php echo htmlspecialchars($cat['nom']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Acte MÃ©dical *</label>
                            <select id="idacte_medical" name="idacte_medical" class="form-control" required 
                                onchange="loadActeInfoMedical(this)">
                                <option value="">-- SÃ©lectionner d'abord une catÃ©gorie --</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>QuantitÃ©</label>
                            <input type="number" name="quantite_medical" class="form-control" value="1" min="1">
                        </div>
                        
                        <div class="form-group">
                            <label>Prix</label>
                            <input type="text" id="prix_medical" name="prix_medical" class="form-control" readonly>
                            <input type="hidden" name="acte_libelle_medical" id="acte_libelle_medical">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label urgent-checkbox">
                            <input type="checkbox" name="urgent_medical" value="1">
                            <div class="urgent-content">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>CAS URGENT</strong>
                                    <p>Cocher si l'acte nÃ©cessite une exÃ©cution immÃ©diate</p>
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="indication_medical">Indication clinique</label>
                        <textarea id="indication_medical" name="indication_medical" class="form-control" rows="3"
                            placeholder="Justification de l'acte mÃ©dical..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-prescription"></i> Prescrire l'Acte
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Liste des prescriptions en attente -->
    <div class="prescriptions-list-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-list"></i> Prescriptions en Cours
                <span class="badge badge-info" id="prescriptionsCount">0</span>
            </h3>
        </div>
        <div class="card-body">
            <div id="prescriptionsEnAttente" class="prescriptions-container">
                <!-- Chargement via AJAX -->
            </div>
        </div>
    </div>
</div>

<!-- Le CSS et JavaScript continuent... -->

<style>
.prescription-interface {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.patient-info-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: var(--shadow);
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 20px;
}

.patient-avatar {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: bold;
    flex-shrink: 0;
}

.patient-details h3 {
    margin: 0 0 10px 0;
    font-size: 22px;
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

.prescription-navigation {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding: 20px;
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
}

.prescription-tabs {
    display: flex;
    gap: 5px;
}

.prescription-stats {
    display: flex;
    gap: 15px;
}

.stat-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: var(--light);
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
}

.tab-btn {
    padding: 12px 24px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #64748b;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
    border-radius: 6px;
}

.tab-btn:hover {
    background: var(--light);
}

.tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    background: var(--light);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.prescription-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    margin-bottom: 25px;
    overflow: hidden;
}

.prescriptions-list-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    margin-top: 30px;
}

.card-header {
    padding: 20px 25px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-body {
    padding: 25px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--dark);
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s;
}

.form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
    font-size: 24px;
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
    font-size: 12px;
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
    justify-content: flex-end;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
}

.alert {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #dc2626;
}

.alert-content {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.prescriptions-container {
    max-height: 400px;
    overflow-y: auto;
}

.prescription-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid var(--border);
    transition: all 0.2s;
}

.prescription-item:hover {
    background: var(--light);
}

.prescription-item:last-child {
    border-bottom: none;
}

.prescription-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.prescription-type {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.prescription-type.labo { background: #dbeafe; color: #1e40af; }
.prescription-type.imagerie { background: #f3e8ff; color: #7c3aed; }
.prescription-type.pharmacie { background: #dcfce7; color: #166534; }
.prescription-type.acte { background: #fef3c7; color: #92400e; }

.prescription-details {
    flex: 1;
}

.prescription-details h4 {
    margin: 0 0 5px 0;
    font-size: 14px;
}

.prescription-meta {
    display: flex;
    gap: 15px;
    font-size: 12px;
    color: #64748b;
}

.prescription-status {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 5px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.status-en_attente { background: #fef3c7; color: #92400e; }
.status-en_cours { background: #dbeafe; color: #1e40af; }
.status-termine { background: #dcfce7; color: #166534; }

.prescription-date {
    font-size: 11px;
    color: #94a3b8;
}

.text-muted {
    text-align: center;
    padding: 40px;
    color: #94a3b8;
    font-style: italic;
}

@media (max-width: 768px) {
    .prescription-navigation {
        flex-direction: column;
        gap: 15px;
    }
    
    .prescription-tabs {
        flex-wrap: wrap;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .patient-info-card {
        flex-direction: column;
        text-align: center;
    }
}
</style>

<script>
// Gestion des onglets
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});

// Toggle centre externe
function toggleCentreExterne(select, type) {
    const centreDiv = document.getElementById('centre_externe_' + type);
    const centreInput = centreDiv.querySelector('input');
    
    if (select.value === 'externe') {
        centreDiv.style.display = 'block';
        centreInput.required = true;
    } else {
        centreDiv.style.display = 'none';
        centreInput.required = false;
        centreInput.value = '';
    }
}

// Charger info acte
function loadActeInfo(select, type) {
    const option = select.options[select.selectedIndex];
    const prix = option.dataset.prix;
    const libelle = option.dataset.libelle;
    
    document.getElementById('prix_' + type).value = formatMoney(prix);
    document.getElementById('acte_libelle_' + type).value = libelle;
}

// Charger info produit pharma
function loadProduitInfo(select) {
    const option = select.options[select.selectedIndex];
    const prix = option.dataset.prix;
    const libelle = option.dataset.libelle;
    
    document.getElementById('prix_pharma').value = formatMoney(prix);
    document.getElementById('produit_libelle').value = libelle;
    calculateTotal();
}

// Charger actes mÃ©dicaux
function loadActesMedicaux(idcategorie) {
    const select = document.getElementById('idacte_medical');
    
    if (!idcategorie) {
        select.innerHTML = '<option value="">-- SÃ©lectionner d\'abord une catÃ©gorie --</option>';
        return;
    }
    
    fetch(`../api/get-actes-by-categorie.php?idcategorie=${idcategorie}`)
        .then(response => response.json())
        .then(data => {
            let html = '<option value="">-- SÃ©lectionner --</option>';
            data.forEach(item => {
                html += `<option value="${item.idacte}" 
                         data-prix="${item.prix_vente || '0'}" 
                         data-libelle="${item.libelle}">
                         ${item.libelle} - ${item.code}
                         </option>`;
            });
            select.innerHTML = html;
        });
}

function loadActeInfoMedical(select) {
    const option = select.options[select.selectedIndex];
    const prix = option.dataset.prix;
    const libelle = option.dataset.libelle;
    
    document.getElementById('prix_medical').value = formatMoney(prix);
    document.getElementById('acte_libelle_medical').value = libelle;
}

// Calculer total pharmacie
function calculateTotal() {
    const prixText = document.getElementById('prix_pharma').value;
    const prix = parseFloat(prixText.replace(/[^0-9.-]+/g,"")) || 0;
    const quantite = parseInt(document.getElementById('quantite').value) || 0;
    const total = prix * quantite;
    
    document.getElementById('total_pharma').value = formatMoney(total);
}

function formatMoney(amount) {
    return new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount) + ' FC';
}

// Charger prescriptions en attente
function loadPrescriptionsEnAttente() {
    fetch(`../api/get-prescriptions-attente.php?sous_sejour_id=<?php echo $sous_sejour_id; ?>`)
        .then(response => response.json())
        .then(data => {
            displayPrescriptions(data);
            updateStats(data);
        });
}

function displayPrescriptions(data) {
    const container = document.getElementById('prescriptionsEnAttente');
    
    if (data.length === 0) {
        container.innerHTML = '<p class="text-muted">Aucune prescription en attente</p>';
        return;
    }
    
    let html = '';
    
    data.forEach(item => {
        const typeClass = getTypeClass(item.type);
        const statusClass = getStatusClass(item.statut);
        const urgentBadge = item.urgent ? '<span class="badge badge-danger" style="margin-left: 8px;">URGENT</span>' : '';
        
        html += `
        <div class="prescription-item">
            <div class="prescription-info">
                <span class="prescription-type ${typeClass}">${item.type}</span>
                <div class="prescription-details">
                    <h4>${item.libelle}${urgentBadge}</h4>
                    <div class="prescription-meta">
                        <span><i class="fas fa-map-marker-alt"></i> ${item.lieu}</span>
                        <span><i class="fas fa-calendar"></i> ${item.date}</span>
                    </div>
                </div>
            </div>
            <div class="prescription-status">
                <span class="status-badge ${statusClass}">${getStatusText(item.statut)}</span>
                <span class="prescription-date">${item.statut_validation === 'rien' ? 'En attente validation' : 'ValidÃ©'}</span>
            </div>
        </div>`;
    });
    
    container.innerHTML = html;
    document.getElementById('prescriptionsCount').textContent = data.length;
}

function updateStats(data) {
    const enAttente = data.filter(item => item.statut === 'en_attente').length;
    const valides = data.filter(item => item.statut_validation === 'valide').length;
    
    document.getElementById('statEnAttente').innerHTML = `<i class="fas fa-clock"></i> <span>${enAttente} en attente</span>`;
    document.getElementById('statValides').innerHTML = `<i class="fas fa-check"></i> <span>${valides} validÃ©s</span>`;
}

function getTypeClass(type) {
    const types = {
        'Laboratoire': 'labo',
        'Imagerie': 'imagerie', 
        'Pharmacie': 'pharmacie',
        'Acte': 'acte'
    };
    return types[type] || 'acte';
}

function getStatusClass(statut) {
    const status = {
        'en_attente': 'status-en_attente',
        'en_cours': 'status-en_cours',
        'termine': 'status-termine'
    };
    return status[statut] || 'status-en_attente';
}

function getStatusText(statut) {
    const texts = {
        'en_attente': 'En attente',
        'en_cours': 'En cours',
        'termine': 'TerminÃ©'
    };
    return texts[statut] || statut;
}

function refreshPrescriptions() {
    loadPrescriptionsEnAttente();
}

// Charger au dÃ©marrage
document.addEventListener('DOMContentLoaded', function() {
    loadPrescriptionsEnAttente();
    setInterval(loadPrescriptionsEnAttente, 30000); // Actualiser toutes les 30s
});
</script>

<?php include '../views/includes/footer.php'; ?>