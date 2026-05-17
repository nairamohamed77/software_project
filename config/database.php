<?php
declare(strict_types=1);

class Database {
    private static ?Database $instance = null;
    private PDO $connection;

    private string $host = 'localhost';
    private string $dbname = 'carenest';
    private string $username = 'root';
    private string $password = '';

    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => 'DB Connection Failed']));
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    private function __clone() {}

    /** @throws Exception */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}
