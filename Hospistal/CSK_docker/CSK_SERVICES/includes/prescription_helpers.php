<?php
/**
 * Helpers Prescriptions - Ecriture dans csk_base depuis csk_services.
 *
 * VERSION FLEXIBLE : Gère les cas où sous_sejour n'existe pas
 */

// Categories d'actes pour le filtrage
define('CAT_LABO', [6]);                   // Biologie
define('CAT_IMAGERIE', [5, 10, 22]);       // Radiologie, Echographie, IRM/Scanner

/**
 * Recherche de patients dans csk_base
 */
function searchPatients(PDO $conn_base, string $term, int $limit = 40): array
{
    $term = trim($term);
    
    if (empty($term)) {
        return [];
    }
    
    $search = "%$term%";
    
    $sql = "SELECT 
                p.idpatient,
                p.numero_dossier,
                p.nom,
                p.prenom,
                p.postnom,
                p.date_naissance,
                p.sexe,
                p.telephone1 AS telephone,
                p.type_patient,
                s.nom AS societe_nom,
                c.nom AS categorie_nom
            FROM patient p
            LEFT JOIN societe s ON p.idsociete = s.idsociete
            LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
            WHERE p.nom LIKE ? 
               OR p.prenom LIKE ? 
               OR p.postnom LIKE ?
               OR p.numero_dossier LIKE ?
            ORDER BY p.nom, p.prenom
            LIMIT ?";
    
    $stmt = $conn_base->prepare($sql);
    $stmt->bindValue(1, $search, PDO::PARAM_STR);
    $stmt->bindValue(2, $search, PDO::PARAM_STR);
    $stmt->bindValue(3, $search, PDO::PARAM_STR);
    $stmt->bindValue(4, $search, PDO::PARAM_STR);
    $stmt->bindValue(5, $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sejours actifs d'un patient - VERSION FLEXIBLE
 * Cherche d'abord les sous_sejour, puis les sejour si aucun sous_sejour
 */
function getPatientSejours(PDO $conn_base, int $idpatient): array
{
    // 1. D'abord essayer de trouver des sous_sejour actifs
    $stmt = $conn_base->prepare("
        SELECT 
            ss.idsous_sejour,
            ss.idsejour,
            ss.date_entree,
            ss.date_sortie,
            ss.observation as motif_admission,
            s.type_sejour,
            s.idsite,
            CONCAT('Séjour #', s.numero_sejour, ' - SS#', ss.numero_sous_sejour) as service_nom,
            'sous_sejour' as type_source
        FROM sous_sejour ss
        JOIN sejour s ON ss.idsejour = s.idsejour
        WHERE s.idpatient = :idp
        AND ss.statut = 'en_cours'
        AND s.statut = 'en_cours'
        ORDER BY ss.date_entree DESC
    ");
    
    $stmt->execute([':idp' => $idpatient]);
    $sous_sejours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si on a trouvé des sous_sejours, les retourner
    if (!empty($sous_sejours)) {
        return $sous_sejours;
    }
    
    // 2. Sinon, retourner les sejours actifs (sans sous_sejour)
    $stmt = $conn_base->prepare("
        SELECT 
            NULL as idsous_sejour,
            s.idsejour,
            s.date_entree,
            s.date_sortie,
            s.observation as motif_admission,
            s.type_sejour,
            s.idsite,
            CONCAT('Séjour #', s.numero_sejour, ' (', s.type_sejour, ')') as service_nom,
            'sejour_only' as type_source
        FROM sejour s
        WHERE s.idpatient = :idp
        AND s.statut = 'en_cours'
        ORDER BY s.date_entree DESC
    ");
    
    $stmt->execute([':idp' => $idpatient]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Créer un sous_sejour automatiquement si nécessaire
 */
function ensureSousSejourExists(PDO $conn_base, int $idsejour): int
{
    // Vérifier si un sous_sejour existe déjà
    $stmt = $conn_base->prepare("
        SELECT idsous_sejour 
        FROM sous_sejour 
        WHERE idsejour = :idsejour 
        AND statut = 'en_cours'
        LIMIT 1
    ");
    $stmt->execute([':idsejour' => $idsejour]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        return (int)$existing['idsous_sejour'];
    }
    
    // Récupérer les infos du séjour
    $stmt = $conn_base->prepare("SELECT * FROM sejour WHERE idsejour = :id");
    $stmt->execute([':id' => $idsejour]);
    $sejour = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sejour) {
        throw new Exception("Séjour introuvable");
    }
    
    // Générer un numéro de sous_sejour
    $stmt = $conn_base->query("
        SELECT COALESCE(MAX(CAST(SUBSTRING(numero_sous_sejour, 3) AS UNSIGNED)), 0) + 1 as next_num
        FROM sous_sejour
        WHERE numero_sous_sejour LIKE 'SS%'
    ");
    $next_num = $stmt->fetchColumn();
    $numero_sous_sejour = 'SS' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
    
    // Créer le sous_sejour
    $stmt = $conn_base->prepare("
        INSERT INTO sous_sejour (
            idsejour, idunite_med, numero_sous_sejour, 
            date_entree, statut, observation
        ) VALUES (
            :idsejour, 1, :numero,
            :date_entree, 'en_cours', 'Créé automatiquement pour prescription'
        )
    ");
    $stmt->execute([
        ':idsejour' => $idsejour,
        ':numero' => $numero_sous_sejour,
        ':date_entree' => $sejour['date_entree']
    ]);
    
    return (int)$conn_base->lastInsertId();
}

/**
 * Actes par categorie (labo ou imagerie).
 */
function getActesByCategorie(PDO $conn_base, string $type = 'labo'): array
{
    $cats = ($type === 'imagerie') ? CAT_IMAGERIE : CAT_LABO;
    $placeholders = implode(',', array_fill(0, count($cats), '?'));
    
    $stmt = $conn_base->prepare("
        SELECT a.idacte, a.code, a.libelle, a.prix_vente,
               a.idcategorie_acte, a.idspecialite,
               ca.nom as categorie_nom
        FROM acte a
        JOIN categorie_acte ca ON a.idcategorie_acte = ca.idcategorie_acte
        WHERE a.idcategorie_acte IN ($placeholders)
        AND a.actif = 1
        ORDER BY ca.nom, a.libelle
    ");
    $stmt->execute($cats);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recherche de produits pharmaceutiques
 */
function searchProdPharma(PDO $conn_base, string $term, int $limit = 30): array
{
    $term = trim($term);
    
    if (empty($term)) {
        return [];
    }
    
    $search = "%$term%";
    
    $sql = "SELECT 
                pp.idprodpharma, 
                pp.code as code_produit, 
                pp.libelle,
                pp.forme,
                pp.prix_vente_externe as prix_unitaire,
                pp.seuil_alerte,
                COALESCE(s.quantite_stock, 0) as stock_actuel
            FROM prodpharma pp
            LEFT JOIN (
                SELECT idprodpharma, SUM(quantite) as quantite_stock
                FROM stockpharma
                GROUP BY idprodpharma
            ) s ON pp.idprodpharma = s.idprodpharma
            WHERE pp.actif = 1
            AND (pp.libelle LIKE ? OR pp.code LIKE ?)
            ORDER BY 
                CASE 
                    WHEN pp.libelle LIKE ? THEN 1
                    WHEN pp.code LIKE ? THEN 2
                    ELSE 3
                END,
                pp.libelle ASC
            LIMIT ?";
    
    $stmt = $conn_base->prepare($sql);
    $stmt->bindValue(1, $search, PDO::PARAM_STR);
    $stmt->bindValue(2, $search, PDO::PARAM_STR);
    $stmt->bindValue(3, $search, PDO::PARAM_STR);
    $stmt->bindValue(4, $search, PDO::PARAM_STR);
    $stmt->bindValue(5, $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Creer une prescription d'acte (labo ou imagerie) dans csk_base
 * VERSION FLEXIBLE : Gère sejour avec ou sans sous_sejour
 */
function createActePrescription(
    PDO $conn_base, PDO $conn_services,
    int $idpatient, int $idsous_sejour, int $idacte,
    $observation, bool $urgence,
    int $prescripteur_id, string $type_service
): array {
    try {
        $conn_base->beginTransaction();
        
        // Gérer observation (string ou array pour labo)
        $observation_text = '';
        $type_prelevement = 'sang_veineux';
        $tube_type = 'vacutainer';
        $couleur_tube = 'violet';
        $volume_ml = null;
        $anticoagulant = null;
        $site_prelevement = null;
        $conditions_particulieres = null;
        
        if (is_array($observation)) {
            $observation_text = $observation['observation'] ?? '';
            $type_prelevement = $observation['type_prelevement'] ?? 'sang_veineux';
            $tube_type = $observation['tube_type'] ?? 'vacutainer';
            $couleur_tube = $observation['couleur_tube'] ?? 'violet';
            $volume_ml = isset($observation['volume_ml']) && !empty($observation['volume_ml']) ? (float)$observation['volume_ml'] : null;
            $anticoagulant = $observation['anticoagulant'] ?? null;
            $site_prelevement = $observation['site_prelevement'] ?? null;
            $conditions_particulieres = $observation['conditions_particulieres'] ?? null;
        } else {
            $observation_text = $observation;
        }
        
        // 1. Charger l'acte
        $stmt = $conn_base->prepare("SELECT * FROM acte WHERE idacte = :id");
        $stmt->execute([':id' => $idacte]);
        $acte = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$acte) {
            $conn_base->rollBack();
            return ['success' => false, 'message' => 'Acte introuvable.', 'code' => ''];
        }
        
        // 2. Récupérer les infos du sous_sejour
        $stmt = $conn_base->prepare("
            SELECT ss.*, s.idpatient, s.idsite, p.idsociete 
            FROM sous_sejour ss
            JOIN sejour s ON ss.idsejour = s.idsejour
            JOIN patient p ON s.idpatient = p.idpatient
            WHERE ss.idsous_sejour = :id
        ");
        $stmt->execute([':id' => $idsous_sejour]);
        $sejour_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sejour_info) {
            $conn_base->rollBack();
            return ['success' => false, 'message' => 'Séjour introuvable.', 'code' => ''];
        }
        
        $idsite = $sejour_info['idsite'] ?? 1;
        $idsociete = $sejour_info['idsociete'] ?? null;
        $prix_unitaire = $acte['prix_vente'] ?? 0;
        $quantite = 1;
        $montant_total = $prix_unitaire * $quantite;
        
        // 3. INSERT dans actes_presc (csk_base)
        $stmt = $conn_base->prepare("
            INSERT INTO actes_presc (
                idsous_sejour, idacte, idsite, idsociete, idspecialite,
                quantite, prix_unitaire, montant_total,
                date_prescription, prescripteur, 
                statut_validation, mode_paiement,
                statut_execution, urgent, observation,
                source_prescription
            ) VALUES (
                :idsous_sejour, :idacte, :idsite, :idsociete, :idspecialite,
                :quantite, :prix_unitaire, :montant_total,
                NOW(), :prescripteur,
                'rien', 'rien',
                'en_attente', :urgent, :observation,
                'csk_services'
            )
        ");
        
        $stmt->execute([
            ':idsous_sejour' => $idsous_sejour,
            ':idacte'        => $idacte,
            ':idsite'        => $idsite,
            ':idsociete'     => $idsociete,
            ':idspecialite'  => $acte['idspecialite'],
            ':quantite'      => $quantite,
            ':prix_unitaire' => $prix_unitaire,
            ':montant_total' => $montant_total,
            ':prescripteur'  => $prescripteur_id,
            ':urgent'        => $urgence ? 1 : 0,
            ':observation'   => $observation_text ?: null,
        ]);
        
        $idactes_presc = (int) $conn_base->lastInsertId();
        
        $conn_base->commit();
        
        // 4. Auto-creation entree workflow dans csk_services
        $code_workflow = '';
        if ($type_service === 'labo') {
            $code_workflow = createLaboEchantillon(
                $conn_services, $idpatient, $idactes_presc, $urgence, $prescripteur_id,
                $type_prelevement, $tube_type, $couleur_tube,
                $volume_ml, $anticoagulant, $site_prelevement, $conditions_particulieres
            );
        } elseif ($type_service === 'imagerie') {
            $code_workflow = createImagerieExamen($conn_services, $idpatient, $idactes_presc, $urgence, $prescripteur_id, $acte);
        }
        
        return [
            'success' => true, 
            'message' => 'Prescription créée avec succès.', 
            'code' => $code_workflow,
            'idactes_presc' => $idactes_presc,
        ];
        
    } catch (Exception $e) {
        if ($conn_base->inTransaction()) $conn_base->rollBack();
        error_log("[CSK Services][Prescriptions] Erreur createActePrescription: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erreur: ' . $e->getMessage(), 'code' => ''];
    }
}

/**
 * Creer une prescription pharma dans csk_base
 * VERSION FLEXIBLE
 */
function createPharmaPrescription(
    PDO $conn_base, PDO $conn_services,
    int $idpatient, int $idsous_sejour, int $idprodpharma,
    int $quantite, string $posologie, string $observation,
    bool $urgence, int $prescripteur_id
): array {
    try {
        $conn_base->beginTransaction();
        
        // 1. Charger le produit
        $stmt = $conn_base->prepare("SELECT * FROM prodpharma WHERE idprodpharma = :id");
        $stmt->execute([':id' => $idprodpharma]);
        $produit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$produit) {
            $conn_base->rollBack();
            return ['success' => false, 'message' => 'Produit introuvable.', 'code' => ''];
        }
        
        // 2. Récupérer les infos du sous_sejour OU créer si nécessaire
        $stmt = $conn_base->prepare("
            SELECT ss.*, s.idpatient, p.idsociete 
            FROM sous_sejour ss
            JOIN sejour s ON ss.idsejour = s.idsejour
            JOIN patient p ON s.idpatient = p.idpatient
            WHERE ss.idsous_sejour = :id
        ");
        $stmt->execute([':id' => $idsous_sejour]);
        $sejour_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Si pas de sous_sejour trouvé, peut-être qu'on a reçu un idsejour
        if (!$sejour_info) {
            try {
                $real_idsous_sejour = ensureSousSejourExists($conn_base, $idsous_sejour);
                $stmt->execute([':id' => $real_idsous_sejour]);
                $sejour_info = $stmt->fetch(PDO::FETCH_ASSOC);
                $idsous_sejour = $real_idsous_sejour;
            } catch (Exception $e) {
                $conn_base->rollBack();
                return ['success' => false, 'message' => 'Séjour introuvable: ' . $e->getMessage(), 'code' => ''];
            }
        }
        
        if (!$sejour_info) {
            $conn_base->rollBack();
            return ['success' => false, 'message' => 'Séjour introuvable.', 'code' => ''];
        }
        
        $idsociete = $sejour_info['idsociete'] ?? null;
        $prix_unitaire = $produit['prix_vente_externe'] ?? 0;
        $montant_total = $prix_unitaire * $quantite;
        
        // 3. INSERT dans pharma_presc (csk_base)
        $stmt = $conn_base->prepare("
            INSERT INTO pharma_presc (
                idsous_sejour, idprodpharma, idsociete,
                quantite, posologie, 
                prix_unitaire, montant_total,
                date_prescription, prescripteur,
                statut_validation, mode_paiement,
                statut_execution, observation, urgent,
                source_prescription
            ) VALUES (
                :idsous_sejour, :idprodpharma, :idsociete,
                :quantite, :posologie,
                :prix_unitaire, :montant_total,
                NOW(), :prescripteur,
                'rien', 'rien',
                'en_attente', :observation, :urgent,
                'csk_services'
            )
        ");
        
        $stmt->execute([
            ':idsous_sejour' => $idsous_sejour,
            ':idprodpharma'  => $idprodpharma,
            ':idsociete'     => $idsociete,
            ':quantite'      => $quantite,
            ':posologie'     => $posologie ?: null,
            ':prix_unitaire' => $prix_unitaire,
            ':montant_total' => $montant_total,
            ':prescripteur'  => $prescripteur_id,
            ':observation'   => $observation ?: null,
            ':urgent'        => $urgence ? 1 : 0,
        ]);
        
        $idpharma_presc = (int) $conn_base->lastInsertId();
        
        $conn_base->commit();
        
        // 4. Auto-creation preparation dans csk_services
        $code_workflow = createPharmaPreparation(
            $conn_services, $idpatient, $idpharma_presc, 
            $idprodpharma, $quantite, $urgence, $prescripteur_id
        );
        
        return [
            'success' => true,
            'message' => 'Prescription pharma créée avec succès.',
            'code' => $code_workflow,
            'idpharma_presc' => $idpharma_presc,
        ];
        
    } catch (Exception $e) {
        if ($conn_base->inTransaction()) $conn_base->rollBack();
        error_log("[CSK Services][Prescriptions] Erreur createPharmaPrescription: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erreur: ' . $e->getMessage(), 'code' => ''];
    }
}

// =============================================
// AUTO-CREATION DES ENTREES WORKFLOW
// =============================================

function createLaboEchantillon(
    PDO $conn, int $idpatient, int $idactes_presc, 
    bool $urgence, int $prescripteur_id,
    string $type_prelevement = 'sang_veineux',
    string $tube_type = 'vacutainer',
    string $couleur_tube = 'violet',
    ?float $volume_ml = null,
    ?string $anticoagulant = null,
    ?string $site_prelevement = null,
    ?string $conditions_particulieres = null
): string {
    // Générer un code unique
    $today = date('Ymd');
    $prefix = "LAB-{$today}-";
    
    // Trouver le dernier numéro pour aujourd'hui
    $stmt = $conn->prepare("
        SELECT code_echantillon 
        FROM labo_echantillons 
        WHERE code_echantillon LIKE :prefix 
        ORDER BY code_echantillon DESC 
        LIMIT 1
    ");
    $stmt->execute([':prefix' => $prefix . '%']);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        // Extraire le numéro (ex: LAB-20250210-0015 -> 15)
        $lastNum = (int)substr($last, -4);
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    // Formater avec 4 chiffres (0001, 0002, etc.)
    $code = $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
    
    // Déterminer la priorité
    $priorite = $urgence ? 'urgente' : 'normale';
    
    // Déterminer l'anticoagulant en fonction du type de tube
    if ($anticoagulant === null) {
        $anticoagulant = match($couleur_tube) {
            'violet' => 'EDTA',
            'bleu' => 'Citrate',
            'vert' => 'Héparine',
            'gris' => 'Fluorure/Oxalate',
            'noir' => 'Citrate (VS)',
            default => null
        };
    }
    
    $stmt = $conn->prepare("
        INSERT INTO labo_echantillons (
            code_echantillon,
            idactes_presc,
            idpatient,
            type_prelevement,
            tube_type,
            couleur_tube,
            volume_ml,
            anticoagulant,
            site_prelevement,
            conditions_particulieres,
            statut,
            urgence,
            priorite,
            delai_theorique_min,
            created_at
        ) VALUES (
            :code,
            :idap,
            :idp,
            :type_prel,
            :tube,
            :couleur,
            :volume,
            :anticoagulant,
            :site,
            :conditions,
            'attente_prelevement',
            :urg,
            :priorite,
            120,
            NOW()
        )
    ");
    
    $stmt->execute([
        ':code'          => $code,
        ':idap'          => $idactes_presc,
        ':idp'           => $idpatient,
        ':type_prel'     => $type_prelevement,
        ':tube'          => $tube_type,
        ':couleur'       => $couleur_tube,
        ':volume'        => $volume_ml,
        ':anticoagulant' => $anticoagulant,
        ':site'          => $site_prelevement,
        ':conditions'    => $conditions_particulieres,
        ':urg'           => $urgence ? 1 : 0,
        ':priorite'      => $priorite,
    ]);
    
    $idech = (int)$conn->lastInsertId();
    
    // Historique du workflow
    $conn->prepare("
        INSERT INTO labo_workflow_history (
            idechantillon,
            action,
            ancien_statut,
            nouveau_statut,
            idutilisateur,
            observation,
            created_at
        ) VALUES (
            :id,
            'Création par prescription (services)',
            NULL,
            'attente_prelevement',
            :uid,
            :obs,
            NOW()
        )
    ")->execute([
        ':id' => $idech, 
        ':uid' => $prescripteur_id,
        ':obs' => 'Prescription créée depuis l\'application Services'
    ]);
    
    return $code;
}

function createImagerieExamen(
    PDO $conn, int $idpatient, int $idactes_presc, 
    bool $urgence, int $prescripteur_id, array $acte
): string {
    $code = 'IMG-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Determiner le type d'examen depuis la categorie de l'acte
    $type_examen = 'radiographie'; // defaut
    $cat_id = $acte['idcategorie_acte'] ?? 0;
    if (in_array($cat_id, [10])) $type_examen = 'echographie';
    if (in_array($cat_id, [22])) $type_examen = 'scanner';
    
    $stmt = $conn->prepare("
        INSERT INTO imagerie_examens
            (code_examen, idpatient, idactes_presc, type_examen, statut,
             priorite, date_examen, source)
        VALUES (:code, :idp, :idap, :type, 'demande_recue', :prio, NOW(), 'prescription_services')
    ");
    $stmt->execute([
        ':code' => $code,
        ':idp'  => $idpatient,
        ':idap' => $idactes_presc,
        ':type' => $type_examen,
        ':prio' => $urgence ? 'urgente' : 'normale',
    ]);
    
    $idex = (int)$conn->lastInsertId();
    
    $conn->prepare("
        INSERT INTO imagerie_workflow_history 
            (idexamen, action, ancien_statut, nouveau_statut, idutilisateur, observation)
        VALUES (:id, 'Creation par prescription (services)', NULL, 'demande_recue', :uid, 
                'Prescription créée depuis app services')
    ")->execute([':id' => $idex, ':uid' => $prescripteur_id]);
    
    return $code;
}

function createPharmaPreparation(
    PDO $conn, int $idpatient, int $idpharma_presc, 
    int $idprodpharma, int $quantite, bool $urgence, int $prescripteur_id
): string {
    $code = 'PH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $stmt = $conn->prepare("
        INSERT INTO pharmacie_preparations
            (code_preparation, idpatient, idpharma_presc, idprodpharma, quantite,
             statut, urgence, source)
        VALUES (:code, :idp, :idpp, :idprod, :qty, 'en_attente', :urg, 'prescription_services')
    ");
    $stmt->execute([
        ':code'   => $code,
        ':idp'    => $idpatient,
        ':idpp'   => $idpharma_presc,
        ':idprod' => $idprodpharma,
        ':qty'    => $quantite,
        ':urg'    => $urgence ? 1 : 0,
    ]);
    
    $idprep = (int)$conn->lastInsertId();
    
    $conn->prepare("
        INSERT INTO pharmacie_workflow_history 
            (idpreparation, action, ancien_statut, nouveau_statut, idutilisateur, observation)
        VALUES (:id, 'Creation par prescription (services)', NULL, 'en_attente', :uid, 
                'Prescription créée depuis app services')
    ")->execute([':id' => $idprep, ':uid' => $prescripteur_id]);
    
    return $code;
}