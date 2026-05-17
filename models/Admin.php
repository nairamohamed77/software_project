<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class Admin {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /** @return array<string,int> */
    public static function overviewStats(): array {
        return [
            'users' => self::scalarIntSafe('SELECT COUNT(*) AS c FROM users'),
            'visits_today' => self::scalarIntSafe('SELECT COUNT(*) AS c FROM visit_requests WHERE DATE(scheduled_start) = CURRENT_DATE'),
            'pending_approvals' => self::scalarIntSafe(
                "
                SELECT COUNT(*) AS c
                FROM pal_profiles pp
                JOIN users u ON u.User_ID = pp.User_ID
                WHERE u.role_type='Pal'
                  AND COALESCE(LOWER(TRIM(pp.verification_status)),'') <> 'approved'
                  AND COALESCE(LOWER(TRIM(u.account_status)),'') IN ('pending','active','inactive')
                "
            ),
            'active_emergencies' => self::scalarIntSafe(
                "
                SELECT COUNT(*) AS c
                FROM emergency_threads
                WHERE COALESCE(LOWER(TRIM(status)),'') IN ('open','active','critical')
                "
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function recentNotifications(int $limit = 10): array {
        $limit = max(1, min(40, $limit));
        try {
            $stmt = self::db()->prepare('SELECT notification_ID AS id, User_ID AS user_id, type, title, message_body FROM notifications ORDER BY notification_ID DESC LIMIT ' . $limit);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function scalarIntSafe(string $sql): int {
        try {
            $stmt = self::db()->query($sql);
            if (!$stmt) {
                return 0;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
