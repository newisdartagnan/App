<?php
class Consultation {
    private $conn;
    private $table = 'consultations';

    public function __construct($db) {
        $this->conn = $db;
    }

    public function createConsultation($data) {
        try {
            $this->conn->beginTransaction();
            
            // 1. Créer la consultation
            $query = "INSERT INTO consultations 
                    (idsous_sejour, idpatient, idutilisateur, motif_consultation, anamnese, 
                    examen_clinique, hypothese_diagnostique, conduite_tenir, 
                    date_consultation, statut)
                    VALUES 
                    (:idsous_sejour, :idpatient, :idutilisateur, :motif, :anamnese, 
                    :examen, :diagnostic, :conduite, NOW(), 'en_cours')";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->bindParam(':idsous_sejour', $data['idsous_sejour']);
            $stmt->bindParam(':idpatient', $data['idpatient']);
            $stmt->bindParam(':idutilisateur', $data['idutilisateur']);
            $stmt->bindParam(':motif', $data['motif_consultation']);
            $stmt->bindParam(':anamnese', $data['anamnese']);
            $stmt->bindParam(':examen', $data['examen_clinique']);
            $stmt->bindParam(':diagnostic', $data['hypothese_diagnostique']);
            $stmt->bindParam(':conduite', $data['conduite_tenir']);
            
            if (!$stmt->execute()) {
                throw new Exception("Erreur lors de la création de la consultation");
            }
            
            $idconsultation = $this->conn->lastInsertId();
            
            // 2. Toujours créer un diagnostic à partir de l'hypothèse diagnostique
            $diagnostic_data = [
                'idpatient' => $data['idpatient'],
                'idsous_sejour' => $data['idsous_sejour'],
                'libelle_diagnostic' => $data['hypothese_diagnostique'],
                'type_diagnostic' => 'présomptif',
                'confirme' => 0
            ];
            
            // Si des données de diagnostic supplémentaires sont fournies
            if (isset($data['diagnostic_data'])) {
                $diagnostic_data = array_merge($diagnostic_data, $data['diagnostic_data']);
            }
            
            // Vérifiez d'abord si la table existe
            try {
                $this->addDiagnostic($diagnostic_data);
            } catch (Exception $e) {
                // Log l'erreur mais ne bloquez pas
                error_log("Note: diagnostic_patient table might not exist: " . $e->getMessage());
            }
            
            $this->conn->commit();
            return $idconsultation;
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Erreur createConsultation: " . $e->getMessage());
            return false;
        }
    }

    public function getBySejourId($idsejour) {
        $query = "SELECT c.*, 
                         u.nom as medecin_nom, u.prenom as medecin_prenom,
                         ss.numero_sous_sejour
                  FROM consultations c
                  JOIN sous_sejour ss ON c.idsous_sejour = ss.idsous_sejour
                  JOIN utilisateur u ON c.idutilisateur = u.idutilisateur
                  WHERE ss.idsejour = :idsejour
                  ORDER BY c.date_consultation DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsejour', $idsejour);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addSigneVital($data) {
        $query = "INSERT INTO parametresvitaux 
                  (idpatient, idsous_sejour, idtypeparamvitaux, valeur, date_mesure, idutilisateur)
                  VALUES 
                  (:idpatient, :idsous_sejour, :idtype, :valeur, NOW(), :idutilisateur)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':idpatient', $data['idpatient']);
        $stmt->bindParam(':idsous_sejour', $data['idsous_sejour']);
        $stmt->bindParam(':idtype', $data['idtypeparamvitaux']);
        $stmt->bindParam(':valeur', $data['valeur']);
        $stmt->bindParam(':idutilisateur', $data['idutilisateur']);
        
