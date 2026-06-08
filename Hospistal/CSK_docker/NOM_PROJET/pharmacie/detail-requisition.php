<?php
// pharmacie/detail-requisition.php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$idrequisition = $_GET['id'] ?? null;

if (!$idrequisition) {
    redirect('requisition.php');
}

// RÃ©cupÃ©rer les dÃ©tails de la rÃ©quisition
$query = "SELECT r.*, o.nom as officine_nom, o.idsite,
                 u.nom as user_nom, u.prenom as user_prenom,
                 ur.nom as traiteur_nom, ur.prenom as traiteur_prenom
          FROM requisition r
          JOIN officine o ON r.idofficine = o.idofficine
          JOIN utilisateur u ON r.idutilisateur = u.idutilisateur
          LEFT JOIN utilisateur ur ON r.traiteur = ur.idutilisateur
          WHERE r.idrequisition = :id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $idrequisition);
$stmt->execute();
$requisition = $stmt->fetch();

if (!$requisition) {
    redirect('requisition.php');
}

// RÃ©cupÃ©rer les lignes de la rÃ©quisition
$query_lignes = "SELECT l.*, p.libelle, p.code, p.prix_achat, p.prix_vente_externe,
                        p.type_produit, f.nom as famille
                 FROM lignesrecquisition l
                 JOIN prodpharma p ON l.idprodpharma = p.idprodpharma
                 LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
                 WHERE l.idrequisition = :idrequisition
                 ORDER BY p.libelle";

$stmt_lignes = $db->prepare($query_lignes);
$stmt_lignes->bindParam(':idrequisition', $idrequisition);
$stmt_lignes->execute();
$lignes = $stmt_lignes->fetchAll();

$pageTitle = "DÃ©tail RÃ©quisition - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-file-alt"></i> DÃ©tail de la RÃ©quisition</h1>
    <a href="requisition.php?idofficine=<?php echo $requisition['idofficine']; ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<div class="requisition-header">
    <div class="header-section">
        <h3>RÃ©quisition NÂ°</h3>
        <p class="numero"><?php echo htmlspecialchars($requisition['numero_requisition']); ?></p>
    </div>
    
    <div class="header-section">
        <h3>Officine</h3>
        <p><?php echo htmlspecialchars($requisition['officine_nom']); ?></p>
    </div>
    
    <div class="header-section">
        <h3>Statut</h3>
        <p>
            <?php
            $badge_class = [
                'en_attente' => 'badge-warning',
                'servi' => 'badge-success',
                'refuse' => 'badge-danger'
            ][$requisition['statut']];
            ?>
            <span class="badge <?php echo $badge_class; ?>">
                <?php echo ucfirst(str_replace('_', ' ', $requisition['statut'])); ?>
            </span>
        </p>
    </div>
    
    <div class="header-section">
        <h3>DemandÃ© par</h3>
        <p><?php echo htmlspecialchars($requisition['user_prenom'] . ' ' . $requisition['user_nom']); ?></p>
        <small><?php echo formatDateTime($requisition['date_requisition']); ?></small>
    </div>
    
    <?php if ($requisition['traiteur']): ?>
    <div class="header-section">
        <h3>TraitÃ© par</h3>
        <p><?php echo htmlspecialchars($requisition['traiteur_prenom'] . ' ' . $requisition['traiteur_nom']); ?></p>
        <small><?php echo formatDateTime($requisition['date_traitement']); ?></small>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Produits DemandÃ©s</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Famille</th>
                        <th>QuantitÃ© DemandÃ©e</th>
                        <th>QuantitÃ© Servie</th>
                        <th>Prix Achat</th>
                        <th>Valeur Totale</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_valeur = 0;
                    foreach ($lignes as $ligne): 
                        $valeur = $ligne['quantite_servie'] * $ligne['prix_achat'];
                        $total_valeur += $valeur;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($ligne['libelle']); ?></td>
                        <td><small><?php echo htmlspecialchars($ligne['code']); ?></small></td>
                        <td>
                            <span class="badge <?php echo $ligne['type_produit'] === 'medicament' ? 'badge-primary' : 'badge-info'; ?>">
                                <?php echo ucfirst($ligne['type_produit']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($ligne['famille'] ?? '-'); ?></td>
                        <td class="text-center">
                            <span class="badge badge-primary"><?php echo $ligne['quantite_demandee']; ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $ligne['quantite_servie'] ? 'badge-success' : 'badge-warning'; ?>">
                                <?php echo $ligne['quantite_servie'] ?? '0'; ?>
                            </span>
                        </td>
                        <td><?php echo formatMoney($ligne['prix_achat']); ?></td>
                        <td><?php echo formatMoney($valeur); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: var(--light); font-weight: bold;">
                        <td colspan="7" style="text-align: right;">VALEUR TOTALE:</td>
                        <td><?php echo formatMoney($total_valeur); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php if ($requisition['statut'] == 'servi' && $requisition['observation']): ?>
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">Observation</h3>
    </div>
    <div class="card-body">
        <p><?php echo nl2br(htmlspecialchars($requisition['observation'])); ?></p>
    </div>
</div>
<?php endif; ?>

<style>
.requisition-header {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 25px;
    margin-bottom: 25px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 25px;
}

.requisition-header h3 {
    color: var(--primary);
    font-size: 14px;
    margin-bottom: 8px;
    text-transform: uppercase;
}

.requisition-header p {
    font-size: 16px;
    margin: 0;
    color: var(--dark);
}

.requisition-header .numero {
    font-size: 24px;
    font-weight: bold;
    color: var(--primary);
}
</style>

<?php include '../views/includes/footer.php'; ?>