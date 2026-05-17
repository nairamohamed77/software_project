<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/FamilyProxy.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/SystemAudit.php';

/**
 * Inactive senior welfare monitoring (system-triggered welfare_checks + notifications + audit).
 */
final class WelfareInactivity {
    /** Days without login or qualifying visit activity before an alert is raised. */
    public const DEFAULT_INACTIVITY_DAYS = 7;

    private const TRIGGER_PREFIX = 'Inactive user welfare:';

    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }

        return self::$db;
    }

    /** @return list<array<string,mixed>> */
    public static function findSeniorsInactiveForDays(int $days): array {
        $days = max(1, min(90, $days));
        $sql = '
            SELECT sp.senior_ID,
                   sp.User_ID AS senior_user_ID,
                   COALESCE(u.last_login, \'1970-01-01 00:00:00\') AS last_login,
                   CONCAT(IFNULL(u.Fname,\'\'),\' \',IFNULL(u.Lname,\'\')) AS senior_name
            FROM senior_profiles sp
            INNER JOIN users u ON sp.User_ID = u.User_ID
            WHERE COALESCE(u.is_active, 0) = 1
              AND LOWER(TRIM(COALESCE(u.account_status, \'\'))) = \'active\'
              AND LOWER(TRIM(COALESCE(u.role_type, \'\'))) = \'senior\'
              AND (
                    u.last_login IS NULL
                    OR u.last_login < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                  )
              AND NOT EXISTS (
                    SELECT 1 FROM visit_requests vr
                    WHERE vr.senior_ID = sp.senior_ID
                      AND (
                            vr.created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                         OR vr.updated_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                         OR vr.scheduled_start >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                      )
                  )
        ';
        $stmt = self::db()->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function hasOpenInactivityCase(int $seniorId): bool {
        $stmt = self::db()->prepare(
            "SELECT COUNT(*) AS c FROM welfare_checks
             WHERE senior_ID = ?
               AND status IN ('Pending','Contacted','Escalated')
               AND trigger_reason LIKE ?"
        );
        $stmt->execute([$seniorId, self::TRIGGER_PREFIX . '%']);

        return (int) ($stmt->fetch()['c'] ?? 0) > 0;
    }

    private static function notifyAdmins(string $title, string $body): void {
        try {
            $q = self::db()->query("SELECT User_ID FROM users WHERE role_type = 'Admin' AND COALESCE(is_active,0) = 1");
            foreach (($q ? $q->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                $uid = (int) ($r['User_ID'] ?? 0);
                if ($uid > 0) {
                    Notification::enqueue($uid, 'Welfare_Check', $title, $body);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Scans seniors; creates welfare_checks rows, notifies proxy + admins, writes audit.
     *
     * @return array{created:int, skipped_open:int, scanned:int}
     */
    public static function runScan(int $days = self::DEFAULT_INACTIVITY_DAYS): array {
        SystemAudit::ensureTable();
        $days = max(1, min(90, $days));
        $rows = self::findSeniorsInactiveForDays($days);
        $created = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $sid = (int) ($row['senior_ID'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            if (self::hasOpenInactivityCase($sid)) {
                ++$skipped;
                continue;
            }
            $reason = self::TRIGGER_PREFIX . ' no login or qualifying visit activity in ' . $days . ' day(s)';
            $stmt = self::db()->prepare(
                'INSERT INTO welfare_checks (senior_ID, triggered_by, trigger_reason, status) VALUES (?,?,?, \'Pending\')'
            );
            $stmt->execute([$sid, 'System', substr($reason, 0, 255)]);
            $checkId = (int) self::db()->lastInsertId();
            ++$created;

            $name = trim((string) ($row['senior_name'] ?? ''));
            if ($name === '') {
                $name = 'Senior #' . $sid;
            }
            $title = 'Welfare alert — inactive senior';
            $body = $name . ' (senior_ID ' . $sid . ') has had no login and no recent visit activity for at least ' . $days . ' days. Please follow up. Welfare check #' . $checkId . '.';

            foreach (FamilyProxy::proxiesLinkedToSeniorSeniorId($sid) as $px) {
                Notification::enqueue((int) $px, 'Welfare_Check', $title, $body);
            }
            self::notifyAdmins($title, $body);

            SystemAudit::record(
                null,
                'WELFARE_INACTIVITY_ALERT',
                'welfare_check',
                $checkId,
                'senior_ID=' . $sid . ' days_threshold=' . $days,
                null
            );
        }

        if ($created > 0) {
            SystemAudit::record(
                null,
                'WELFARE_INACTIVITY_SCAN',
                null,
                null,
                'days=' . $days . ' candidates=' . count($rows) . ' created=' . $created . ' skipped_open=' . $skipped,
                null
            );
        }

        return ['created' => $created, 'skipped_open' => $skipped, 'scanned' => count($rows)];
    }

    /** @return list<array<string,mixed>> */
    public static function listForAdmin(int $limit = 100): array {
        $lim = max(1, min(200, $limit));
        $stmt = self::db()->query(
            '
            SELECT w.*,
                   CONCAT(IFNULL(su.Fname,\'\'),\' \',IFNULL(su.Lname,\'\')) AS senior_name,
                   su.email AS senior_email,
                   su.last_login AS senior_last_login,
                   CONCAT(IFNULL(au.Fname,\'\'),\' \',IFNULL(au.Lname,\'\')) AS checked_by_name
            FROM welfare_checks w
            INNER JOIN senior_profiles sp ON w.senior_ID = sp.senior_ID
            INNER JOIN users su ON sp.User_ID = su.User_ID
            LEFT JOIN users au ON w.checked_by = au.User_ID
            ORDER BY w.check_ID DESC
            LIMIT ' . $lim
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function countOpenCases(): int {
        try {
            $stmt = self::db()->query(
                "SELECT COUNT(*) AS c FROM welfare_checks WHERE status IN ('Pending','Contacted','Escalated')"
            );
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function updateStatus(int $checkId, int $adminUserId, string $newStatus, string $notes): void {
        $allowed = ['Pending', 'Contacted', 'Resolved', 'Escalated'];
        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        $notes = trim($notes);
        if ($notes === '' && ($newStatus === 'Resolved' || $newStatus === 'Escalated')) {
            throw new InvalidArgumentException('Please add notes for this outcome.');
        }
        $stmt = self::db()->prepare('SELECT check_ID FROM welfare_checks WHERE check_ID = ? LIMIT 1');
        $stmt->execute([$checkId]);
        if (!(int) $stmt->fetchColumn()) {
            throw new RuntimeException('Welfare check not found.');
        }
        $sql = 'UPDATE welfare_checks SET status = ?, resolution_notes = ?, checked_by = ?';
        if ($newStatus === 'Resolved' || $newStatus === 'Escalated') {
            $sql .= ', resolved_at = NOW()';
        } else {
            $sql .= ', resolved_at = NULL';
        }
        $sql .= ' WHERE check_ID = ? LIMIT 1';
        $upd = self::db()->prepare($sql);
        $upd->execute([$newStatus, $notes !== '' ? substr($notes, 0, 8000) : null, $adminUserId, $checkId]);
        SystemAudit::record(
            $adminUserId,
            'WELFARE_CHECK_STATUS',
            'welfare_check',
            $checkId,
            'status=' . $newStatus . ($notes !== '' ? ' | ' . substr($notes, 0, 500) : '')
        );
    }
}
