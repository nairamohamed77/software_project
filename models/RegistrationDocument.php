<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class RegistrationDocument {
    private static PDO $db;
    private static bool $tableReady = false;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    public static function ensureTable(): void {
        if (self::$tableReady) {
            return;
        }
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS registration_documents (
    registration_document_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    User_ID INT UNSIGNED NOT NULL,
    document_type VARCHAR(60) NOT NULL,
    original_name VARCHAR(255) DEFAULT NULL,
    file_url VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (User_ID),
    CONSTRAINT fk_registration_documents_user
        FOREIGN KEY (User_ID) REFERENCES users(User_ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        self::db()->exec($sql);
        self::$tableReady = true;
    }

    public static function add(
        int $userId,
        string $documentType,
        string $originalName,
        string $fileUrl,
        ?string $mimeType = null
    ): void {
        self::ensureTable();
        $stmt = self::db()->prepare(
            'INSERT INTO registration_documents (User_ID, document_type, original_name, file_url, mime_type) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            substr(trim($documentType), 0, 60),
            substr(trim($originalName), 0, 255),
            substr(trim($fileUrl), 0, 255),
            $mimeType !== null ? substr(trim($mimeType), 0, 120) : null,
        ]);
    }
}
