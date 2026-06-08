<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../models/Sejour.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// VÃ©rification du paramÃ¨tre sejour_id
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: recherche-patient.php?error=missing_sejour_id');
    exit();
}

$sejour_id = intval($_GET['id']);

// âœ… RÃ©cupÃ©ration des dÃ©tails du sÃ©jour AVEC toutes les colonnes nÃ©cessaires
$query = "SELECT 
    s.*, 
    p.nom, p.prenom, p.postnom, p.numero_dossier, p.date_naissance, p.sexe,
    p.telephone1, p.type_patient, p.idpatient,
    gs.nom AS groupe_sanguin,
    
    -- Motif du sÃ©jour
    m.libelle AS motif,
    
    -- UnitÃ© via le sous-sÃ©jour actif
    um.nom AS unite_nom,
    
    -- CatÃ©gorie et sociÃ©tÃ©
    cat.nom AS categorie_nom,
    soc.nom AS societe_nom,
    
    -- Utilisateur qui a crÃ©Ã© le sÃ©jour
    u.nom AS utilisateur_nom,
    u.prenom AS utilisateur_prenom

    FROM sejour s
    JOIN patient p ON s.idpatient = p.idpatient
    LEFT JOIN grsanguin gs ON p.idgrsanguin = gs.idgrsanguin
    LEFT JOIN motif m ON s.idmotif = m.idmotif
    -- LEFT JOIN sous_sejour ss0 ON ss0.idsejour = s.idsejour AND ss0.actif = 1
    LEFT JOIN sous_sejour ss0 ON ss0.idsejour = s.idsejour AND ss0.statut = 'en_cours'
    LEFT JOIN unite_med um ON ss0.idunite_med = um.idunite_med
    LEFT JOIN categorie cat ON p.idcategorie = cat.idcategorie
    LEFT JOIN societe soc ON p.idsociete = soc.idsociete
    LEFT JOIN utilisateur u ON s.idutilisateur = u.idutilisateur

    WHERE s.idsejour = :id
    LIMIT 1";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $sejour_id);
$stmt->execute();
$sejour = $stmt->fetch();

if (!$sejour) {
    header('Location: recherche-patient.php?error=sejour_not_found');
    exit();
}

// âœ… VÃ©rifier s'il y a une consultation en attente pour ce sÃ©jour
$query_consultation = "SELECT c.idconsultation, c.statut, ss.idsous_sejour
    FROM consultations c
    JOIN sous_sejour ss ON c.idsous_sejour = ss.idsous_sejour
    WHERE ss.idsejour = :sejour_id 
    AND c.statut = 'en_attente'
    AND ss.statut = 'en_cours'
    ORDER BY c.date_consultation DESC
    LIMIT 1";

$stmt_consult = $db->prepare($query_consultation);
$stmt_consult->bindParam(':sejour_id', $sejour_id);
$stmt_consult->execute();
$consultation_attente = $stmt_consult->fetch();

// ðŸš€ REDIRECTION AUTOMATIQUE vers salle d'attente si consultation en attente
if ($consultation_attente) {
    header('Location: salle-attente.php?sejour_id=' . $sejour_id . '&consultation_id=' . $consultation_attente['idconsultation']);
    exit();
}

// RÃ©cupÃ©ration des sous-sÃ©jours
$query_ss = "SELECT ss.*, 
    um.nom as unite_nom,
    us.nom as specialite_nom,
    COUNT(DISTINCT c.idconsultation) as nb_consultations
    FROM sous_sejour ss
    LEFT JOIN unite_med um ON ss.idunite_med = um.idunite_med
    LEFT JOIN unite_hospi us ON ss.idunitehospi = us.idunitehospi
    LEFT JOIN consultations c ON ss.idsous_sejour = c.idsous_sejour
    WHERE ss.idsejour = :sejour_id
    GROUP BY ss.idsous_sejour
    ORDER BY ss.date_entree DESC";

$stmt_ss = $db->prepare($query_ss);
$stmt_ss->bindParam(':sejour_id', $sejour_id);
$stmt_ss->execute();
$sous_sejours = $stmt_ss->fetchAll();

// RÃ©cupÃ©ration des actes prescrits
$query_actes = "SELECT ap.*, 
    a.libelle as acte_nom,
    a.code as acte_code,
    ca.nom as categorie_acte,
    u.nom as prescripteur_nom, u.prenom as prescripteur_prenom,
    ss.date_entree as date_sous_sejour
    FROM actes_presc ap
    JOIN acte a ON ap.idacte = a.idacte
    JOIN categorie_acte ca ON a.idcategorie_acte = ca.idcategorie_acte
    JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
    LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
    WHERE ss.idsejour = :sejour_id
    ORDER BY ap.date_prescription DESC
    LIMIT 20";

