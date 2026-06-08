<?php
class Imagerie {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ==================== EXAMENS ====================
    
    public function getExamensEnAttente($idsite) {
        $query = "SELECT ap.*, 
                         a.libelle as acte_libelle,
                         a.code as acte_code,
                         p.nom as patient_nom,
                         p.prenom as patient_prenom,
                         p.numero_dossier,
                         p.date_naissance,
                         p.sexe,
                         s.type_sejour,
                         u.nom as prescripteur_nom,
                         u.prenom as prescripteur_prenom
                  FROM actes_presc ap
                  JOIN acte a ON ap.idacte = a.idacte
                  JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                  JOIN sejour s ON ss.idsejour = s.idsejour
                  JOIN patient p ON s.idpatient = p.idpatient
                  LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                  WHERE s.idsite = :idsite
                  AND a.idcategorie_acte = 5
                  AND ap.statut_validation = 'valide'
                  --> AND ap.statut_execution IN ('en_attente', 'en_cours') -->
                  AND ap.statut_execution = 'en_attente'       
                  ORDER BY ap.urgent DESC, ap.date_prescription ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsite', $idsite);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getExamenById($idactes_presc, $idsite = null) {
        $query = "SELECT ap.*, 
                         a.libelle as acte_libelle,
                         a.code as acte_code,
                         p.nom as patient_nom,
                         p.prenom as patient_prenom,
                         p.numero_dossier,
                         p.date_naissance,
                         p.sexe,
                         s.type_sejour,
                         s.idsite,
                         u.nom as prescripteur_nom,
                         u.prenom as prescripteur_prenom,
                         img.*,
                         radio.nom as radiologue_nom,
                         radio.prenom as radiologue_prenom
                  FROM actes_presc ap
                  JOIN acte a ON ap.idacte = a.idacte
                  JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                  JOIN sejour s ON ss.idsejour = s.idsejour
                  JOIN patient p ON s.idpatient = p.idpatient
                  LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                  LEFT JOIN image_i img ON ap.idactes_presc = img.idactes_presc
                  LEFT JOIN utilisateur radio ON img.radiologue = radio.idutilisateur
                  WHERE ap.idactes_presc = :id";
        
        if ($idsite) {
            $query .= " AND s.idsite = :idsite";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $idactes_presc);
        
        if ($idsite) {
            $stmt->bindParam(':idsite', $idsite);
        }
        
        $stmt->execute();
        return $stmt->fetch();
    }

    // ==================== COMPTE RENDU ====================
    
