<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// RÃ©cupÃ©rer les donnÃ©es de rÃ©fÃ©rence
$categories = $db->query("SELECT * FROM categorie ORDER BY nom")->fetchAll();
$societes = $db->query("SELECT * FROM societe WHERE actif = 1 ORDER BY nom")->fetchAll();
$communes = $db->query("SELECT * FROM commune ORDER BY nom")->fetchAll();
$quartiers = $db->query("SELECT q.*, c.nom as commune_nom FROM quartier q JOIN commune c ON q.idcommune = c.idcommune ORDER BY c.nom, q.nom")->fetchAll();
$groupes_sanguins = $db->query("SELECT * FROM grsanguin ORDER BY nom")->fetchAll();
$religions = $db->query("SELECT * FROM religion ORDER BY nom")->fetchAll();
$ethnies = $db->query("SELECT * FROM ethnie ORDER BY nom")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation et crÃ©ation du patient
    $nom = sanitizeInput($_POST['nom'] ?? '');
    $prenom = sanitizeInput($_POST['prenom'] ?? '');
    $date_naissance = $_POST['date_naissance'] ?? '';
    $sexe = $_POST['sexe'] ?? '';
    
    if (!empty($nom) && !empty($prenom) && !empty($date_naissance) && !empty($sexe)) {
        try {
            $patient = new Patient($db);
            
            // Remplir les propriÃ©tÃ©s
            $patient->nom = $nom;
            $patient->prenom = $prenom;
            $patient->postnom = sanitizeInput($_POST['postnom'] ?? '');
            $patient->date_naissance = $date_naissance;
            $patient->lieu_naissance = sanitizeInput($_POST['lieu_naissance'] ?? '');
            $patient->sexe = $sexe;
            $patient->etat_civil = $_POST['etat_civil'] ?? 'celibataire';
            $patient->profession = sanitizeInput($_POST['profession'] ?? '');
            $patient->nationalite = sanitizeInput($_POST['nationalite'] ?? 'Congolaise');
            
            // Adresse
            $patient->idquartier = !empty($_POST['idquartier']) ? $_POST['idquartier'] : null;
            $patient->avenue = sanitizeInput($_POST['avenue'] ?? '');
            $patient->numero = sanitizeInput($_POST['numero'] ?? '');
            
            // Contact
            $patient->telephone1 = sanitizeInput($_POST['telephone1'] ?? '');
            $patient->telephone2 = sanitizeInput($_POST['telephone2'] ?? '');
            $patient->email = sanitizeInput($_POST['email'] ?? '');
            
            // Informations mÃ©dicales
            $patient->idgrsanguin = !empty($_POST['idgrsanguin']) ? $_POST['idgrsanguin'] : null;
            $patient->idethnie = !empty($_POST['idethnie']) ? $_POST['idethnie'] : null;
            $patient->idreligion = !empty($_POST['idreligion']) ? $_POST['idreligion'] : null;
            
            // Type patient et convention
            $patient->type_patient = $_POST['type_patient'] ?? 'prive';
            $patient->idsociete = ($patient->type_patient === 'conventionne' && !empty($_POST['idsociete'])) ? $_POST['idsociete'] : null;
            $patient->idcategorie = !empty($_POST['idcategorie']) ? $_POST['idcategorie'] : null;
            $patient->numero_carte_assurance = sanitizeInput($_POST['numero_carte_assurance'] ?? '');
            
            // Personne Ã  contacter
            $patient->nom_contact = sanitizeInput($_POST['nom_contact'] ?? '');
            $patient->telephone_contact = sanitizeInput($_POST['telephone_contact'] ?? '');
            $patient->lien_parente = sanitizeInput($_POST['lien_parente'] ?? '');
            
            $patient->idutilisateur = $_SESSION['user_id'];
            
            if ($patient->create()) {
                $success = "Patient enregistrÃ© avec succÃ¨s ! NÂ° Dossier: " . $patient->numero_dossier;
                // Redirection vers la crÃ©ation du sÃ©jour
                header("Location: creer-sejour.php?patient_id=" . $patient->idpatient . "&success=1");
                exit();
            } else {
                $error = "Erreur lors de l'enregistrement du patient.";
            }
        } catch (Exception $e) {
            $error = "Erreur : " . $e->getMessage();
        }
    } else {
        $error = "Veuillez remplir tous les champs obligatoires.";
    }
}

$pageTitle = "Nouveau Patient - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-plus"></i> Enregistrement d'un Nouveau Patient</h1>
    <a href="recherche-patient.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour Ã  la recherche
    </a>
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

