<?php
class PrescriptionMedicale {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // ============================================
    // PRESCRIPTIONS LABORATOIRE / IMAGERIE
    // ============================================

    /**
     * Prescrire un acte de laboratoire
     * @param array $data : idsous_sejour, idacte, idsite, idspecialite, prix,
     *                       prescripteur, urgent, indication, type_externe, centre_externe
     */
    public function prescrireLaboratoire(array $data) {
        return $this->prescrireActe($data, 'laboratoire');
    }

    /**
     * Prescrire un acte d'imagerie
     */
    public function prescrireImagerie(array $data) {
        return $this->prescrireActe($data, 'imagerie');
    }

    /**
     * Méthode commune pour actes (labo / imagerie)
     */
    private function prescrireActe(array $data, string $type) {
        $sql = "INSERT INTO actes_presc (
                    idsous_sejour, idacte, idsite, idspecialite,
                    quantite, prix_unitaire, montant_total,
                    prescripteur, urgent, indication,
                    statut_validation, statut_execution,
                    type_externe, centre_externe, date_prescription
                ) VALUES (
                    :idsous_sejour, :idacte, :idsite, :idspecialite,
                    1, :prix, :prix,
                    :prescripteur, :urgent, :indication,
                    'en_attente', 'en_attente',
                    :type_externe, :centre_externe, NOW()
                )";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':idsous_sejour' => $data['idsous_sejour'],
            ':idacte'        => $data['idacte'],
            ':idsite'        => $data['idsite'],
            ':idspecialite'  => $data['idspecialite'] ?? null,
            ':prix'          => (float)($data['prix'] ?? 0),
            ':prescripteur'  => $data['prescripteur'],
            ':urgent'        => (int)($data['urgent'] ?? 0),
            ':indication'    => $data['indication'] ?? null,
            ':type_externe'  => $data['type_externe'] ?? 'interne',
            ':centre_externe'=> $data['centre_externe'] ?? null,
        ]);

        return $this->conn->lastInsertId();
    }

    // ============================================
    // PRESCRIPTIONS PHARMACIE
    // ============================================

    /**
     * Prescrire un médicament
     * @param array $data : idsous_sejour, idprodpharma, quantite, posologie,
     *                       prescripteur, urgence, montant_total, observation
     */
    public function prescrirePharma(array $data) {
        $sql = "INSERT INTO pharma_presc (
                    idsous_sejour, idprodpharma, quantite,
                    posologie, prescripteur, urgence,
                    montant_total, statut_execution, date_prescription
                ) VALUES (
                    :idsous_sejour, :idprodpharma, :quantite,
                    :posologie, :prescripteur, :urgence,
                    :montant_total, 'en_attente', NOW()
                )";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':idsous_sejour' => $data['idsous_sejour'],
            ':idprodpharma'  => $data['idprodpharma'],
            ':quantite'      => (int)($data['quantite'] ?? 1),
            ':posologie'     => $data['posologie'] ?? null,
            ':prescripteur'  => $data['prescripteur'],
            ':urgence'       => (int)($data['urgence'] ?? 0),
            ':montant_total' => (float)($data['montant_total'] ?? 0),
        ]);

        return $this->conn->lastInsertId();
    }

    // ============================================
    // RÉCUPÉRER LES PRESCRIPTIONS
    // ============================================

    /**
     * Prescriptions pharma en attente pour un séjour
     */
    public function getPrescriptionsPharmaEnAttente($idsejour) {
        $query = "SELECT pp.*,
                         pr.libelle AS produit_libelle,
                         pr.code    AS produit_code,
                         p.nom      AS patient_nom,
                         p.prenom   AS patient_prenom,
                         u.nom      AS prescripteur_nom,
                         sp.quantite AS stock_disponible
                  FROM pharma_presc pp
                  JOIN prodpharma  pr ON pp.idprodpharma = pr.idprodpharma
                  JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
                  JOIN sejour      s  ON ss.idsejour      = s.idsejour
                  JOIN patient     p  ON s.idpatient      = p.idpatient
                  LEFT JOIN utilisateur u ON pp.prescripteur  = u.idutilisateur
                  LEFT JOIN stockpharma sp ON pr.idprodpharma = sp.idprodpharma
                  WHERE s.idsejour = :idsejour
                  AND   pp.statut_execution = 'en_attente'
                  ORDER BY pp.date_prescription DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':idsejour' => $idsejour]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prescriptions actes (labo/imagerie) pour un sous-séjour
     */
    public function getPrescriptionsActes($idsous_sejour) {
        $query = "SELECT ap.*,
                         a.libelle  AS acte_libelle,
                         a.code     AS acte_code,
                         u.nom      AS prescripteur_nom
                  FROM actes_presc ap
                  JOIN acte        a ON ap.idacte      = a.idacte
                  LEFT JOIN utilisateur u ON ap.prescripteur = u.idutilisateur
                  WHERE ap.idsous_sejour = :idsous_sejour
                  ORDER BY ap.date_prescription DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':idsous_sejour' => $idsous_sejour]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ============================================
    // NOTIFICATIONS
    // ============================================

    /**
     * Notifier le prescripteur de la livraison d'un médicament
     */
    public function notifierPrescripteurLivraison($idpharma_presc) {
        // Récupérer infos prescription
        $query = "SELECT pp.prescripteur,
                         pr.libelle AS produit_libelle,
                         p.nom      AS patient_nom,
                         p.prenom   AS patient_prenom
                  FROM   pharma_presc pp
                  JOIN   prodpharma  pr ON pp.idprodpharma = pr.idprodpharma
                  JOIN   sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
                  JOIN   sejour      s  ON ss.idsejour      = s.idsejour
                  JOIN   patient     p  ON s.idpatient      = p.idpatient
                  WHERE  pp.idpharma_presc = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $idpharma_presc]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$info) return false;

        // Insérer notification (si table messages existe)
        try {
            $message = "Médicament '{$info['produit_libelle']}' délivré pour {$info['patient_prenom']} {$info['patient_nom']}.";
            $qNotif  = "INSERT INTO msg (idutilisateur, message, date_envoi, lu)
                        VALUES (:idutilisateur, :message, NOW(), 0)";
            $sNotif  = $this->conn->prepare($qNotif);
            $sNotif->execute([
                ':idutilisateur' => $info['prescripteur'],
                ':message'       => $message,
            ]);
        } catch (PDOException $e) {
            // La table msg peut ne pas exister — pas bloquant
            error_log("Notification non envoyée : " . $e->getMessage());
        }

        return true;
    }

    // ============================================
    // VALIDATION / EXÉCUTION
    // ============================================

    /**
     * Délivrer un médicament : déduire stock + mettre à jour statut
     */
    public function delivrerMedicament($idpharma_presc, $idofficine, $idexecuteur) {
        // Récupérer la prescription
        $qGet = "SELECT pp.*, s.idsite
                 FROM pharma_presc pp
                 JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
                 JOIN sejour      s  ON ss.idsejour      = s.idsejour
                 WHERE pp.idpharma_presc = :id";
        $sGet = $this->conn->prepare($qGet);
        $sGet->execute([':id' => $idpharma_presc]);
        $presc = $sGet->fetch(PDO::FETCH_ASSOC);

        if (!$presc) {
            throw new Exception("Prescription introuvable.");
        }

        // Vérifier stock
        $qStock = "SELECT quantite FROM stockpharma
                   WHERE idprodpharma = :idprodpharma AND idofficine = :idofficine";
        $sStock = $this->conn->prepare($qStock);
        $sStock->execute([
            ':idprodpharma' => $presc['idprodpharma'],
            ':idofficine'   => $idofficine,
        ]);
        $stock = $sStock->fetch(PDO::FETCH_ASSOC);
        $qteDisponible = $stock['quantite'] ?? 0;

        if ($qteDisponible < $presc['quantite']) {
            throw new Exception("Stock insuffisant ! Disponible : {$qteDisponible}, Demandé : {$presc['quantite']}");
        }

        $this->conn->beginTransaction();

        // Déduire stock
        $qDeduire = "UPDATE stockpharma
                     SET quantite = quantite - :quantite
                     WHERE idprodpharma = :idprodpharma AND idofficine = :idofficine";
        $sDeduire = $this->conn->prepare($qDeduire);
        $sDeduire->execute([
            ':quantite'      => $presc['quantite'],
            ':idprodpharma'  => $presc['idprodpharma'],
            ':idofficine'    => $idofficine,
        ]);

        // Mettre à jour prescription
        $qUpdate = "UPDATE pharma_presc
                    SET statut_execution = 'acheve',
                        date_execution   = NOW(),
                        executeur        = :executeur
                    WHERE idpharma_presc = :id";
        $sUpdate = $this->conn->prepare($qUpdate);
        $sUpdate->execute([':executeur' => $idexecuteur, ':id' => $idpharma_presc]);

        $this->conn->commit();

        // Notifier prescripteur
        $this->notifierPrescripteurLivraison($idpharma_presc);

        return true;
    }
}
