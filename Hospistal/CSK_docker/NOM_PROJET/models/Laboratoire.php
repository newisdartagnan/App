<?php
class Laboratoire {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Dans Laboratoire.php, Imagerie.php, Pharmacie.php
    public function getAnalysesEnAttente($idsite) {
        $query = "SELECT ap.*, 
                        a.libelle as acte_libelle,
                        a.code as acte_code,
                        p.nom as patient_nom,
                        p.prenom as patient_prenom,
                        p.numero_dossier,
                        p.date_naissance,
                        p.sexe,
                        u.nom as prescripteur_nom
                FROM actes_presc ap
                JOIN acte a ON ap.idacte = a.idacte
                JOIN sous_sejour ss ON ap.idsous_sejour = ss.idsous_sejour
                JOIN sejour s ON ss.idsejour = s.idsejour
                JOIN patient p ON s.idpatient = p.idpatient
                LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                WHERE s.idsite = :idsite
                AND a.idcategorie_acte = 6
                AND ap.statut_validation = 'valide'
                AND ap.statut_execution = 'en_attente'
                ORDER BY ap.urgent DESC, ap.date_prescription ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsite', $idsite);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function createEchantillon($data) {
        $query = "INSERT INTO echantillon 
                  (idactes_presc, code_echantillon, type_echantillon, date_prelevement, 
                   preleve_par, statut)
                  VALUES 
                  (:idactes_presc, :code, :type, NOW(), :preleve_par, 'reçu')";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':idactes_presc', $data['idactes_presc']);
        $stmt->bindParam(':code', $data['code_echantillon']);
        $stmt->bindParam(':type', $data['type_echantillon']);
        $stmt->bindParam(':preleve_par', $data['preleve_par']);
        
        return $stmt->execute();
    }

    public function saveResultat($data) {
        $query = "INSERT INTO résultatslabo 
                  (idactes_presc, idmachinelabo, resultat, valeur_normale, 
                   interpretation, date_analyse, analyse_par)
                  VALUES 
                  (:idactes_presc, :idmachine, :resultat, :valeur_normale, 
                   :interpretation, NOW(), :analyse_par)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':idactes_presc', $data['idactes_presc']);
        $stmt->bindParam(':idmachine', $data['idmachinelabo']);
        $stmt->bindParam(':resultat', $data['resultat']);
        $stmt->bindParam(':valeur_normale', $data['valeur_normale']);
        $stmt->bindParam(':interpretation', $data['interpretation']);
        $stmt->bindParam(':analyse_par', $data['analyse_par']);
        
        if ($stmt->execute()) {
            // Marquer l'acte comme terminé
            $query_update = "UPDATE actes_presc 
                           SET statut_execution = 'termine',
                               date_execution = NOW()
                           WHERE idactes_presc = :id";
            
            $stmt_update = $this->conn->prepare($query_update);
            $stmt_update->bindParam(':id', $data['idactes_presc']);
            $stmt_update->execute();
            
            return true;
        }
        return false;
    }

    public function getResultats($idactes_presc) {
        $query = "SELECT r.*, m.nom as machine_nom, u.nom as technicien_nom
                  FROM résultatslabo r
                  LEFT JOIN machineslabo m ON r.idmachinelabo = m.idmachinelabo
                  LEFT JOIN utilisateur u ON r.analyse_par = u.idutilisateur
                  WHERE r.idactes_presc = :id
                  ORDER BY r.date_analyse DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $idactes_presc);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}