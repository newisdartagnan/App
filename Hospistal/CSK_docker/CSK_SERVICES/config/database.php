<?php
class Database {
    private $host     = "db";
    private $username = "root";
    private $password = "password";

    private $db_base     = "csk_base";   // ← anciennement csk_base
    private $db_services = "csk_services";

    private $conn_base;
    private $conn_services;

    public function getConnection() {
        $this->conn_base = null;
        try {
            $this->conn_base = new PDO(
                "mysql:host={$this->host};dbname={$this->db_base};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $this->conn_base->exec("SET time_zone = '+01:00'");
        } catch (PDOException $e) {
            echo "Erreur de connexion (base): " . $e->getMessage();
        }
        return $this->conn_base;
    }

    public function getServicesConnection() {
        $this->conn_services = null;
        try {
            $this->conn_services = new PDO(
                "mysql:host={$this->host};dbname={$this->db_services};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $this->conn_services->exec("SET time_zone = '+01:00'");
        } catch (PDOException $e) {
            echo "Erreur de connexion (services): " . $e->getMessage();
        }
        return $this->conn_services;
    }

    // Alias
    public function getBaseConnection() {
        return $this->getConnection();
    }
}