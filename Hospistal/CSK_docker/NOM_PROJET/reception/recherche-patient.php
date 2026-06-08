<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();
if (!hasPermission('reception')) redirect('../index.php');

$database = new Database();
$db = $database->getConnection();

$resultats  = [];
$searched   = false;
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['recherche'])) {
    $type_recherche = $_GET['type_recherche'] ?? 'nom';
    $searched = true;

    try {
        if ($type_recherche === 'nom') {
            $nom    = trim($_GET['nom'] ?? '');
            $prenom = trim($_GET['prenom'] ?? '');

            if (empty($nom)) {
                $error = "Veuillez saisir au moins un nom.";
            } else {
                $query = "SELECT
                            p.idpatient, p.numero_dossier, p.nom, p.prenom, p.postnom,
                            p.date_naissance, p.sexe, p.telephone1, p.type_patient,
                            s.nom  AS societe_nom,
                            c.nom  AS categorie_nom
                          FROM patient p
                          LEFT JOIN societe   s ON p.idsociete   = s.idsociete
                          LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
                          WHERE (p.nom LIKE :search OR p.prenom LIKE :search OR p.postnom LIKE :search)";
                $params = [':search' => '%' . $nom . '%'];

                if (!empty($prenom)) {
                    $query .= " AND p.prenom LIKE :prenom";
                    $params[':prenom'] = '%' . $prenom . '%';
                }
                $query .= " ORDER BY p.nom, p.prenom LIMIT 50";

                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

        } elseif ($type_recherche === 'dossier') {
            $numero = trim($_GET['numero_dossier'] ?? '');
            if (empty($numero)) {
                $error = "Veuillez saisir un numéro de dossier.";
            } else {
                $stmt = $db->prepare("SELECT p.*, c.nom AS categorie_nom, s.nom AS societe_nom
                                      FROM patient p
                                      LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
                                      LEFT JOIN societe   s ON p.idsociete   = s.idsociete
                                      WHERE p.numero_dossier LIKE :n LIMIT 20");
                $stmt->execute([':n' => '%' . $numero . '%']);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

        } elseif ($type_recherche === 'date') {
            $date = $_GET['date_naissance'] ?? '';
            if (empty($date)) {
                $error = "Veuillez saisir une date de naissance.";
            } else {
                $stmt = $db->prepare("SELECT p.*, c.nom AS categorie_nom, s.nom AS societe_nom
                                      FROM patient p
                                      LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
                                      LEFT JOIN societe   s ON p.idsociete   = s.idsociete
                                      WHERE p.date_naissance = :d ORDER BY p.nom");
                $stmt->execute([':d' => $date]);
                $resultats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        $error = "Erreur de recherche : " . $e->getMessage();
    }
}

$pageTitle = "Recherche Patient - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-search"></i> Recherche Patient</h1>
    <a href="nouveau-patient.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouveau Patient
    </a>
</div>

<!-- Formulaire de recherche -->
<div class="card">
    <div class="card-header"><h3 class="card-title">Critères de recherche</h3></div>
    <div class="card-body">
        <form method="GET" action="">
            <input type="hidden" name="recherche" value="1">
            <div class="form-row" style="margin-bottom:15px;">
                <div class="form-group">
                    <label>Type de recherche</label>
                    <select name="type_recherche" class="form-control" id="typeRecherche" onchange="toggleFields()">
                        <option value="nom"    <?php echo ($_GET['type_recherche'] ?? '') === 'nom'    ? 'selected' : ''; ?>>Par nom</option>
                        <option value="dossier"<?php echo ($_GET['type_recherche'] ?? '') === 'dossier'? 'selected' : ''; ?>>Par N° dossier</option>
                        <option value="date"   <?php echo ($_GET['type_recherche'] ?? '') === 'date'   ? 'selected' : ''; ?>>Par date de naissance</option>
                    </select>
                </div>
            </div>

            <div id="fieldNom" class="form-row">
                <div class="form-group">
                    <label>Nom</label>
                    <input type="text" name="nom" class="form-control" placeholder="Entrez le nom..."
                           value="<?php echo htmlspecialchars($_GET['nom'] ?? ''); ?>" autofocus>
                </div>
                <div class="form-group">
                    <label>Prénom (optionnel)</label>
                    <input type="text" name="prenom" class="form-control" placeholder="Prénom..."
                           value="<?php echo htmlspecialchars($_GET['prenom'] ?? ''); ?>">
                </div>
            </div>

            <div id="fieldDossier" class="form-row" style="display:none">
                <div class="form-group">
                    <label>Numéro de dossier</label>
                    <input type="text" name="numero_dossier" class="form-control" placeholder="PAT000001..."
                           value="<?php echo htmlspecialchars($_GET['numero_dossier'] ?? ''); ?>">
                </div>
            </div>

            <div id="fieldDate" class="form-row" style="display:none">
                <div class="form-group">
                    <label>Date de naissance</label>
                    <input type="date" name="date_naissance" class="form-control"
                           value="<?php echo htmlspecialchars($_GET['date_naissance'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-actions" style="justify-content:flex-start;margin-top:10px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Rechercher
                </button>
                <a href="recherche-patient.php" class="btn btn-outline">
                    <i class="fas fa-redo"></i> Réinitialiser
                </a>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<!-- Résultats -->
<?php if ($searched && empty($error)): ?>
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-list"></i> Résultats
            <span class="badge badge-primary"><?php echo count($resultats); ?></span>
        </h3>
    </div>
    <div class="card-body">
        <?php if (empty($resultats)): ?>
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <h3>Aucun patient trouvé</h3>
                <p>Essayez avec d'autres critères ou <a href="nouveau-patient.php">créez un nouveau dossier</a>.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table id="tablePatients">
                    <thead>
                        <tr>
                            <th>N° Dossier</th>
                            <th>Nom complet</th>
                            <th>Né(e) le</th>
                            <th>Sexe</th>
                            <th>Téléphone</th>
                            <th>Type</th>
                            <th>Catégorie</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultats as $p): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['numero_dossier']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['nom'] . ' ' . $p['prenom'] . ($p['postnom'] ? ' ' . $p['postnom'] : '')); ?></td>
                            <td><?php echo formatDate($p['date_naissance']); ?></td>
                            <td><?php echo $p['sexe'] === 'M' ? 'Masculin' : 'Féminin'; ?></td>
                            <td><?php echo htmlspecialchars($p['telephone1'] ?? '-'); ?></td>
                            <td>
                                <span class="badge <?php echo $p['type_patient'] === 'conventionne' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo $p['type_patient'] === 'conventionne' ? 'Conventionné' : 'Privé'; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($p['categorie_nom'] ?? '-'); ?></td>
                            <td>
                                <a href="dossier-patient.php?id=<?php echo $p['idpatient']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-folder-open"></i> Dossier
                                </a>
                                <a href="creer-sejour.php?idpatient=<?php echo $p['idpatient']; ?>" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-door-open"></i> Séjour
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
function toggleFields() {
    const type = document.getElementById('typeRecherche').value;
    document.getElementById('fieldNom').style.display    = type === 'nom'     ? '' : 'none';
    document.getElementById('fieldDossier').style.display= type === 'dossier' ? '' : 'none';
    document.getElementById('fieldDate').style.display   = type === 'date'    ? '' : 'none';
}
// Init au chargement
toggleFields();
</script>
<?php include '../views/includes/footer.php'; ?>
