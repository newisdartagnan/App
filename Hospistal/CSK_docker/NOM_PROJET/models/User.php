<?php
class User {
    private $conn;
    private $table = 'utilisateur';

    public function __construct($db) {
        $this->conn = $db;
    }

    /**
     * Connexion utilisateur
     */
    public function login($username, $password) {
        $query = "SELECT u.*,
                         p.nom    AS profil_nom,
                         s.nom    AS site_nom
                  FROM   " . $this->table . " u
                  LEFT JOIN profiluser p ON u.idprofiluser = p.idprofiluser
                  LEFT JOIN site       s ON u.idsite       = s.idsite
                  WHERE  u.username = :username
                  AND    u.actif    = 1
                  LIMIT  1";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return false;

        // Support password_hash ET ancien mot de passe en clair
        if (password_verify($password, $user['password'] ?? '')) {
            return $user;
        }
        // Fallback legacy (plain text)
        if (isset($user['password']) && $user['password'] === $password) {
            return $user;
        }
        return false;
    }

    /**
     * Récupérer les permissions (codes fonctions) du profil
     */
    public function getPermissions($idprofiluser) {
        $query = "SELECT f.code
                  FROM   fct_profiluser fp
                  JOIN   fct f ON fp.idfct = f.idfct
                  WHERE  fp.idprofiluser = :idprofiluser";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':idprofiluser' => $idprofiluser]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'code');
    }

    /**
     * Récupérer un utilisateur par ID
     */
    public function getById($id) {
        $query = "SELECT u.*, p.nom AS profil_nom, s.nom AS site_nom
                  FROM   " . $this->table . " u
                  LEFT JOIN profiluser p ON u.idprofiluser = p.idprofiluser
                  LEFT JOIN site       s ON u.idsite       = s.idsite
                  WHERE  u.idutilisateur = :id";

        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Changer le mot de passe
     */
    public function changePassword($idutilisateur, $newPassword) {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $query  = "UPDATE " . $this->table . "
                   SET password = :password
                   WHERE idutilisateur = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':password' => $hashed, ':id' => $idutilisateur]);
    }
}