$stmt_actes = $db->prepare($query_actes);
$stmt_actes->bindParam(':sejour_id', $sejour_id);
$stmt_actes->execute();
$actes = $stmt_actes->fetchAll();

// RÃ©cupÃ©ration des prescriptions pharmacie
$query_pharma = "SELECT pp.*, 
    prod.libelle as produit_nom,
    prod.code as produit_code,
    u.nom as prescripteur_nom, u.prenom as prescripteur_prenom,
    ss.date_entree as date_sous_sejour
    FROM pharma_presc pp
    JOIN prodpharma prod ON pp.idprodpharma = prod.idprodpharma
    JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
    LEFT JOIN utilisateur u ON pp.prescripteur = u.idutilisateur
    WHERE ss.idsejour = :sejour_id
    ORDER BY pp.date_prescription DESC
    LIMIT 20";

$stmt_pharma = $db->prepare($query_pharma);
$stmt_pharma->bindParam(':sejour_id', $sejour_id);
$stmt_pharma->execute();
$prescriptions = $stmt_pharma->fetchAll();

// Calculer l'Ã¢ge du patient
$birthDate = new DateTime($sejour['date_naissance']);
$today = new DateTime();
$age = $today->diff($birthDate);

$pageTitle = 'DÃ©tails SÃ©jour - ' . $sejour['nom'] . ' ' . $sejour['prenom'];
include '../views/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-hospital-user"></i> DÃ©tails du SÃ©jour</h1>
        <p class="subtitle">NÂ° SÃ©jour: <strong><?php echo $sejour['numero_sejour'] ?? $sejour['idsejour']; ?></strong></p>
    </div>
    <div class="header-actions">
        <a href="dossier-patient.php?id=<?php echo $sejour['idpatient']; ?>" class="btn btn-outline">
            <i class="fas fa-folder-open"></i> Dossier Patient
        </a>
        <a href="recherche-patient.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
    </div>
</div>

