<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class VisitReport {
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
CREATE TABLE IF NOT EXISTS visit_pal_reports (
    report_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL,
    pal_ID INT UNSIGNED NOT NULL,
    phase ENUM('During','After') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_visit_pal (visit_ID, pal_ID),
    KEY idx_visit (visit_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        try {
            self::db()->exec($sql);
        } catch (\Throwable $e) {
            // Table may already exist with different definition
        }
        self::$tableReady = true;
    }

    public static function add(int $visitId, int $palPalId, string $phase, string $body): void {
        self::ensureTable();
        $phaseNorm = strcasecmp($phase, 'After') === 0 ? 'After' : 'During';
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException('Report text cannot be empty.');
        }
        $stmt = self::db()->prepare('INSERT INTO visit_pal_reports (visit_ID, pal_ID, phase, body) VALUES (?, ?, ?, ?)');
        $stmt->execute([$visitId, $palPalId, $phaseNorm, substr($body, 0, 60000)]);
    }

    /** @return list<array<string,mixed>> */
    public static function listByVisit(int $visitId): array {
        self::ensureTable();
        try {
            $stmt = self::db()->prepare('SELECT * FROM visit_pal_reports WHERE visit_ID = ? ORDER BY created_at ASC, report_ID ASC');
            $stmt->execute([$visitId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
