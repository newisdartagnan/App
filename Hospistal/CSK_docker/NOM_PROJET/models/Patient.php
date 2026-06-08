<?php
class Patient {
    private $conn;
    private $table = 'patient';

    public $idpatient;
    public $numero_dossier;
    public $nom;
    public $prenom;
    public $postnom;
    public $date_naissance;
    public $sexe;
    public $telephone1;
    public $type_patient;
    public $idsociete;
    public $idcategorie;
    public $lieu_naissance;
    public $etat_civil;
    public $profession;
    public $nationalite;
    public $idquartier;
    public $avenue;
    public $numero;
    public $telephone2;
    public $email;
    public $idgrsanguin;
    public $idethnie;
    public $idreligion;
    public $numero_carte_assurance;
    public $nom_contact;
    public $telephone_contact;
    public $lien_parente;
    public $idutilisateur;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function create() {
        $this->numero_dossier = $this->generateNumeroDossier();

        $query = "INSERT INTO " . $this->table . "
            (numero_dossier, nom, prenom, postnom, date_naissance, lieu_naissance,
            sexe, etat_civil, profession, nationalite, idquartier, avenue, numero,
            telephone1, telephone2, email, idgrsanguin, idethnie, idreligion,
            type_patient, idsociete, idcategorie, numero_carte_assurance,
            nom_contact, telephone_contact, lien_parente, idutilisateur)
            VALUES
            (:numero_dossier, :nom, :prenom, :postnom, :date_naissance, :lieu_naissance,
            :sexe, :etat_civil, :profession, :nationalite, :idquartier, :avenue, :numero,
            :telephone1, :telephone2, :email, :idgrsanguin, :idethnie, :idreligion,
            :type_patient, :idsociete, :idcategorie, :numero_carte_assurance,
            :nom_contact, :telephone_contact, :lien_parente, :idutilisateur)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':numero_dossier',        $this->numero_dossier);
        $stmt->bindParam(':nom',                   $this->nom);
        $stmt->bindParam(':prenom',                $this->prenom);
        $stmt->bindParam(':postnom',               $this->postnom);
        $stmt->bindParam(':date_naissance',        $this->date_naissance);
        $stmt->bindParam(':lieu_naissance',        $this->lieu_naissance);
        $stmt->bindParam(':sexe',                  $this->sexe);
        $stmt->bindParam(':etat_civil',            $this->etat_civil);
        $stmt->bindParam(':profession',            $this->profession);
        $stmt->bindParam(':nationalite',           $this->nationalite);
        $stmt->bindParam(':idquartier',            $this->idquartier);
        $stmt->bindParam(':avenue',                $this->avenue);
        $stmt->bindParam(':numero',                $this->numero);
        $stmt->bindParam(':telephone1',            $this->telephone1);
        $stmt->bindParam(':telephone2',            $this->telephone2);
        $stmt->bindParam(':email',                 $this->email);
        $stmt->bindParam(':idgrsanguin',           $this->idgrsanguin);
        $stmt->bindParam(':idethnie',              $this->idethnie);
        $stmt->bindParam(':idreligion',            $this->idreligion);
        $stmt->bindParam(':type_patient',          $this->type_patient);
        $stmt->bindParam(':idsociete',             $this->idsociete);
        $stmt->bindParam(':idcategorie',           $this->idcategorie);
        $stmt->bindParam(':numero_carte_assurance',$this->numero_carte_assurance);
        $stmt->bindParam(':nom_contact',           $this->nom_contact);
        $stmt->bindParam(':telephone_contact',     $this->telephone_contact);
        $stmt->bindParam(':lien_parente',          $this->lien_parente);
        $stmt->bindParam(':idutilisateur',         $this->idutilisateur);

        if ($stmt->execute()) {
            $this->idpatient = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }

