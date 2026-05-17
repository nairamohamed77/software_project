<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

/**
 * UC-34: Append-only system audit trail (no updates/deletes from app).
 */
final class SystemAudit {
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
        self::db()->exec(
            'CREATE TABLE IF NOT EXISTS system_audit_log (
                log_ID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_User_ID INT UNSIGNED DEFAULT NULL,
                action_type VARCHAR(120) NOT NULL,
                entity_type VARCHAR(80) DEFAULT NULL,
                entity_ID BIGINT UNSIGNED DEFAULT NULL,
                details TEXT,
                ip_address VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_audit_created (created_at),
                KEY idx_audit_actor (actor_User_ID),
                KEY idx_audit_entity (entity_type, entity_ID),
                KEY idx_audit_action (action_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableReady = true;
    }

    /** Best-effort: remove legacy per-dispute tables (replaced by this log). */
    public static function dropLegacyDisputeSideTables(): void {
        try {
            self::db()->exec('DROP TABLE IF EXISTS dispute_messages');
        } catch (\Throwable $e) {
        }
        try {
            self::db()->exec('DROP TABLE IF EXISTS dispute_audit_log');
        } catch (\Throwable $e) {
        }
    }

    public static function clientIp(): ?string {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip === '' || $ip === '127.0.0.1') {
            return null;
        }

        return substr($ip, 0, 45);
    }

    public static function record(
        ?int $actorUserId,
        string $actionType,
        ?string $entityType,
        ?int $entityId,
        ?string $details,
        ?string $ip = null
    ): void {
        self::ensureTable();
        $stmt = self::db()->prepare(
            'INSERT INTO system_audit_log (actor_User_ID, action_type, entity_type, entity_ID, details, ip_address)
             VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([
            $actorUserId ?: null,
            substr($actionType, 0, 120),
            $entityType !== null ? substr($entityType, 0, 80) : null,
            $entityId,
            $details !== null ? substr($details, 0, 16000) : null,
            $ip !== null ? substr($ip, 0, 45) : self::clientIp(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function listRecent(
        int $limit = 150,
        ?string $actionFilter = null,
        ?string $entityTypeFilter = null,
        ?string $search = null
    ): array {
        self::ensureTable();
        $limit = max(1, min(500, $limit));
        $where = ['1=1'];
        $params = [];
        if ($actionFilter !== null && $actionFilter !== '') {
            $where[] = 'action_type LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $actionFilter) . '%';
        }
        if ($entityTypeFilter !== null && $entityTypeFilter !== '') {
            $where[] = 'entity_type = ?';
            $params[] = substr($entityTypeFilter, 0, 80);
        }
        if ($search !== null && $search !== '') {
            $where[] = '(details LIKE ? OR CAST(entity_ID AS CHAR) LIKE ? OR action_type LIKE ?)';
            $s = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
        $sql = 'SELECT l.*, CONCAT(IFNULL(u.Fname,\'\'),\' \',IFNULL(u.Lname,\'\')) AS actor_name, u.email AS actor_email
                FROM system_audit_log l
                LEFT JOIN users u ON l.actor_User_ID = u.User_ID
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY l.log_ID DESC
                LIMIT ' . $limit;
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public static function forEntity(string $entityType, int $entityId, int $limit = 80): array {
        self::ensureTable();
        $limit = max(1, min(200, $limit));
        $stmt = self::db()->prepare(
            'SELECT l.*, CONCAT(IFNULL(u.Fname,\'\'),\' \',IFNULL(u.Lname,\'\')) AS actor_name
             FROM system_audit_log l
             LEFT JOIN users u ON l.actor_User_ID = u.User_ID
             WHERE l.entity_type = ? AND l.entity_ID = ?
             ORDER BY l.log_ID ASC
             LIMIT ' . $limit
        );
        $stmt->execute([substr($entityType, 0, 80), $entityId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
