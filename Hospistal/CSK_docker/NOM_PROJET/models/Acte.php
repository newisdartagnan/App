<?php
class Acte {
    private $conn;
    private $table = 'actes_presc';
    private $prescriptionService;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getActesBySpecialite($idspecialite, $idsous_specialite = null) {
        $query = "SELECT a.*, ca.nom as categorie
                  FROM acte a
                  LEFT JOIN categorie_acte ca ON a.idcategorie_acte = ca.idcategorie_acte
                  WHERE a.actif = 1";
        
        if ($idsous_specialite) {
            $query .= " AND a.idsous_specialite = :idsous_specialite";
        }
        
        $query .= " ORDER BY a.libelle";

        $stmt = $this->conn->prepare($query);
        
        if ($idsous_specialite) {
            $stmt->bindParam(':idsous_specialite', $idsous_specialite);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getPrixActe($idacte, $idcategorie_patient, $idsociete = null) {
        // Logique de récupération du prix selon la catégorie et la société
        if ($idsociete) {
            // Pour les conventionnés, vérifier le tarif société
            $query = "SELECT prix FROM societe_tarif_detail 
                      WHERE idacte = :idacte 
                      AND idsociete = :idsociete 
                      AND idcategorie = :idcategorie";
            // À implémenter selon votre logique tarifaire
        } else {
            // Pour les privés, prix standard
            $query = "SELECT prix_base FROM acte WHERE idacte = :idacte";
        }
        
        // Simplification : retourner un prix fixe pour l'exemple
        return 5000; // À remplacer par la vraie logique
    }

    public function prescrire($idsous_sejour, $idacte, $quantite, $prix, $urgent = false) {
        $query = "INSERT INTO " . $this->table . "
                  (idsous_sejour, idacte, quantite, prix_unitaire, montant_total, 
                   prescripteur, urgent, statut_validation)
                  VALUES
                  (:idsous_sejour, :idacte, :quantite, :prix_unitaire, :montant_total,
                   :prescripteur, :urgent, 'rien')";

        $stmt = $this->conn->prepare($query);

        $montant_total = $quantite * $prix;

        $stmt->bindParam(':idsous_sejour', $idsous_sejour);
        $stmt->bindParam(':idacte', $idacte);
        $stmt->bindParam(':quantite', $quantite);
        $stmt->bindParam(':prix_unitaire', $prix);
        $stmt->bindParam(':montant_total', $montant_total);
        $stmt->bindParam(':prescripteur', $_SESSION['user_id']);
        $stmt->bindParam(':urgent', $urgent, PDO::PARAM_BOOL);

        return $stmt->execute();
    }

    public function getActesNonValides($idsous_sejour) {
        $query = "SELECT ap.*, a.libelle as acte_libelle, a.code as acte_code
                  FROM " . $this->table . " ap
                  JOIN acte a ON ap.idacte = a.idacte
                  WHERE ap.idsous_sejour = :idsous_sejour
                  AND ap.statut_validation = 'rien'
                  ORDER BY ap.date_prescription DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':idsous_sejour', $idsous_sejour);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function valider($idactes_presc, $mode_paiement) {
        // Valider que le mode de paiement est valide
        $modes_valides = ['cash', 'credit_card', 'mobile_money', 'credit_societe', 'assurance'];
        
        if (!in_array($mode_paiement, $modes_valides)) {
            throw new Exception("Mode de paiement invalide");
        }
        
        $query = "UPDATE actes_presc
                SET statut_validation = 'valide',
                    mode_paiement = :mode_paiement,
                    date_validation = NOW(),
                    valideur = :valideur
                WHERE idactes_presc = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':mode_paiement', $mode_paiement);
        $stmt->bindParam(':valideur', $_SESSION['user_id']);
        $stmt->bindParam(':id', $idactes_presc);
        
        if ($stmt->execute()) {
            // Notifier le service technique après validation
            $this->notifierServiceApresValidation($idactes_presc);
            return true;
        }
        
        return false;
    }

    // Dans Acte.php
    private function notifierServiceApresValidation($idactes_presc) {
        $query = "SELECT a.libelle, 
                        CASE 
                            WHEN a.idcategorie_acte = 6 THEN 'laboratoire'
                            WHEN a.idcategorie_acte = 5 THEN 'imagerie'
                            ELSE 'acte_medical'
                        END as type_prescription
                FROM actes_presc ap
                JOIN acte a ON ap.idacte = a.idacte
                WHERE ap.idactes_presc = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $idactes_presc]);
        $data = $stmt->fetch();
        
        if ($data) {
            $service_map = [
                'laboratoire' => 'laboratoire',
                'imagerie' => 'imagerie',
                'acte_medical' => 'soins'
            ];
            
            $service = $service_map[$data['type_prescription']] ?? 'soins';
            
            $query_users = "SELECT u.idutilisateur 
                FROM utilisateur u
                JOIN profiluser p ON u.idprofiluser = p.idprofiluser
                JOIN fct_profiluser fp ON p.idprofiluser = fp.idprofiluser
                JOIN fct f ON fp.idfct = f.idfct
                WHERE f.code = :service
                AND u.actif = 1";
            
            $stmt_users = $this->conn->prepare($query_users);
            $stmt_users->execute([':service' => $service]);
            
            $query_notif = "INSERT INTO notifications 
                (idutilisateur, type, titre, message, lien, priorite)
                VALUES (:idutilisateur, 'info', :titre, :message, :lien, 'normale')";
            
            $stmt_notif = $this->conn->prepare($query_notif);
            
            while ($user = $stmt_users->fetch()) {
                $lien = $this->getLienService($data['type_prescription'], $idactes_presc);
                
                $stmt_notif->execute([
                    ':idutilisateur' => $user['idutilisateur'],
                    ':titre' => 'Prescription validée - Prêt pour exécution',
                    ':message' => "Prescription validée : {$data['libelle']}",
                    ':lien' => $lien
                ]);
            }
        }
    }

    private function getLienService($type, $id) {
        switch ($type) {
            case 'laboratoire':
                return "../laboratoire/prelevement.php?id={$id}";
            case 'imagerie':
                return "../imagerie/realiser-examen.php?id={$id}";
            default:
                return "../soins/realiser-acte.php?id={$id}";
        }
    }
}