<form method="POST" class="patient-form">
    <!-- Section IdentitÃ© -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-id-card"></i> IdentitÃ© du Patient</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="nom">Nom *</label>
                    <input type="text" id="nom" name="nom" class="form-control" required 
                           value="<?php echo htmlspecialchars($_POST['nom'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="prenom">PrÃ©nom *</label>
                    <input type="text" id="prenom" name="prenom" class="form-control" required
                           value="<?php echo htmlspecialchars($_POST['prenom'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="postnom">Postnom</label>
                    <input type="text" id="postnom" name="postnom" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['postnom'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="date_naissance">Date de naissance *</label>
                    <input type="date" id="date_naissance" name="date_naissance" class="form-control" required
                           value="<?php echo htmlspecialchars($_POST['date_naissance'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="lieu_naissance">Lieu de naissance</label>
                    <input type="text" id="lieu_naissance" name="lieu_naissance" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['lieu_naissance'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="sexe">Sexe *</label>
                    <select id="sexe" name="sexe" class="form-control" required>
                        <option value="">-- SÃ©lectionner --</option>
                        <option value="M" <?php echo ($_POST['sexe'] ?? '') === 'M' ? 'selected' : ''; ?>>Masculin</option>
                        <option value="F" <?php echo ($_POST['sexe'] ?? '') === 'F' ? 'selected' : ''; ?>>FÃ©minin</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="etat_civil">Ã‰tat civil</label>
                    <select id="etat_civil" name="etat_civil" class="form-control">
                        <option value="celibataire">CÃ©libataire</option>
                        <option value="marie">MariÃ©(e)</option>
                        <option value="divorce">DivorcÃ©(e)</option>
                        <option value="veuf">Veuf/Veuve</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="profession">Profession</label>
                    <input type="text" id="profession" name="profession" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['profession'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="nationalite">NationalitÃ©</label>
                    <input type="text" id="nationalite" name="nationalite" class="form-control" 
                           value="<?php echo htmlspecialchars($_POST['nationalite'] ?? 'Congolaise'); ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Section Contact -->
    <div class="card mt-2">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-phone"></i> CoordonnÃ©es</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="telephone1">TÃ©lÃ©phone 1 *</label>
                    <input type="tel" id="telephone1" name="telephone1" class="form-control" required
                           value="<?php echo htmlspecialchars($_POST['telephone1'] ?? ''); ?>"
                           placeholder="+243 XXX XXX XXX">
                </div>
                <div class="form-group">
                    <label for="telephone2">TÃ©lÃ©phone 2</label>
                    <input type="tel" id="telephone2" name="telephone2" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['telephone2'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="idquartier">Quartier</label>
                    <select id="idquartier" name="idquartier" class="form-control">
                        <option value="">-- SÃ©lectionner --</option>
                        <?php foreach ($quartiers as $q): ?>
                            <option value="<?php echo $q['idquartier']; ?>">
                                <?php echo htmlspecialchars($q['nom'] . ' (' . $q['commune_nom'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="avenue">Avenue</label>
                    <input type="text" id="avenue" name="avenue" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['avenue'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="numero">NumÃ©ro</label>
                    <input type="text" id="numero" name="numero" class="form-control"
                           value="<?php echo htmlspecialchars($_POST['numero'] ?? ''); ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Section Type de Patient -->
    <div class="card mt-2">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user-tag"></i> Type de Patient</h3>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label>Type de patient *</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="type_patient" value="prive" checked
                                   onchange="toggleConvention(false)">
                            <span>PrivÃ©</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="type_patient" value="conventionne"
                                   onchange="toggleConvention(true)">
                            <span>ConventionnÃ©</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div id="conventionFields" style="display: none;">
                <div class="form-row">
                    <div class="form-group">
                        <label for="idsociete">SociÃ©tÃ© / Assurance</label>
                        <select id="idsociete" name="idsociete" class="form-control">
                            <option value="">-- SÃ©lectionner --</option>
                            <?php foreach ($societes as $s): ?>
                                <option value="<?php echo $s['idsociete']; ?>">
                                    <?php echo htmlspecialchars($s['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_carte_assurance">NÂ° Carte Assurance</label>
                        <input type="text" id="numero_carte_assurance" name="numero_carte_assurance" class="form-control">
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="idcategorie">CatÃ©gorie</label>
                    <select id="idcategorie" name="idcategorie" class="form-control">
                        <option value="">-- SÃ©lectionner --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['idcategorie']; ?>">
                                <?php echo htmlspecialchars($cat['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Boutons d'action -->
    <div class="form-actions">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Enregistrer le Patient
        </button>
        <a href="recherche-patient.php" class="btn btn-outline btn-lg">
            <i class="fas fa-times"></i> Annuler
        </a>
    </div>
</form>

<style>
.radio-group {
    display: flex;
    gap: 20px;
    margin-top: 8px;
}

.radio-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 10px 16px;
    border: 2px solid var(--border);
    border-radius: 6px;
    transition: all 0.2s;
}

.radio-label:hover {
    border-color: var(--primary);
    background: var(--light);
}

.radio-label input[type="radio"]:checked + span {
    color: var(--primary);
    font-weight: 500;
}

.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid var(--border);
}
</style>

<script>
function toggleConvention(show) {
    const conventionFields = document.getElementById('conventionFields');
    conventionFields.style.display = show ? 'block' : 'none';
}
</script>

<?php include '../views/includes/footer.php'; ?>