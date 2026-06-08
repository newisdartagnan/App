<?php

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../models/Patient.php';
require_once '../models/Sejour.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// Vérification du paramètre patient_id
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: recherche-patient.php?error=missing_id');
    exit();
}

$patient_id = intval($_GET['id']);
$patientModel = new Patient($db);
$patient = $patientModel->getById($patient_id);

if (!$patient) {
    header('Location: recherche-patient.php?error=not_found');
    exit();
}

// Récupération des séjours du patient
$sejours = $patientModel->getSejoursByPatient($patient_id);

$pageTitle = 'Dossier Patient - ' . $patient['nom'] . ' ' . $patient['prenom'];
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-folder-open"></i> Dossier Patient : <?php echo htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']); ?></h1>
    <a href="recherche-patient.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Retour</a>
</div>

<!-- Informations générales -->
<div class="card">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-user"></i> Informations Générales</h3></div>
    <div class="card-body">
        <div class="info-grid">
            <div><strong>NÃ‚Â° Dossier :</strong> <?php echo $patient['numero_dossier']; ?></div>
            <div><strong>Nom :</strong> <?php echo htmlspecialchars($patient['nom']); ?></div>
            <div><strong>Prénom :</strong> <?php echo htmlspecialchars($patient['prenom']); ?></div>
            <div><strong>Postnom :</strong> <?php echo htmlspecialchars($patient['postnom']); ?></div>
            <div><strong>Date de naissance :</strong> <?php echo htmlspecialchars($patient['date_naissance']); ?></div>
            <div><strong>Sexe :</strong> <?php echo htmlspecialchars($patient['sexe']); ?></div>
            <div><strong>Nationalité :</strong> <?php echo htmlspecialchars($patient['nationalite']); ?></div>
            <div><strong>àâ€°tat civil :</strong> <?php echo htmlspecialchars($patient['etat_civil']); ?></div>
            <div><strong>Profession :</strong> <?php echo htmlspecialchars($patient['profession']); ?></div>
        </div>
    </div>
</div>

<!-- Coordonnées -->
<div class="card mt-2">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-phone"></i> Coordonnées</h3></div>
    <div class="card-body">
        <div class="info-grid">
            <div><strong>Téléphone 1 :</strong> <?php echo htmlspecialchars($patient['telephone1']); ?></div>
            <div><strong>Téléphone 2 :</strong> <?php echo htmlspecialchars($patient['telephone2']); ?></div>
            <div><strong>Email :</strong> <?php echo htmlspecialchars($patient['email']); ?></div>
            <div><strong>Adresse :</strong> <?php echo htmlspecialchars(($patient['avenue'] ?? '') . ' ' . ($patient['numero'] ?? '')); ?></div>
            <div><strong>Quartier :</strong> <?php echo htmlspecialchars($patient['quartier_nom'] ?? ''); ?></div>
            <div><strong>Commune :</strong> <?php echo htmlspecialchars($patient['commune_nom'] ?? ''); ?></div>
        </div>
    </div>
</div>

<!-- Informations médicales -->
<div class="card mt-2">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-stethoscope"></i> Informations Médicales</h3></div>
    <div class="card-body">
        <div class="info-grid">
            <div><strong>Groupe sanguin :</strong> <?php echo htmlspecialchars($patient['groupe_sanguin'] ?? ''); ?></div>
            <div><strong>Ethnie :</strong> <?php echo htmlspecialchars($patient['ethnie_nom'] ?? ''); ?></div>
            <div><strong>Religion :</strong> <?php echo htmlspecialchars($patient['religion_nom'] ?? ''); ?></div>
        </div>
    </div>
</div>

<!-- Type patient -->
<div class="card mt-2">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-user-tag"></i> Type de Patient</h3></div>
    <div class="card-body">
        <div class="info-grid">
            <div><strong>Type :</strong> <?php echo htmlspecialchars($patient['type_patient']); ?></div>
            <div><strong>Catégorie :</strong> <?php echo htmlspecialchars($patient['categorie_nom'] ?? ''); ?></div>
            <?php if ($patient['type_patient'] === 'conventionne'): ?>
                <div><strong>Société :</strong> <?php echo htmlspecialchars($patient['societe_nom'] ?? ''); ?></div>
                <div><strong>Carte assurance :</strong> <?php echo htmlspecialchars($patient['numero_carte_assurance']); ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Personne de contact -->
<div class="card mt-2">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-users"></i> Personne à  contacter</h3></div>
    <div class="card-body">
        <div class="info-grid">
            <div><strong>Nom :</strong> <?php echo htmlspecialchars($patient['nom_contact']); ?></div>
            <div><strong>Téléphone :</strong> <?php echo htmlspecialchars($patient['telephone_contact']); ?></div>
            <div><strong>Lien :</strong> <?php echo htmlspecialchars($patient['lien_parente']); ?></div>
        </div>
    </div>
</div>

<!-- Séjours -->
<div class="card mt-2">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-hospital"></i> Séjours hospitaliers</h3></div>
    <div class="card-body">
        <?php if (empty($sejours)): ?>
            <p>Aucun séjour enregistré.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date entrée</th>
                        <th>Date sortie</th>
                        <th>Motif</th>
                        <th>Unité</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sejours as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['date_entree']); ?></td>
                        <td><?php echo htmlspecialchars($s['date_sortie']); ?></td>
                        <td><?php echo htmlspecialchars($s['motif']); ?></td>
                        <td><?php echo htmlspecialchars($s['unite_nom']); ?></td>
                        <td><a href="details-sejour.php?id=<?php echo $s['idsejour']; ?>" class="btn btn-sm btn-primary">Voir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <a href="creer-sejour.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-primary mt-2"><i class="fas fa-plus"></i> Nouveau séjour</a>
    </div>
</div>

<?php include '../views/includes/footer.php'; ?>