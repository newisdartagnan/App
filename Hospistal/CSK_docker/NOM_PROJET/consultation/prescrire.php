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
$query = "SELECT p.*, s.*, ss.*
          FROM sous_sejour ss
          JOIN sejour s ON ss.idsejour = s.idsejour
          JOIN patient p ON s.idpatient = p.idpatient
          WHERE ss.idsous_sejour = :id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $sous_sejour_id);
$stmt->execute();
$patient_data = $stmt->fetch();

// RÃ©cupÃ©rer les actes de laboratoire
$actes_labo = $db->query("SELECT * FROM acte WHERE idcategorie_acte = 6 AND actif = 1 ORDER BY libelle")->fetchAll();

// RÃ©cupÃ©rer les actes d'imagerie
$actes_imagerie = $db->query("SELECT * FROM acte WHERE idcategorie_acte = 5 AND actif = 1 ORDER BY libelle")->fetchAll();

// RÃ©cupÃ©rer les produits pharmaceutiques
$produits_pharma = $db->query("SELECT * FROM prodpharma WHERE actif = 1 AND disponibilite = 'disponible' ORDER BY libelle")->fetchAll();

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
                    'acte_libelle' => $_POST['acte_libelle']
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
                    'acte_libelle' => $_POST['acte_libelle']
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
        <a href="consulter.php?sous_sejour_id=<?php echo $sous_sejour_id; ?>" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour Ã  la consultation
        </a>
    </div>

    <!-- Info patient -->
    <div class="patient-info-bar">
        <strong>Patient:</strong> <?php echo htmlspecialchars($patient_data['nom'] . ' ' . $patient_data['prenom']); ?>
        <strong>NÂ° Dossier:</strong> <?php echo htmlspecialchars($patient_data['numero_dossier']); ?>
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

    <!-- Onglets de prescription -->
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
    </div>

    <!-- TAB LABORATOIRE -->
    <div class="tab-content active" id="tab-laboratoire">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Prescrire une Analyse de Laboratoire</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="prescription-form">
                    <input type="hidden" name="type_prescription" value="laboratoire">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="acte_labo">Analyse *</label>
                            <select id="acte_labo" name="idacte" class="form-control" required onchange="loadActeInfo(this, 'labo')">
                                <option value="">-- SÃ©lectionner --</option>
                                <?php foreach ($actes_labo as $acte): ?>
                                    <option value="<?php echo $acte['idacte']; ?>" 
                                            data-prix="5000"
                                            data-libelle="<?php echo htmlspecialchars($acte['libelle']); ?>">
                                        <?php echo htmlspecialchars($acte['libelle']); ?> (<?php echo $acte['code']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Prix</label>
                            <input type="text" id="prix_labo" name="prix" class="form-control" readonly>
                            <input type="hidden" name="acte_libelle" id="acte_libelle_labo">
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="urgent" value="1">
                                <span>URGENT</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="indication_labo">Indication clinique</label>
                        <textarea id="indication_labo" name="indication" class="form-control" rows="3" 
                                  placeholder="Renseignements cliniques, suspicion diagnostique..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-prescription"></i> Prescrire l'Analyse
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB IMAGERIE -->
    <div class="tab-content" id="tab-imagerie">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Prescrire un Examen d'Imagerie</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="prescription-form">
                    <input type="hidden" name="type_prescription" value="imagerie">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="acte_imagerie">Examen *</label>
                            <select id="acte_imagerie" name="idacte" class="form-control" required onchange="loadActeInfo(this, 'imagerie')">
                                <option value="">-- SÃ©lectionner --</option>
                                <?php foreach ($actes_imagerie as $acte): ?>
                                    <option value="<?php echo $acte['idacte']; ?>" 
                                            data-prix="10000"
                                            data-libelle="<?php echo htmlspecialchars($acte['libelle']); ?>">
                                        <?php echo htmlspecialchars($acte['libelle']); ?> (<?php echo $acte['code']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Type</label>
                            <select name="type_externe" class="form-control" required>
                                <option value="interne">Interne (CHM)</option>
                                <option value="externe">Externe (RÃ©fÃ©rence)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Prix</label>
                            <input type="text" id="prix_imagerie" name="prix" class="form-control" readonly>
                            <input type="hidden" name="acte_libelle" id="acte_libelle_imagerie">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="urgent" value="1">
                            <span>URGENT</span>
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label for="indication_imagerie">Indication / Renseignements cliniques</label>
                        <textarea id="indication_imagerie" name="indication" class="form-control" rows="3" 
                                  placeholder="Suspicion diagnostique, zone Ã  explorer..." required></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-prescription"></i> Prescrire l'Examen
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- TAB PHARMACIE -->
    <div class="tab-content" id="tab-pharmacie">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Prescrire des MÃ©dicaments</h3>
            </div>
            <div class="card-body">
                <form method="POST" class="prescription-form">
                    <input type="hidden" name="type_prescription" value="pharmacie">
                    
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label for="produit_pharma">MÃ©dicament *</label>
                            <select id="produit_pharma" name="idprodpharma" class="form-control" required onchange="loadProduitInfo(this)">
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
                        <label class="checkbox-label">
                            <input type="checkbox" name="urgent" value="1">
                            <span>URGENT</span>
                        </label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-prescription"></i> Prescrire le MÃ©dicament
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Liste des prescriptions en attente -->
    <div class="card mt-2">
        <div class="card-header">
            <h3 class="card-title">Prescriptions en Attente de Validation</h3>
        </div>
        <div class="card-body">
            <div id="prescriptionsEnAttente">
                <!-- Chargement via AJAX -->
            </div>
        </div>
    </div>
</div>

<style>
.prescription-tabs {
    display: flex;
    gap: 10px;
    margin: 20px 0;
    border-bottom: 2px solid var(--border);
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
}

.tab-btn.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: #fef3c7;
    border: 2px solid #f59e0b;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    color: #92400e;
}

.checkbox-label input[type="checkbox"] {
    width: 20px;
    height: 20px;
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
    fetch('../api/get-prescriptions-attente.php?sous_sejour_id=<?php echo $sous_sejour_id; ?>')
        .then(response => response.json())
        .then(data => {
            displayPrescriptions(data);
        });
}

function displayPrescriptions(data) {
    const container = document.getElementById('prescriptionsEnAttente');
    
    if (data.length === 0) {
        container.innerHTML = '<p class="text-muted">Aucune prescription en attente</p>';
        return;
    }
    
    let html = '<table class="table"><thead><tr><th>Type</th><th>LibellÃ©</th><th>Statut</th><th>Date</th></tr></thead><tbody>';
    
    data.forEach(item => {
        html += `<tr>
            <td><span class="badge badge-info">${item.type}</span></td>
            <td>${item.libelle}</td>
            <td><span class="badge badge-warning">${item.statut}</span></td>
            <td>${item.date}</td>
        </tr>`;
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

// Charger au dÃ©marrage
loadPrescriptionsEnAttente();
setInterval(loadPrescriptionsEnAttente, 30000); // Actualiser toutes les 30s
</script>

<?php include '../views/includes/header.php'; ?>