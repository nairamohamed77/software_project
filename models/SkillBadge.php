<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Notification.php';

final class SkillBadge {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    public static function pendingCount(): int {
        $stmt = self::db()->query(
            "SELECT COUNT(*) AS c FROM skill_badges WHERE verification_status = 'Pending'"
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return (int) ($row['c'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public static function pendingForAdmin(int $limit = 120): array {
        $lim = max(1, min(200, $limit));
        $stmt = self::db()->prepare(
            '
            SELECT sb.badge_ID,
                   sb.pal_ID,
                   sb.badge_name,
                   sb.description,
                   sb.certificate_url,
                   sb.verification_status,
                   sb.created_at,
                   u.User_ID AS user_id,
                   u.Fname AS pal_fname,
                   u.Lname AS pal_lname,
                   u.email AS pal_email,
                   pp.verification_status AS pal_profile_status
            FROM skill_badges sb
            INNER JOIN pal_profiles pp ON sb.pal_ID = pp.pal_ID
            INNER JOIN users u ON pp.User_ID = u.User_ID
            WHERE sb.verification_status = \'Pending\'
            ORDER BY sb.badge_ID DESC
            LIMIT ' . $lim
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function verify(int $badgeId, int $adminUserId): void {
        $stmt = self::db()->prepare(
            '
            SELECT sb.badge_ID, sb.badge_name, sb.verification_status, pp.User_ID AS user_id
            FROM skill_badges sb
            INNER JOIN pal_profiles pp ON sb.pal_ID = pp.pal_ID
            WHERE sb.badge_ID = ?
            LIMIT 1
            '
        );
        $stmt->execute([$badgeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) ($row['verification_status'] ?? '') !== 'Pending') {
            throw new RuntimeException('Badge not found or already reviewed.');
        }

        $uid = (int) ($row['user_id'] ?? 0);
        $bname = trim((string) ($row['badge_name'] ?? ''));
        if ($bname === '') {
            $bname = 'Skill badge';
        }

        $up = self::db()->prepare(
            "
            UPDATE skill_badges
            SET verification_status = 'Verified',
                verified_by = ?,
                issued_at = COALESCE(issued_at, NOW())
            WHERE badge_ID = ?
              AND verification_status = 'Pending'
            LIMIT 1
            "
        );
        $up->execute([$adminUserId > 0 ? $adminUserId : null, $badgeId]);
        if ($up->rowCount() === 0) {
            throw new RuntimeException('Could not verify badge.');
        }

        Notification::enqueue(
            $uid,
            'Badge_Awarded',
            'Skill badge verified',
            'Your skill badge “' . $bname . '” has been verified by CareNest.'
        );
    }

    public static function reject(int $badgeId, string $reason): void {
        $reason = trim(substr($reason, 0, 2000));
        if ($reason === '') {
            throw new InvalidArgumentException('Reason required.');
        }

        $stmt = self::db()->prepare(
            '
            SELECT sb.badge_ID, sb.badge_name, sb.verification_status, pp.User_ID AS user_id
            FROM skill_badges sb
            INNER JOIN pal_profiles pp ON sb.pal_ID = pp.pal_ID
            WHERE sb.badge_ID = ?
            LIMIT 1
            '
        );
        $stmt->execute([$badgeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) ($row['verification_status'] ?? '') !== 'Pending') {
            throw new RuntimeException('Badge not found or already reviewed.');
        }

        $uid = (int) ($row['user_id'] ?? 0);
        $bname = trim((string) ($row['badge_name'] ?? ''));
        if ($bname === '') {
            $bname = 'Skill badge';
        }

        $up = self::db()->prepare(
            "
            UPDATE skill_badges
            SET verification_status = 'Rejected',
                verified_by = NULL
            WHERE badge_ID = ?
              AND verification_status = 'Pending'
            LIMIT 1
            "
        );
        $up->execute([$badgeId]);
        if ($up->rowCount() === 0) {
            throw new RuntimeException('Could not reject badge.');
        }

        Notification::enqueue(
            $uid,
            'System',
            'Skill badge not approved',
            'Regarding “' . $bname . '”: ' . $reason
        );
    }
}
