<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host     = getenv('DB_HOST')     ?: 'db';
        $this->db_name  = getenv('DB_NAME')     ?: 'csk_base';
        $this->username = getenv('DB_USER')     ?: 'root';
        $this->password = getenv('DB_PASSWORD') ?: 'password';
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);
            $this->conn->exec("SET NAMES 'utf8mb4'");
        } catch (PDOException $e) {
            error_log("Erreur connexion DB : " . $e->getMessage());
            die(json_encode(['error' => 'Erreur de connexion à la base de données.']));
        }
        return $this->conn;
    }
}
