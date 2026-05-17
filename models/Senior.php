<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class Senior {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /** @return array<string,mixed>|null */
    public static function profileByUserId(int $userId): ?array {
        $stmt = self::db()->prepare('SELECT * FROM senior_profiles WHERE User_ID = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function healthBySeniorId(int $seniorId): ?array {
        $stmt = self::db()->prepare('SELECT * FROM health_records WHERE senior_ID = ? LIMIT 1');
        $stmt->execute([$seniorId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function totalVisits(int $seniorId): int {
        $stmt = self::db()->prepare('SELECT COUNT(*) AS c FROM visit_requests WHERE senior_ID = ?');
        $stmt->execute([$seniorId]);
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    public static function completedVisits(int $seniorId): int {
        $stmt = self::db()->prepare("SELECT COUNT(*) AS c FROM visit_requests WHERE senior_ID = ? AND LOWER(TRIM(status)) = 'completed'");
        $stmt->execute([$seniorId]);
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public static function upcomingVisits(int $seniorId, int $limit = 5): array {
        $limit = max(1, min(20, $limit));
        $sql = "SELECT vr.*,
                COALESCE(pe.Fname, '') AS Pal_Fname,
                COALESCE(pe.Lname, '') AS Pal_Lname,
                COALESCE(pe.User_ID, 0) AS Pal_User_ID,
                sc.category_name
            FROM visit_requests vr
            LEFT JOIN pal_profiles pp ON vr.pal_ID = pp.pal_ID
            LEFT JOIN users pe ON pp.User_ID = pe.User_ID
            LEFT JOIN service_categories sc ON vr.category_ID = sc.category_ID
            WHERE vr.senior_ID = ?
              AND COALESCE(TRIM(vr.status), '') NOT IN ('Completed', 'Cancelled', 'Rejected', 'No_Show')
            ORDER BY vr.scheduled_start ASC
            LIMIT {$limit}";
        $stmt = self::db()->prepare($sql);
        $stmt->execute([$seniorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function nextVisitSnippet(int $seniorId): string {
        $rows = self::upcomingVisits($seniorId, 1);
        if (!$rows) {
            return 'No upcoming visits.';
        }
        $v = $rows[0];
        $palName = trim((string) $v['Pal_Fname'] . ' ' . (string) $v['Pal_Lname']);
        if ($palName === '') {
            $palName = 'Pal TBD';
        }
        $when = (string) ($v['scheduled_start'] ?? '');
        $cat = (string) ($v['category_name'] ?? 'Service');
        return $cat . ' with ' . $palName . ' — ' . $when;
    }

    public static function adjustPoints(int $seniorId, int $delta): void {
        $stmt = self::db()->prepare('UPDATE senior_profiles SET points_balance = points_balance + ? WHERE senior_ID = ?');
        $stmt->execute([$delta, $seniorId]);
    }

    public static function pointsBalance(int $seniorId): int {
        $stmt = self::db()->prepare('SELECT points_balance FROM senior_profiles WHERE senior_ID = ? LIMIT 1');
        $stmt->execute([$seniorId]);
        return (int) ($stmt->fetch()['points_balance'] ?? 0);
    }

    public static function seniorUserIdFromSeniorRow(int $seniorId): ?int {
        $stmt = self::db()->prepare('SELECT User_ID FROM senior_profiles WHERE senior_ID = ? LIMIT 1');
        $stmt->execute([$seniorId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['User_ID'] : null;
    }
}