<!-- Informations Patient et SÃ©jour -->
<div class="sejour-overview">
    <div class="card patient-summary">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-user"></i> Patient</h3>
        </div>
        <div class="card-body">
            <div class="patient-identity">
                <div class="patient-avatar-large">
                    <?php echo strtoupper(substr($sejour['nom'], 0, 1) . substr($sejour['prenom'], 0, 1)); ?>
                </div>
                <div class="patient-info">
                    <h2><?php echo htmlspecialchars($sejour['nom'] . ' ' . $sejour['prenom']); ?></h2>
                    <?php if (!empty($sejour['postnom'])): ?>
                        <p class="postnom"><?php echo htmlspecialchars($sejour['postnom']); ?></p>
                    <?php endif; ?>
                    <div class="patient-meta">
                        <span><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($sejour['numero_dossier']); ?></span>
                        <span><i class="fas fa-birthday-cake"></i> <?php echo $age->y; ?> ans</span>
                        <span><i class="fas fa-venus-mars"></i> <?php echo $sejour['sexe'] === 'M' ? 'Masculin' : 'FÃ©minin'; ?></span>
                        <?php if (!empty($sejour['groupe_sanguin'])): ?>
                            <span><i class="fas fa-tint"></i> <?php echo htmlspecialchars($sejour['groupe_sanguin']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card sejour-summary">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-hospital"></i> Informations SÃ©jour</h3>
            <?php 
            $statut_class = $sejour['statut'] === 'en_cours' ? 'badge-success' : 
                           ($sejour['statut'] === 'termine' ? 'badge-secondary' : 'badge-warning');
            ?>
            <span class="badge <?php echo $statut_class; ?>">
                <?php echo strtoupper(str_replace('_', ' ', $sejour['statut'])); ?>
            </span>
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <span class="label">Date d'entrÃ©e</span>
                    <span class="value"><?php echo formatDateTime($sejour['date_entree']); ?></span>
                </div>
                <?php if ($sejour['date_sortie']): ?>
                <div class="info-item">
                    <span class="label">Date de sortie</span>
                    <span class="value"><?php echo formatDateTime($sejour['date_sortie']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($sejour['unite_nom'])): ?>
                <div class="info-item">
                    <span class="label">UnitÃ© mÃ©dicale</span>
                    <span class="value"><?php echo htmlspecialchars($sejour['unite_nom']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($sejour['utilisateur_nom'])): ?>
                <div class="info-item">
                    <span class="label">MÃ©decin traitant</span>
                    <span class="value">Dr. <?php echo htmlspecialchars($sejour['utilisateur_nom'] . ' ' . $sejour['utilisateur_prenom']); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <span class="label">Type patient</span>
                    <span class="value">
                        <span class="badge <?php echo $sejour['type_patient'] === 'prive' ? 'badge-warning' : 'badge-info'; ?>">
                            <?php echo $sejour['type_patient'] === 'prive' ? 'PrivÃ©' : 'ConventionnÃ©'; ?>
                        </span>
                    </span>
                </div>
                <?php if (!empty($sejour['categorie_nom'])): ?>
                <div class="info-item">
                    <span class="label">CatÃ©gorie</span>
                    <span class="value"><?php echo htmlspecialchars($sejour['categorie_nom']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($sejour['motif'])): ?>
            <div class="motif-section">
                <h4><i class="fas fa-comment-medical"></i> Motif d'hospitalisation</h4>
                <p><?php echo nl2br(htmlspecialchars($sejour['motif'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bouton crÃ©er consultation si aucune en attente -->
<?php if (!$consultation_attente): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> Aucune consultation en attente pour ce sÃ©jour.
    <a href="creer-consultation.php?sejour_id=<?php echo $sejour_id; ?>" class="btn btn-primary btn-sm ml-2">
        <i class="fas fa-plus"></i> CrÃ©er une consultation
    </a>
</div>
<?php endif; ?>

<!-- Sous-sÃ©jours -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-procedures"></i> Sous-SÃ©jours et Transferts</h3>
        <span class="badge badge-primary"><?php echo count($sous_sejours); ?> sous-sÃ©jour(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($sous_sejours)): ?>
            <p class="text-muted">Aucun sous-sÃ©jour enregistrÃ©.</p>
        <?php else: ?>
            <div class="sous-sejours-timeline">
                <?php foreach ($sous_sejours as $index => $ss): ?>
                <div class="timeline-item">
                    <div class="timeline-marker"><?php echo $index + 1; ?></div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <h4>
                                <?php if ($ss['unite_nom']): ?>
                                    <i class="fas fa-hospital-alt"></i> <?php echo htmlspecialchars($ss['unite_nom']); ?>
                                <?php elseif ($ss['specialite_nom']): ?>
                                    <i class="fas fa-user-md"></i> <?php echo htmlspecialchars($ss['specialite_nom']); ?>
                                <?php else: ?>
                                    <i class="fas fa-question-circle"></i> Non spÃ©cifiÃ©
                                <?php endif; ?>
                            </h4>
                            <?php 
                            $ss_statut_class = $ss['statut'] === 'en_cours' ? 'badge-success' : 
                                               ($ss['statut'] === 'termine' ? 'badge-secondary' : 'badge-warning');
                            ?>
                            <span class="badge <?php echo $ss_statut_class; ?>">
                                <?php echo strtoupper(str_replace('_', ' ', $ss['statut'])); ?>
                            </span>
                        </div>
                        <div class="timeline-details">
                            <span><i class="fas fa-calendar-check"></i> DÃ©but: <?php echo formatDateTime($ss['date_entree']); ?></span>
                            <?php if ($ss['date_sortie']): ?>
                                <span><i class="fas fa-calendar-times"></i> Fin: <?php echo formatDateTime($ss['date_sortie']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-notes-medical"></i> <?php echo $ss['nb_consultations']; ?> consultation(s)</span>
                        </div>
                        <?php if ($ss['statut'] === 'en_cours'): ?>
                        <div class="timeline-actions">
                            <a href="../consultation/consulter.php?sous_sejour_id=<?php echo $ss['idsous_sejour']; ?>" 
                               class="btn btn-sm btn-primary">
                                <i class="fas fa-stethoscope"></i> Consulter
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Actes prescrits -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-flask"></i> Actes Prescrits (Labo/Imagerie)</h3>
        <span class="badge badge-info"><?php echo count($actes); ?> acte(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($actes)): ?>
            <p class="text-muted">Aucun acte prescrit.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Acte</th>
                            <th>CatÃ©gorie</th>
                            <th>Prescripteur</th>
                            <th>Statut</th>
                            <th>Urgence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($actes as $acte): ?>
                        <tr>
                            <td><?php echo formatDateTime($acte['date_prescription']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($acte['acte_nom']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($acte['acte_code']); ?></small>
                            </td>
                            <td><span class="badge badge-secondary"><?php echo htmlspecialchars($acte['categorie_acte']); ?></span></td>
                            <td><?php echo htmlspecialchars($acte['prescripteur_nom'] . ' ' . $acte['prescripteur_prenom']); ?></td>
                            <td>
                                <?php 
                                $statut_badge = '';
                                switch($acte['statut_execution']) {
                                    case 'en_attente': $statut_badge = 'badge-warning'; break;
                                    case 'en_cours': $statut_badge = 'badge-info'; break;
                                    case 'termine': $statut_badge = 'badge-success'; break;
                                    case 'annule': $statut_badge = 'badge-danger'; break;
                                }
                                ?>
                                <span class="badge <?php echo $statut_badge; ?>">
                                    <?php echo strtoupper(str_replace('_', ' ', $acte['statut_execution'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($acte['urgent']): ?>
                                    <span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> URGENT</span>
                                <?php else: ?>
                                    <span class="text-muted">Normal</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Prescriptions Pharmacie -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-pills"></i> Prescriptions MÃ©dicamenteuses</h3>
        <span class="badge badge-info"><?php echo count($prescriptions); ?> prescription(s)</span>
    </div>
    <div class="card-body">
        <?php if (empty($prescriptions)): ?>
            <p class="text-muted">Aucune prescription mÃ©dicamenteuse.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>MÃ©dicament</th>
                            <th>QuantitÃ©</th>
                            <th>Posologie</th>
                            <th>Prescripteur</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prescriptions as $presc): ?>
                        <tr>
                            <td><?php echo formatDateTime($presc['date_prescription']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($presc['produit_nom']); ?></strong><br>
                                <small class="text-muted"><?php echo htmlspecialchars($presc['produit_code']); ?></small>
                            </td>
                            <td><?php echo $presc['quantite']; ?></td>
                            <td><?php echo htmlspecialchars($presc['posologie']); ?></td>
                            <td><?php echo htmlspecialchars($presc['prescripteur_nom'] . ' ' . $presc['prescripteur_prenom']); ?></td>
                            <td>
                                <?php 
                                $statut_badge = '';
                                switch($presc['statut_execution']) {
                                    case 'en_attente': $statut_badge = 'badge-warning'; break;
                                    case 'en_cours': $statut_badge = 'badge-info'; break;
                                    case 'delivre': $statut_badge = 'badge-success'; break;
                                    case 'annule': $statut_badge = 'badge-danger'; break;
                                }
                                ?>
                                <span class="badge <?php echo $statut_badge; ?>">
                                    <?php echo strtoupper(str_replace('_', ' ', $presc['statut_execution'])); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 30px;
}

.subtitle {
    color: #64748b;
    font-size: 14px;
    margin-top: 5px;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.sejour-overview {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.patient-identity {
    display: flex;
    gap: 20px;
    align-items: center;
}

.patient-avatar-large {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    font-weight: bold;
    flex-shrink: 0;
}

.patient-info h2 {
    margin: 0 0 5px 0;
    font-size: 24px;
    color: var(--dark);
}

.postnom {
    color: #64748b;
    margin: 0 0 10px 0;
    font-style: italic;
}

.patient-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    font-size: 14px;
    color: #64748b;
}

.patient-meta span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.info-item .label {
    font-size: 13px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 600;
}

.info-item .value {
    font-size: 15px;
    color: var(--dark);
    font-weight: 500;
}

.motif-section {
    padding: 15px;
    background: var(--light);
    border-radius: 8px;
    margin-top: 15px;
}

.motif-section h4 {
    font-size: 14px;
    color: var(--primary);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.motif-section p {
    margin: 0;
    line-height: 1.6;
    color: var(--dark);
}

.sous-sejours-timeline {
    position: relative;
    padding-left: 40px;
}

.sous-sejours-timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--border);
}

.timeline-item {
    position: relative;
    margin-bottom: 25px;
}

.timeline-marker {
    position: absolute;
    left: -40px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 14px;
    border: 3px solid white;
    box-shadow: 0 0 0 2px var(--primary);
}

.timeline-content {
    background: white;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 15px;
}

.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.timeline-header h4 {
    margin: 0;
    font-size: 16px;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

.timeline-details {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    font-size: 13px;
    color: #64748b;
    margin-bottom: 10px;
}

.timeline-details span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.timeline-actions {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

.text-muted {
    color: #94a3b8;
    font-style: italic;
}

.table-responsive {
    overflow-x: auto;
}

.ml-2 {
    margin-left: 10px;
}

@media (max-width: 992px) {
    .sejour-overview {
        grid-template-columns: 1fr;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include '../views/includes/footer.php'; ?>