    public function saveCompteRendu($data) {
        try {
            $this->conn->beginTransaction();
            
            // Vérifier si un compte rendu existe déjà
            $check_query = "SELECT idimage FROM image_i WHERE idactes_presc = :id";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':id', $data['idactes_presc']);
            $check_stmt->execute();
            $existing = $check_stmt->fetch();
            
            if ($existing) {
                // Mettre à jour
                $query = "UPDATE image_i SET 
                    technique_utilisee = :technique,
                    description_images = :description,
                    conclusion = :conclusion,
                    recommandations = :recommandations,
                    radiologue = :radiologue,
                    fichier_externe = :fichier_externe,
                    date_examen = NOW()
                    WHERE idactes_presc = :idactes_presc";
            } else {
                // Insérer nouveau
                $query = "INSERT INTO image_i 
                    (idactes_presc, technique_utilisee, description_images, 
                    conclusion, recommandations, date_examen, radiologue, fichier_externe)
                    VALUES 
                    (:idactes_presc, :technique, :description, 
                    :conclusion, :recommandations, NOW(), :radiologue, :fichier_externe)";
            }
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->execute([
                ':idactes_presc' => $data['idactes_presc'],
                ':technique' => $data['technique'],
                ':description' => $data['description'],
                ':conclusion' => $data['conclusion'],
                ':recommandations' => $data['recommandations'] ?? null,
                ':radiologue' => $data['radiologue'],
                ':fichier_externe' => $data['fichier_externe'] ?? null
            ]);
            
            // Marquer comme terminé
            $query_update = "UPDATE actes_presc 
                    SET statut_execution = 'termine',
                        date_execution = NOW()
                    WHERE idactes_presc = :id";
            
            $stmt_update = $this->conn->prepare($query_update);
            $stmt_update->execute([':id' => $data['idactes_presc']]);
            
            $this->conn->commit();
            
            // Notifier le prescripteur
            $this->notifyPrescripteur($data['idactes_presc']);
            
            return true;
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    // ==================== NOTIFICATIONS ====================
    
    public function notifyPrescripteur($idactes_presc) {
        try {
            // Récupérer les informations de notification
            $query = "SELECT ap.prescripteur, 
                             p.nom as patient_nom, 
                             p.prenom as patient_prenom,
                             a.libelle as acte_libelle
                      FROM actes_presc ap
                      JOIN acte a ON ap.idacte = a.idacte
                      JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                      JOIN sejour s ON ss.idsejour = s.idsejour
                      JOIN patient p ON s.idpatient = p.idpatient
                      WHERE ap.idactes_presc = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $idactes_presc);
            $stmt->execute();
            $data = $stmt->fetch();
            
            if ($data && $data['prescripteur']) {
                // Créer une notification
                $notification_query = "INSERT INTO notifications 
                    (idutilisateur, type, titre, message, lien, date_creation)
                    VALUES (:idutilisateur, 'imagerie', :titre, :message, :lien, NOW())";
                
                $notification_stmt = $this->conn->prepare($notification_query);
                
                return $notification_stmt->execute([
                    ':idutilisateur' => $data['prescripteur'],
                    ':titre' => 'Résultat d\'imagerie disponible',
                    ':message' => 'Le résultat de l\'examen ' . $data['acte_libelle'] . 
                                 ' pour ' . $data['patient_nom'] . ' ' . $data['patient_prenom'] . 
                                 ' est maintenant disponible.',
                    ':lien' => '../imagerie/voir-resultat.php?id=' . $idactes_presc
                ]);
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Erreur notifyPrescripteur: " . $e->getMessage());
            return false;
        }
    }

    // ==================== STATISTIQUES ====================
    
    public function getStatistics($idsite, $start_date = null, $end_date = null) {
        try {
            $where = "WHERE s.idsite = :idsite 
                     AND a.idcategorie_acte = 5 
                     AND ap.statut_validation = 'valide'";
            
            $params = [':idsite' => $idsite];
            
            if ($start_date && $end_date) {
                $where .= " AND DATE(ap.date_prescription) BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $start_date;
                $params[':end_date'] = $end_date;
            }
            
            $query = "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN ap.statut_execution = 'en_attente' THEN 1 END) as en_attente,
                COUNT(CASE WHEN ap.statut_execution = 'en_cours' THEN 1 END) as en_cours,
                COUNT(CASE WHEN ap.statut_execution = 'termine' THEN 1 END) as termines,
                COUNT(CASE WHEN ap.urgent = 1 AND ap.statut_execution != 'termine' THEN 1 END) as urgents,
                COUNT(CASE WHEN ap.type_externe = 'externe' THEN 1 END) as externes,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, ap.date_prescription, img.date_examen)), 1) as delai_moyen
                FROM actes_presc ap
                JOIN acte a ON ap.idacte = a.idacte
                JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                JOIN sejour s ON ss.idsejour = s.idsejour
                LEFT JOIN image_i img ON ap.idactes_presc = img.idactes_presc
                $where";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            error_log("Erreur getStatistics: " . $e->getMessage());
            return false;
        }
    }

    // ==================== UPLOAD IMAGES ====================
    
    public function uploadImage($idimage, $filename, $filepath) {
        $query = "UPDATE image_i 
                  SET image_filename = :filename,
                      image_path = :filepath
                  WHERE idimage = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':filename', $filename);
        $stmt->bindParam(':filepath', $filepath);
        $stmt->bindParam(':id', $idimage);
        
        return $stmt->execute();
    }

    // ==================== GETTERS DIVERS ====================
    
    public function getExamensByStatut($idsite, $statut) {
        $query = "SELECT ap.*, 
                         a.libelle as acte_libelle,
                         a.code as acte_code,
                         p.nom as patient_nom,
                         p.prenom as patient_prenom,
                         p.numero_dossier,
                         p.date_naissance,
                         p.sexe,
                         s.type_sejour,
                         u.nom as prescripteur_nom,
                         u.prenom as prescripteur_prenom,
                         img.date_examen,
                         radio.nom as radiologue_nom
                  FROM actes_presc ap
                  JOIN acte a ON ap.idacte = a.idacte
                  JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                  JOIN sejour s ON ss.idsejour = s.idsejour
                  JOIN patient p ON s.idpatient = p.idpatient
                  LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                  LEFT JOIN image_i img ON ap.idactes_presc = img.idactes_presc
                  LEFT JOIN utilisateur radio ON img.radiologue = radio.idutilisateur
                  WHERE s.idsite = :idsite
                  AND a.idcategorie_acte = 5
                  AND ap.statut_validation = 'valide'
                  AND ap.statut_execution = :statut
                  ORDER BY ap.urgent DESC, ap.date_prescription ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsite', $idsite);
        $stmt->bindParam(':statut', $statut);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getExamensUrgents($idsite) {
        return $this->getExamensByStatutWithFilter($idsite, "AND ap.urgent = 1 AND ap.statut_execution != 'termine'");
    }

    public function getExamensTermines($idsite) {
        return $this->getExamensByStatut($idsite, 'termine');
    }

    private function getExamensByStatutWithFilter($idsite, $additional_filter = "") {
        $query = "SELECT ap.*, 
                         a.libelle as acte_libelle,
                         a.code as acte_code,
                         p.nom as patient_nom,
                         p.prenom as patient_prenom,
                         p.numero_dossier,
                         p.date_naissance,
                         p.sexe,
                         s.type_sejour,
                         u.nom as prescripteur_nom,
                         u.prenom as prescripteur_prenom
                  FROM actes_presc ap
                  JOIN acte a ON ap.idacte = a.idacte
                  JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                  JOIN sejour s ON ss.idsejour = s.idsejour
                  JOIN patient p ON s.idpatient = p.idpatient
                  LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                  WHERE s.idsite = :idsite
                  AND a.idcategorie_acte = 5
                  AND ap.statut_validation = 'valide'
                  $additional_filter
                  ORDER BY ap.urgent DESC, ap.date_prescription ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsite', $idsite);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
?>