        return $stmt->execute();
    }

    public function getSignesVitaux($idsous_sejour) {
        $query = "SELECT pv.*, tpv.nom as type_parametre
                  FROM parametresvitaux pv
                  JOIN typeparamvitaux tpv ON pv.idtypeparamvitaux = tpv.idtypeparamvitaux
                  WHERE pv.idsous_sejour = :idsous_sejour
                  ORDER BY pv.date_mesure DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsous_sejour', $idsous_sejour);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addDiagnostic($data) {
        try {
            // D'abord, chercher ou créer un diagnostic "LIBRE" générique
            $query_libre = "SELECT iddiagnostic FROM diagnostic WHERE code_cim = 'LIBRE'";
            $stmt_libre = $this->conn->prepare($query_libre);
            $stmt_libre->execute();
            $libre = $stmt_libre->fetch(PDO::FETCH_ASSOC);
            
            if (!$libre) {
                // Créer le diagnostic générique "LIBRE"
                $query_create_libre = "INSERT INTO diagnostic (code_cim, libelle, actif) 
                                    VALUES ('LIBRE', 'Diagnostic libre (non codé)', 1)";
                $stmt_create_libre = $this->conn->prepare($query_create_libre);
                $stmt_create_libre->execute();
                $id_libre = $this->conn->lastInsertId();
            } else {
                $id_libre = $libre['iddiagnostic'];
            }
            
            // Préparer les données
            $iddiagnostic = $id_libre; // Par défaut: diagnostic libre
            $libelle_diagnostic = $data['libelle_diagnostic'];
            
            // Vérifier si un code CIM spécifique a été fourni
            if (isset($data['diagnostic_code']) && !empty($data['diagnostic_code']) && $data['diagnostic_code'] !== 'LIBRE') {
                // Chercher par code CIM exact
                $query_check = "SELECT iddiagnostic FROM diagnostic WHERE code_cim = :code_cim";
                $stmt_check = $this->conn->prepare($query_check);
                $stmt_check->bindParam(':code_cim', $data['diagnostic_code']);
                $stmt_check->execute();
                
                $diagnostic = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($diagnostic && isset($diagnostic['iddiagnostic'])) {
                    // Diagnostic trouvé, utiliser son ID
                    $iddiagnostic = $diagnostic['iddiagnostic'];
                    
                    // Récupérer le libellé officiel
                    $query_libelle = "SELECT libelle FROM diagnostic WHERE iddiagnostic = :id";
                    $stmt_libelle = $this->conn->prepare($query_libelle);
                    $stmt_libelle->bindParam(':id', $iddiagnostic);
                    $stmt_libelle->execute();
                    $libelle_data = $stmt_libelle->fetch(PDO::FETCH_ASSOC);
                    
                    if ($libelle_data) {
                        $libelle_diagnostic = $libelle_data['libelle'];
                    }
                } else {
                    // Créer un nouveau diagnostic avec le code fourni
                    $query_new = "INSERT INTO diagnostic (code_cim, libelle, actif) 
                                VALUES (:code_cim, :libelle, 1)";
                    $stmt_new = $this->conn->prepare($query_new);
                    $stmt_new->bindParam(':code_cim', $data['diagnostic_code']);
                    $stmt_new->bindParam(':libelle', $data['libelle_diagnostic']);
                    
                    if ($stmt_new->execute()) {
                        $iddiagnostic = $this->conn->lastInsertId();
                    }
                }
            }
            
            // Insérer dans diagnostic_patient
            $query = "INSERT INTO diagnostic_patient 
                    (idpatient, idsous_sejour, iddiagnostic, libelle_diagnostic, 
                    type_diagnostic, date_diagnostic, confirmé)
                    VALUES 
                    (:idpatient, :idsous_sejour, :iddiagnostic, :libelle, 
                    :type, NOW(), :confirme)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':idpatient', $data['idpatient']);
            $stmt->bindParam(':idsous_sejour', $data['idsous_sejour']);
            $stmt->bindParam(':iddiagnostic', $iddiagnostic);
            $stmt->bindParam(':libelle', $libelle_diagnostic);
            $stmt->bindParam(':type', $data['type_diagnostic']);
            $stmt->bindParam(':confirme', $data['confirme']);
            
            return $stmt->execute();
            
        } catch (Exception $e) {
            error_log("Erreur addDiagnostic: " . $e->getMessage());
            return false;
        }
    }
    
    public function getPatientsEnAttente($idsite) {
        $query = "SELECT DISTINCT
                        p.idpatient,
                        p.numero_dossier,
                        p.nom,
                        p.prenom,
                        p.date_naissance,
                        s.idsejour,
                        s.type_sejour,
                        ss.idsous_sejour,
                        um.nom as unite_medicale,
                        s.date_entree,
                        ap.urgent,
                        COUNT(DISTINCT ap.idactes_presc) as nb_actes_valides
                FROM patient p
                JOIN sejour s ON p.idpatient = s.idpatient
                JOIN sous_sejour ss ON s.idsejour = ss.idsejour
                JOIN unite_med um ON ss.idunite_med = um.idunite_med
                JOIN actes_presc ap ON ss.idsous_sejour = ap.idsous_sejour
                LEFT JOIN consultations c ON ss.idsous_sejour = c.idsous_sejour
                WHERE s.idsite = :idsite
                AND s.statut = 'en_cours'
                AND ap.statut_validation = 'valide'
                AND ap.statut_execution = 'en_attente'
                AND c.idconsultation IS NULL
                GROUP BY p.idpatient, p.numero_dossier, p.nom, p.prenom, 
                        p.date_naissance, s.idsejour, s.type_sejour, 
                        ss.idsous_sejour, um.nom, s.date_entree, ap.urgent
                ORDER BY ap.urgent DESC, s.date_entree ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsite', $idsite);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}