    /**
     * Recherche flexible par nom / prénom / postnom
     */
    public function searchByName($nom, $prenom = '') {
        $nom    = trim($nom);
        $prenom = trim($prenom);

        if (empty($nom) && empty($prenom)) {
            return [];
        }

        $query = "SELECT
                    p.idpatient,
                    p.numero_dossier,
                    p.nom,
                    p.prenom,
                    p.postnom,
                    p.date_naissance,
                    p.sexe,
                    p.telephone1,
                    p.type_patient,
                    s.nom  AS societe_nom,
                    c.nom  AS categorie_nom
                FROM " . $this->table . " p
                LEFT JOIN societe   s ON p.idsociete   = s.idsociete
                LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
                WHERE 1=1";

        if (!empty($nom)) {
            $query .= " AND (p.nom LIKE :search OR p.prenom LIKE :search OR p.postnom LIKE :search)";
        }
        if (!empty($prenom)) {
            $query .= " AND p.prenom LIKE :prenom";
        }

        $query .= " ORDER BY p.nom, p.prenom LIMIT 40";

        $stmt = $this->conn->prepare($query);

        if (!empty($nom)) {
            $stmt->bindValue(':search', '%' . $nom . '%', PDO::PARAM_STR);
        }
        if (!empty($prenom)) {
            $stmt->bindValue(':prenom', '%' . $prenom . '%', PDO::PARAM_STR);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche par numéro de dossier
     */
    public function searchByNumeroDossier($numero_dossier) {
        $query = "SELECT
                    p.idpatient, p.numero_dossier, p.nom, p.prenom, p.postnom,
                    p.date_naissance, p.sexe, p.telephone1, p.type_patient,
                    c.nom AS categorie_nom, s.nom AS societe_nom
                FROM " . $this->table . " p
                LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
                LEFT JOIN societe   s ON p.idsociete   = s.idsociete
                WHERE p.numero_dossier LIKE :numero
                LIMIT 20";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':numero' => '%' . $numero_dossier . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche par date de naissance
     */
    public function searchByDateNaissance($date_naissance) {
        $query = "SELECT p.*,
                         c.nom AS categorie_nom,
                         s.nom AS societe_nom
                  FROM " . $this->table . " p
                  LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
                  LEFT JOIN societe   s ON p.idsociete   = s.idsociete
                  WHERE p.date_naissance = :date_naissance
                  ORDER BY p.nom, p.prenom";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':date_naissance' => $date_naissance]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Récupérer un patient par ID
     */
    public function getById($id) {
        $query = "SELECT p.*,
                         c.nom   AS categorie_nom,
                         s.nom   AS societe_nom,
                         s.type_tarif AS societe_tarif,
                         q.nom   AS quartier_nom,
                         co.nom  AS commune_nom,
                         g.nom   AS groupe_sanguin,
                         e.nom   AS ethnie_nom,
                         r.nom   AS religion_nom
                  FROM " . $this->table . " p
                  LEFT JOIN categorie c  ON p.idcategorie = c.idcategorie
                  LEFT JOIN societe   s  ON p.idsociete   = s.idsociete
                  LEFT JOIN quartier  q  ON p.idquartier  = q.idquartier
                  LEFT JOIN commune   co ON q.idcommune   = co.idcommune
                  LEFT JOIN grsanguin g  ON p.idgrsanguin = g.idgrsanguin
                  LEFT JOIN ethnie    e  ON p.idethnie    = e.idethnie
                  LEFT JOIN religion  r  ON p.idreligion  = r.idreligion
                  WHERE p.idpatient = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Mise à jour d'un patient
     */
    public function update() {
        $query = "UPDATE " . $this->table . "
                  SET nom        = :nom,
                      prenom     = :prenom,
                      postnom    = :postnom,
                      date_naissance = :date_naissance,
                      lieu_naissance = :lieu_naissance,
                      sexe       = :sexe,
                      etat_civil = :etat_civil,
                      profession = :profession,
                      nationalite = :nationalite,
                      idquartier = :idquartier,
                      avenue     = :avenue,
                      numero     = :numero,
                      telephone1 = :telephone1,
                      telephone2 = :telephone2,
                      email      = :email,
                      type_patient  = :type_patient,
                      idsociete     = :idsociete,
                      idcategorie   = :idcategorie,
                      numero_carte_assurance = :numero_carte_assurance,
                      nom_contact        = :nom_contact,
                      telephone_contact  = :telephone_contact,
                      lien_parente       = :lien_parente
                  WHERE idpatient = :id";

        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':nom'                    => $this->nom,
            ':prenom'                 => $this->prenom,
            ':postnom'                => $this->postnom,
            ':date_naissance'         => $this->date_naissance,
            ':lieu_naissance'         => $this->lieu_naissance,
            ':sexe'                   => $this->sexe,
            ':etat_civil'             => $this->etat_civil,
            ':profession'             => $this->profession,
            ':nationalite'            => $this->nationalite,
            ':idquartier'             => $this->idquartier,
            ':avenue'                 => $this->avenue,
            ':numero'                 => $this->numero,
            ':telephone1'             => $this->telephone1,
            ':telephone2'             => $this->telephone2,
            ':email'                  => $this->email,
            ':type_patient'           => $this->type_patient,
            ':idsociete'              => $this->idsociete,
            ':idcategorie'            => $this->idcategorie,
            ':numero_carte_assurance' => $this->numero_carte_assurance,
            ':nom_contact'            => $this->nom_contact,
            ':telephone_contact'      => $this->telephone_contact,
            ':lien_parente'           => $this->lien_parente,
            ':id'                     => $this->idpatient
        ]);
    }

    /**
     * Récupérer les séjours d'un patient
     */
    public function getSejoursByPatient($idpatient) {
        $query = "SELECT
                    s.idsejour,
                    s.numero_sejour,
                    s.type_sejour,
                    s.anciennete,
                    s.numero_jeton,
                    s.date_entree,
                    s.date_sortie,
                    s.statut,
                    s.observation,
                    m.libelle   AS motif,
                    site.nom    AS site_nom,
                    um.nom      AS unite_nom,
                    u.nom       AS utilisateur_nom
                FROM sejour s
                LEFT JOIN motif    m    ON s.idmotif    = m.idmotif
                LEFT JOIN site     site ON s.idsite     = site.idsite
                LEFT JOIN sous_sejour ss ON s.idsejour  = ss.idsejour
                LEFT JOIN unite_med   um ON ss.idunite_med = um.idunite_med
                LEFT JOIN utilisateur u  ON s.idutilisateur = u.idutilisateur
                WHERE s.idpatient = :idpatient
                GROUP BY s.idsejour
                ORDER BY s.date_entree DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':idpatient' => $idpatient]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Génération du numéro de dossier
     */
    private function generateNumeroDossier() {
        $query = "SELECT MAX(CAST(SUBSTRING(numero_dossier, 4) AS UNSIGNED)) AS last_num
                  FROM " . $this->table . "
                  WHERE numero_dossier LIKE 'PAT%'";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastNumber = $row['last_num'] ?? 0;
        return generateNumero('PAT', $lastNumber);
    }
}
