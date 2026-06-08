<?php
class BlocOperatoire {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Récupérer le programme opératoire d'une journée
     */
    public function getProgrammeOperatoire($idsite, $date, $idsalle = null) {
        $query = "SELECT
                    bi.*,
                    p.nom              AS nom,
                    p.prenom           AS prenom,
                    p.numero_dossier,
                    sb.numero_salle,
                    sb.nom_salle,
                    ch.nom             AS chirurgien_nom,
                    ch.prenom          AS chirurgien_prenom,
                    an.nom             AS anesthesiste_nom,
                    an.prenom          AS anesthesiste_prenom
                FROM bloc_intervention bi
                JOIN sous_sejour ss ON bi.idsous_sejour = ss.idsous_sejour
                JOIN sejour      s  ON ss.idsejour      = s.idsejour
                JOIN patient     p  ON s.idpatient      = p.idpatient
                JOIN salle_bloc  sb ON bi.idsalle_bloc  = sb.idsalle_bloc
                LEFT JOIN utilisateur ch ON bi.idchirurgien    = ch.idutilisateur
                LEFT JOIN utilisateur an ON bi.idanesthesiste  = an.idutilisateur
                WHERE s.idsite      = :idsite
                AND   bi.date_prevue = :date";

        $params = [':idsite' => $idsite, ':date' => $date];

        if ($idsalle) {
            $query .= " AND bi.idsalle_bloc = :idsalle";
            $params[':idsalle'] = $idsalle;
        }

        $query .= " ORDER BY bi.heure_debut_prevue ASC, bi.urgence DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Programmer une intervention
     */
    public function programmer(array $data) {
        $sql = "INSERT INTO bloc_intervention (
                    idsous_sejour, type_intervention, libelle_intervention,
                    idchirurgien, idanesthesiste, idsalle_bloc,
                    date_prevue, heure_debut_prevue, duree_prevue_minutes,
                    type_anesthesie, urgence, position_patient,
                    statut, idutilisateur_programmation, date_programmation
                ) VALUES (
                    :idsous_sejour, :type_intervention, :libelle_intervention,
                    :idchirurgien, :idanesthesiste, :idsalle_bloc,
                    :date_prevue, :heure_debut_prevue, :duree_prevue_minutes,
                    :type_anesthesie, :urgence, :position_patient,
                    'programmee', :idutilisateur, NOW()
                )";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            ':idsous_sejour'       => $data['idsous_sejour'],
            ':type_intervention'   => $data['type_intervention'] ?? null,
            ':libelle_intervention'=> $data['libelle_intervention'],
            ':idchirurgien'        => $data['idchirurgien'],
            ':idanesthesiste'      => $data['idanesthesiste'] ?? null,
            ':idsalle_bloc'        => $data['idsalle_bloc'],
            ':date_prevue'         => $data['date_prevue'],
            ':heure_debut_prevue'  => $data['heure_debut_prevue'],
            ':duree_prevue_minutes'=> $data['duree_prevue_minutes'] ?? 60,
            ':type_anesthesie'     => $data['type_anesthesie'] ?? null,
            ':urgence'             => (int)($data['urgence'] ?? 0),
            ':position_patient'    => $data['position_patient'] ?? null,
            ':idutilisateur'       => $data['idutilisateur'],
        ]);

        return $this->conn->lastInsertId();
    }

    /**
     * Débuter une intervention
     */
    public function debuter($idintervention) {
        $sql = "UPDATE bloc_intervention
                SET statut           = 'en_cours',
                    heure_debut_reelle = TIME(NOW()),
                    date_modification  = NOW()
                WHERE idintervention = :id";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([':id' => $idintervention]);
    }

    /**
     * Terminer une intervention
     */
    public function terminer($idintervention, array $data = []) {
        $sql = "UPDATE bloc_intervention
                SET statut           = 'terminee',
                    heure_fin_reelle = TIME(NOW()),
                    complications    = :complications,
                    observations_postop = :observations_postop,
                    date_modification   = NOW()
                WHERE idintervention = :id";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':complications'      => $data['complications'] ?? null,
            ':observations_postop'=> $data['observations_postop'] ?? null,
            ':id'                 => $idintervention,
        ]);
    }

    /**
     * Récupérer une intervention par ID
     */
    public function getById($idintervention) {
        $query = "SELECT bi.*,
                         p.nom AS nom, p.prenom, p.numero_dossier,
                         sb.numero_salle, sb.nom_salle,
                         ch.nom AS chirurgien_nom, ch.prenom AS chirurgien_prenom
                  FROM bloc_intervention bi
                  JOIN sous_sejour ss ON bi.idsous_sejour = ss.idsous_sejour
                  JOIN sejour      s  ON ss.idsejour      = s.idsejour
                  JOIN patient     p  ON s.idpatient      = p.idpatient
                  JOIN salle_bloc  sb ON bi.idsalle_bloc  = sb.idsalle_bloc
                  LEFT JOIN utilisateur ch ON bi.idchirurgien = ch.idutilisateur
                  WHERE bi.idintervention = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $idintervention]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
