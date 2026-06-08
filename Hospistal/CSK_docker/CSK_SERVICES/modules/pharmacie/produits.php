<?php
/**
 * Module Pharmacie - Catalogue des produits
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

$familles = $conn_base->query("SELECT * FROM famiprod ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$unites = $conn_base->query("SELECT * FROM unite ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$voies = $conn_base->query("SELECT * FROM voie_prod ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT p.*, f.nom as famille, u.nom as unite, v.nom as voie
          FROM prodpharma p
          LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
          LEFT JOIN unite u ON p.idunite = u.idunite
          LEFT JOIN voie_prod v ON p.idvoie_prod = v.idvoie_prod
          ORDER BY p.libelle";
$produits = $conn_base->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-capsule-pill me-2" style="color: #198754;"></i>Catalogue des produits pharmaceutiques</h4>
    <div class="btn-group">
        <a href="index.php?page=pharmacie&action=dashboard" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour</a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#produitModal"><i class="bi bi-plus-circle"></i> Nouveau produit</button>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Catalogue</h6>
        <div><input type="text" id="searchProduit" class="form-control form-control-sm" placeholder="Rechercher..." style="width: 250px;"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="produitsTable">
                <thead class="table-light">
                    <tr>
                        <th>Code</th><th>Libellé</th><th>Type</th><th>Famille</th><th>Voie</th><th>Unité</th>
                        <th class="text-center">Prix achat</th><th class="text-center">Prix vente</th>
                        <th class="text-center">Seuil alerte</th><th class="text-center">Seuil réappro</th><th>Statut</th><th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($produits as $p): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                        <td><strong><?= htmlspecialchars($p['libelle']) ?></strong></td>
                        <td><?= getPharmaTypeProduitBadge($p['type_produit']) ?></td>
                        <td><?= htmlspecialchars($p['famille'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($p['voie'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($p['unite'] ?? '-') ?></td>
                        <td class="text-center"><?= formatMoney($p['prix_achat']) ?></td>
                        <td class="text-center"><?= formatMoney($p['prix_vente_externe']) ?></td>
                        <td class="text-center"><?= $p['seuil_alerte'] ?></td>
                        <td class="text-center"><?= $p['seuil_reappro'] ?></td>
                        <td><span class="badge <?= $p['actif'] ? 'bg-success' : 'bg-secondary' ?>"><?= $p['actif'] ? 'Actif' : 'Inactif' ?></span></td>
                        <td class="text-center"><button class="btn btn-sm btn-outline-primary" onclick="editerProduit(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="bi bi-pencil"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Ajout/Modification Produit -->
<div class="modal fade" id="produitModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><i class="bi bi-plus-circle"></i> Nouveau produit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../api/save-produit.php">
                <div class="modal-body">
                    <input type="hidden" name="idprodpharma" id="edit_idprodpharma">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Libellé *</label><input type="text" name="libelle" id="edit_libelle" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Code *</label><input type="text" name="code" id="edit_code" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Type *</label><select name="type_produit" id="edit_type" class="form-select" required>
                            <option value="medicament">Médicament</option><option value="consommable">Consommable</option>
                            <option value="dispositif_medical">Dispositif médical</option><option value="reactif">Réactif</option>
                            <option value="solution">Solution</option><option value="autre">Autre</option>
                        </select></div>
                        <div class="col-md-4"><label class="form-label">Famille</label><select name="idfamiprod" id="edit_famille" class="form-select"><option value="">-- Aucune --</option>
                            <?php foreach ($familles as $f): ?><option value="<?= $f['idfamiprod'] ?>"><?= htmlspecialchars($f['nom']) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-md-4"><label class="form-label">Voie d'administration</label><select name="idvoie_prod" id="edit_voie" class="form-select"><option value="">-- Aucune --</option>
                            <?php foreach ($voies as $v): ?><option value="<?= $v['idvoie_prod'] ?>"><?= htmlspecialchars($v['nom']) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-md-4"><label class="form-label">Unité</label><select name="idunite" id="edit_unite" class="form-select"><option value="">-- Aucune --</option>
                            <?php foreach ($unites as $u): ?><option value="<?= $u['idunite'] ?>"><?= htmlspecialchars($u['nom']) ?></option><?php endforeach; ?>
                        </select></div>
                        <div class="col-md-4"><label class="form-label">Prix d'achat (FC)</label><input type="number" name="prix_achat" id="edit_prix_achat" class="form-control" step="0.01" min="0" value="0"></div>
                        <div class="col-md-4"><label class="form-label">Prix de vente (FC)</label><input type="number" name="prix_vente_externe" id="edit_prix_vente" class="form-control" step="0.01" min="0" value="0"></div>
                        <div class="col-md-4"><label class="form-label">Seuil d'alerte</label><input type="number" name="seuil_alerte" id="edit_seuil_alerte" class="form-control" min="0" value="10"></div>
                        <div class="col-md-4"><label class="form-label">Seuil de réapprovisionnement</label><input type="number" name="seuil_reappro" id="edit_seuil_reappro" class="form-control" min="0" value="50"></div>
                        <div class="col-md-4"><label class="form-label">Dosage</label><input type="text" name="dosage" id="edit_dosage" class="form-control" placeholder="Ex: 500mg"></div>
                        <div class="col-md-12"><label class="form-label">Description</label><textarea name="description" id="edit_description" class="form-control" rows="2"></textarea></div>
                        <div class="col-md-12"><div class="form-check"><input type="checkbox" name="actif" id="edit_actif" class="form-check-input" value="1" checked><label class="form-check-label">Produit actif</label></div></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('searchProduit').addEventListener('keyup', function() {
    const search = this.value.toLowerCase();
    document.querySelectorAll('#produitsTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(search) ? '' : 'none';
    });
});

function editerProduit(produit) {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil"></i> Modifier produit';
    document.getElementById('edit_idprodpharma').value = produit.idprodpharma || '';
    document.getElementById('edit_libelle').value = produit.libelle || '';
    document.getElementById('edit_code').value = produit.code || '';
    document.getElementById('edit_type').value = produit.type_produit || 'medicament';
    document.getElementById('edit_famille').value = produit.idfamiprod || '';
    document.getElementById('edit_voie').value = produit.idvoie_prod || '';
    document.getElementById('edit_unite').value = produit.idunite || '';
    document.getElementById('edit_dosage').value = produit.dosage || '';
    document.getElementById('edit_prix_achat').value = produit.prix_achat || 0;
    document.getElementById('edit_prix_vente').value = produit.prix_vente_externe || 0;
    document.getElementById('edit_seuil_alerte').value = produit.seuil_alerte || 10;
    document.getElementById('edit_seuil_reappro').value = produit.seuil_reappro || 50;
    document.getElementById('edit_description').value = produit.description || '';
    document.getElementById('edit_actif').checked = produit.actif == 1;
    new bootstrap.Modal(document.getElementById('produitModal')).show();
}

document.getElementById('produitModal').addEventListener('show.bs.modal', function(event) {
    if (!event.relatedTarget) {
        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle"></i> Nouveau produit';
        document.getElementById('edit_idprodpharma').value = '';
        document.getElementById('edit_libelle').value = '';
        document.getElementById('edit_code').value = '';
        document.getElementById('edit_type').value = 'medicament';
        document.getElementById('edit_famille').value = '';
        document.getElementById('edit_voie').value = '';
        document.getElementById('edit_unite').value = '';
        document.getElementById('edit_dosage').value = '';
        document.getElementById('edit_prix_achat').value = 0;
        document.getElementById('edit_prix_vente').value = 0;
        document.getElementById('edit_seuil_alerte').value = 10;
        document.getElementById('edit_seuil_reappro').value = 50;
        document.getElementById('edit_description').value = '';
        document.getElementById('edit_actif').checked = true;
    }
});
</script>