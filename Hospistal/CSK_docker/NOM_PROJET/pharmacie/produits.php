<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// RÃ©cupÃ©rer les produits
$query = "SELECT p.*, f.nom as famille, u.nom as unite, v.nom as voie
          FROM prodpharma p
          LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
          LEFT JOIN unite u ON p.idunite = u.idunite
          LEFT JOIN voie_prod v ON p.idvoie_prod = v.idvoie_prod
          ORDER BY p.libelle";

$stmt = $db->prepare($query);
$stmt->execute();
$produits = $stmt->fetchAll();

// RÃ©cupÃ©rer les familles, unitÃ©s, voies pour les formulaires
$familles = $db->query("SELECT * FROM famiprod ORDER BY nom")->fetchAll();
$unites = $db->query("SELECT * FROM unite ORDER BY nom")->fetchAll();
$voies = $db->query("SELECT * FROM voie_prod ORDER BY nom")->fetchAll();

$pageTitle = "Produits Pharmaceutiques - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-capsules"></i> Produits Pharmaceutiques</h1>
    <div class="header-actions">
        <a href="index.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <button class="btn btn-primary" onclick="showModal('ajouterProduit')">
            <i class="fas fa-plus"></i> Nouveau Produit
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Catalogue des Produits</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>LibellÃ©</th>
                        <th>Famille</th>
                        <th>Type</th>
                        <th>Voie</th>
                        <th>UnitÃ©</th>
                        <th>Prix Achat</th>
                        <th>Prix Vente</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($produits as $produit): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($produit['code']); ?></strong></td>
                        <td>
                            <strong><?php echo htmlspecialchars($produit['libelle']); ?></strong>
                            <?php if (!$produit['actif']): ?>
                                <span class="badge badge-danger">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($produit['famille'] ?? '-'); ?></td>
                        <td>
                            <span class="badge <?php echo $produit['type_produit'] === 'medicament' ? 'badge-primary' : 'badge-info'; ?>">
                                <?php echo ucfirst($produit['type_produit']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($produit['voie'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($produit['unite'] ?? '-'); ?></td>
                        <td><?php echo formatMoney($produit['prix_achat']); ?></td>
                        <td><?php echo formatMoney($produit['prix_vente_externe']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $produit['actif'] ? 'success' : 'danger'; ?>">
                                <?php echo $produit['actif'] ? 'Actif' : 'Inactif'; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info" 
                                    onclick="editerProduit(<?php echo htmlspecialchars(json_encode($produit)); ?>)">
                                <i class="fas fa-edit"></i> Modifier
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Ajouter Produit -->
<div id="ajouterProduit" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Nouveau Produit</h3>
            <span class="close" onclick="closeModal('ajouterProduit')">&times;</span>
        </div>
        <div class="modal-body">
            <form id="formProduit" method="POST" action="../api/save-produit.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="libelle">LibellÃ© *</label>
                        <input type="text" id="libelle" name="libelle" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="code">Code *</label>
                        <input type="text" id="code" name="code" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="type_produit">Type *</label>
                        <select id="type_produit" name="type_produit" class="form-control" required>
                            <option value="medicament">MÃ©dicament</option>
                            <option value="consommable">Consommable</option>
                            <option value="autre">Autre</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="idfamiprod">Famille</label>
                        <select id="idfamiprod" name="idfamiprod" class="form-control">
                            <option value="">-- SÃ©lectionner --</option>
                            <?php foreach ($familles as $famille): ?>
                                <option value="<?php echo $famille['idfamiprod']; ?>">
                                    <?php echo htmlspecialchars($famille['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="idvoie_prod">Voie d'administration</label>
                        <select id="idvoie_prod" name="idvoie_prod" class="form-control">
                            <option value="">-- SÃ©lectionner --</option>
                            <?php foreach ($voies as $voie): ?>
                                <option value="<?php echo $voie['idvoie_prod']; ?>">
                                    <?php echo htmlspecialchars($voie['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="idunite">UnitÃ©</label>
                        <select id="idunite" name="idunite" class="form-control">
                            <option value="">-- SÃ©lectionner --</option>
                            <?php foreach ($unites as $unite): ?>
                                <option value="<?php echo $unite['idunite']; ?>">
                                    <?php echo htmlspecialchars($unite['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="prix_achat">Prix d'achat (FC)</label>
                        <input type="number" id="prix_achat" name="prix_achat" class="form-control" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="prix_vente_externe">Prix de vente (FC)</label>
                        <input type="number" id="prix_vente_externe" name="prix_vente_externe" class="form-control" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="seuil_alerte">Seuil d'alerte</label>
                        <input type="number" id="seuil_alerte" name="seuil_alerte" class="form-control" min="0" value="10">
                    </div>
                    
                    <div class="form-group">
                        <label for="seuil_reappro">Seuil de rÃ©approvisionnement</label>
                        <input type="number" id="seuil_reappro" name="seuil_reappro" class="form-control" min="0" value="50">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="actif" value="1" checked> Produit actif
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('ajouterProduit')">Annuler</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
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

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: var(--primary);
}

.close {
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #64748b;
}

.close:hover {
    color: var(--dark);
}

.modal-body {
    padding: 20px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
</style>

<script>
function showModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function editerProduit(produit) {
    // Remplir le formulaire avec les donnÃ©es du produit
    document.getElementById('libelle').value = produit.libelle;
    document.getElementById('code').value = produit.code;
    document.getElementById('type_produit').value = produit.type_produit;
    document.getElementById('idfamiprod').value = produit.idfamiprod || '';
    document.getElementById('idvoie_prod').value = produit.idvoie_prod || '';
    document.getElementById('idunite').value = produit.idunite || '';
    document.getElementById('prix_achat').value = produit.prix_achat;
    document.getElementById('prix_vente_externe').value = produit.prix_vente_externe;
    document.getElementById('seuil_alerte').value = produit.seuil_alerte;
    document.getElementById('seuil_reappro').value = produit.seuil_reappro;
    
    // Ajouter un champ hidden pour l'ID
    if (!document.getElementById('idprodpharma')) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.id = 'idprodpharma';
        input.name = 'idprodpharma';
        document.getElementById('formProduit').appendChild(input);
    }
    document.getElementById('idprodpharma').value = produit.idprodpharma;
    
    showModal('ajouterProduit');
}

// Fermer la modal en cliquant en dehors
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php include '../views/includes/footer.php'; ?>