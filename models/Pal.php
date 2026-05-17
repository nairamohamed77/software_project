<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class Pal {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /** @return array<string,mixed>|null */
    public static function profileByPalId(int $palId): ?array {
        $stmt = self::db()->prepare('SELECT * FROM pal_profiles WHERE pal_ID = ? LIMIT 1');
        $stmt->execute([$palId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function profileByUserId(int $userId): ?array {
        $stmt = self::db()->prepare('SELECT * FROM pal_profiles WHERE User_ID = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function setAvailability(int $palId, int $flag): bool {
        $stmt = self::db()->prepare('UPDATE pal_profiles SET is_available = ? WHERE pal_ID = ?');
        return $stmt->execute([(int) !!$flag, $palId]);
    }

    public static function visitsCompletedTotal(int $palId): int {
        $stmt = self::db()->prepare("SELECT COUNT(*) AS c FROM visit_requests WHERE pal_ID = ? AND LOWER(TRIM(status)) = 'completed'");
        $stmt->execute([$palId]);
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    public static function pointsEarnedThisMonth(int $userId): int {
        $stmt = self::db()->prepare("SELECT COALESCE(SUM(points_amount),0) AS s FROM silverpoints_ledger WHERE User_ID = ? AND entry_type = 'Visit_Payment' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        $stmt->execute([$userId]);
        return (int) ($stmt->fetch()['s'] ?? 0);
    }

    public static function pendingRequestCount(int $palId): int {
        $stmt = self::db()->prepare("SELECT COUNT(*) AS c FROM visit_requests WHERE pal_ID = ? AND LOWER(TRIM(status)) = 'pending'");
        $stmt->execute([$palId]);
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    public static function hasActiveAssignment(int $palId): bool {
        $stmt = self::db()->prepare(
            "SELECT 1
             FROM visit_requests
             WHERE pal_ID = ?
               AND LOWER(TRIM(REPLACE(status, '_', '-'))) IN ('live', 'en-route')
             LIMIT 1"
        );
        $stmt->execute([$palId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public static function pendingTableRows(int $palId): array {
        $sql = "SELECT vr.*, u.Fname, u.Lname, sc.category_name
            FROM visit_requests vr
            JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
            JOIN users u ON sp.User_ID = u.User_ID
            JOIN service_categories sc ON vr.category_ID = sc.category_ID
            WHERE vr.pal_ID = ? AND LOWER(TRIM(vr.status)) = 'pending'
            ORDER BY vr.scheduled_start ASC";
        $stmt = self::db()->prepare($sql);
        $stmt->execute([$palId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public static function todayScheduleRows(int $palId): array {
        $sql = "SELECT vr.*, u.Fname, u.Lname, sc.category_name,
                e.points_locked AS escrow_points, e.status AS escrow_status
            FROM visit_requests vr
            JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
            JOIN users u ON sp.User_ID = u.User_ID
            JOIN service_categories sc ON vr.category_ID = sc.category_ID
            LEFT JOIN escrow e ON e.visit_ID = vr.visit_ID
            WHERE vr.pal_ID = ?
              AND DATE(vr.scheduled_start) = CURDATE()
              AND vr.status IN ('Accepted','Live','En_Route')
            ORDER BY vr.scheduled_start ASC";
        $stmt = self::db()->prepare($sql);
        $stmt->execute([$palId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<string> */
    public static function verifiedBadgeNames(int $palId): array {
        $badges = [];
        try {
            $bq = self::db()->prepare(
                "SELECT badge_name FROM skill_badges WHERE pal_ID = ? AND LOWER(TRIM(verification_status)) IN ('verified','approved') LIMIT 25"
            );
            $bq->execute([$palId]);
            foreach (($bq->fetchAll(PDO::FETCH_ASSOC)) ?: [] as $b) {
                if (!empty($b['badge_name'])) {
                    $badges[] = (string) $b['badge_name'];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $badges;
    }

    /**
     * Pals available for booking pickers.
     * Includes non-rejected profiles; exposes flags for UI disabling.
     *
     * @return list<array<string,mixed>>
     */
    public static function catalogForBookingWithBadges(): array {
        $sql = "
            SELECT pp.pal_ID, pp.travel_radius_km, pp.rating_avg, pp.verification_status,
                   COALESCE(pp.is_available, 0) AS is_available,
                   u.User_ID AS user_ID, u.Fname, u.Lname, u.account_status, u.is_active AS user_is_active,
                   EXISTS(
                       SELECT 1
                       FROM visit_requests vr
                       WHERE vr.pal_ID = pp.pal_ID
                         AND LOWER(TRIM(REPLACE(vr.status, '_', '-'))) IN ('accepted', 'live', 'en-route')
                       LIMIT 1
                   ) AS has_active_assignment
            FROM pal_profiles pp
            INNER JOIN users u ON u.User_ID = pp.User_ID AND u.role_type = 'Pal'
            WHERE LOWER(TRIM(COALESCE(pp.verification_status, ''))) <> 'rejected'
              AND LOWER(TRIM(COALESCE(u.account_status, ''))) NOT IN ('suspended', 'banned')
            ORDER BY
              (LOWER(TRIM(COALESCE(pp.verification_status, ''))) = 'approved') DESC,
              (LOWER(TRIM(COALESCE(u.account_status, ''))) = 'active' AND COALESCE(u.is_active, 0) = 1) DESC,
              COALESCE(pp.is_available, 0) DESC,
              COALESCE(pp.rating_avg, 0) DESC
        ";
        try {
            $stmt = self::db()->query($sql);
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (\Throwable $e) {
            $rows = [];
        }
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['pal_ID'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $out[] = [
                'pal_ID' => $pid,
                'user_ID' => (int) ($r['user_ID'] ?? 0),
                'Fname' => (string) ($r['Fname'] ?? ''),
                'Lname' => (string) ($r['Lname'] ?? ''),
                'rating_avg' => (float) ($r['rating_avg'] ?? 0),
                'travel_radius_km' => (int) ($r['travel_radius_km'] ?? 0),
                'is_available' => (int) ($r['is_available'] ?? 0),
                'verification_status' => (string) ($r['verification_status'] ?? ''),
                'account_status' => (string) ($r['account_status'] ?? ''),
                'user_is_active' => (int) ($r['user_is_active'] ?? 0),
                'has_active_assignment' => (int) ($r['has_active_assignment'] ?? 0),
                'badges' => self::verifiedBadgeNames($pid),
            ];
        }

        return $out;
    }

    /** Human-readable line for booking dropdowns (English, short). */
    public static function palPickerOptionLabel(array $p): string {
        $nm = trim((string) ($p['Fname'] ?? '') . ' ' . (string) ($p['Lname'] ?? ''));
        if ($nm === '') {
            $nm = 'Pal #' . (int) ($p['pal_ID'] ?? 0);
        }
        $parts = [];
        $vs = strtolower(trim((string) ($p['verification_status'] ?? '')));
        if ($vs !== '' && $vs !== 'approved') {
            $parts[] = 'profile ' . $vs;
        }
        $as = strtolower(trim((string) ($p['account_status'] ?? '')));
        $ua = (int) ($p['user_is_active'] ?? 0);
        if ($as !== 'active' || $ua !== 1) {
            $parts[] = 'activate account in admin to log in';
        }
        if (!(int) ($p['is_available'] ?? 0)) {
            $parts[] = 'calendar busy';
        }
        if ((int) ($p['has_active_assignment'] ?? 0) === 1) {
            $parts[] = 'pending task';
        }

        return $nm . ($parts !== [] ? ' · ' . implode(' · ', $parts) : '');
    }

    public static function canBeBookedNow(int $palId): bool {
        return !self::hasActiveAssignment($palId);
    }
}
