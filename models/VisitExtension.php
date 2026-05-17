<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Visit.php';
require_once __DIR__ . '/Pal.php';
require_once __DIR__ . '/Senior.php';
require_once __DIR__ . '/FamilyProxy.php';
require_once __DIR__ . '/Points.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/SystemAudit.php';

final class VisitExtension {
    private static PDO $db;
    private static bool $tablesReady = false;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    public static function ensureTables(): void {
        if (self::$tablesReady) {
            return;
        }
        SystemAudit::ensureTable();
        try {
            self::db()->exec(
                'CREATE TABLE IF NOT EXISTS visit_extension_requests (
                    request_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    visit_ID INT UNSIGNED NOT NULL,
                    pal_ID INT UNSIGNED NOT NULL,
                    extra_minutes INT UNSIGNED NOT NULL,
                    extra_points INT UNSIGNED NOT NULL,
                    status ENUM(\'Pending\', \'Approved\', \'Rejected\') NOT NULL DEFAULT \'Pending\',
                    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    resolved_at TIMESTAMP NULL DEFAULT NULL,
                    resolved_by_User_ID INT UNSIGNED DEFAULT NULL,
                    reject_reason VARCHAR(500) DEFAULT NULL,
                    KEY idx_ext_visit (visit_ID),
                    KEY idx_ext_status (status),
                    CONSTRAINT fk_ext_visit FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE CASCADE,
                    CONSTRAINT fk_ext_pal FOREIGN KEY (pal_ID) REFERENCES pal_profiles(pal_ID) ON DELETE CASCADE,
                    CONSTRAINT fk_ext_resolver FOREIGN KEY (resolved_by_User_ID) REFERENCES users(User_ID) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (\Throwable $e) {
            self::db()->exec(
                'CREATE TABLE IF NOT EXISTS visit_extension_requests (
                    request_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    visit_ID INT UNSIGNED NOT NULL,
                    pal_ID INT UNSIGNED NOT NULL,
                    extra_minutes INT UNSIGNED NOT NULL,
                    extra_points INT UNSIGNED NOT NULL,
                    status ENUM(\'Pending\', \'Approved\', \'Rejected\') NOT NULL DEFAULT \'Pending\',
                    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    resolved_at TIMESTAMP NULL DEFAULT NULL,
                    resolved_by_User_ID INT UNSIGNED DEFAULT NULL,
                    reject_reason VARCHAR(500) DEFAULT NULL,
                    KEY idx_ext_visit (visit_ID),
                    KEY idx_ext_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
        self::$tablesReady = true;
    }

    /**
     * Pure formula: SilverPoints for extra visit time from category hourly rate (visit extension UC).
     * Tests cover this without a database.
     */
    public static function computeExtraSilverPoints(int $costPerHour, int $extraMinutes): int {
        $cph = max(1, $costPerHour);
        $hours = max(0, $extraMinutes) / 60.0;
        $raw = (int) ceil($hours * $cph);

        return max(1, $raw);
    }

    /**
     * Pal-requested extension minutes: clamp 15–240 and round up to nearest 15 minutes.
     */
    public static function normalizeExtensionMinutesInput(int $minutes): int {
        return max(15, min(240, (int) (ceil($minutes / 15) * 15)));
    }

    public static function extraPointsForMinutes(int $categoryId, int $extraMinutes): int {
        $stmt = self::db()->prepare('SELECT COALESCE(cost_per_extra_hour, 5) AS cph FROM service_categories WHERE category_ID = ? LIMIT 1');
        $stmt->execute([$categoryId]);
        $cph = (int) ($stmt->fetch()['cph'] ?? 5);

        return self::computeExtraSilverPoints($cph, $extraMinutes);
    }

    public static function hasPendingForVisit(int $visitId): bool {
        self::ensureTables();
        $stmt = self::db()->prepare("SELECT COUNT(*) AS c FROM visit_extension_requests WHERE visit_ID = ? AND status = 'Pending'");
        $stmt->execute([$visitId]);

        return (int) ($stmt->fetch()['c'] ?? 0) > 0;
    }

    public static function request(int $visitId, int $palPalId, int $extraMinutes, int $palUserId): int {
        self::ensureTables();
        $extraMinutes = self::normalizeExtensionMinutesInput($extraMinutes);
        $v = Visit::byId($visitId);
        if (!$v || (int) ($v['pal_ID'] ?? 0) !== $palPalId) {
            throw new RuntimeException('Invalid visit.');
        }
        $st = strtolower(trim(str_replace('_', '-', (string) ($v['status'] ?? ''))));
        if ($st !== 'live') {
            throw new RuntimeException('Extensions are only available during an active (Live) visit after check-in.');
        }
        if (self::hasPendingForVisit($visitId)) {
            throw new RuntimeException('An extension request is already pending for this visit.');
        }
        $catId = (int) ($v['category_ID'] ?? 0);
        $cost = self::extraPointsForMinutes($catId, $extraMinutes);
        $seniorId = (int) ($v['senior_ID'] ?? 0);
        $bal = Senior::pointsBalance($seniorId);
        if ($bal < $cost) {
            throw new RuntimeException('The senior household does not have enough SilverPoints for this extension (' . $cost . ' required, ' . $bal . ' available).');
        }

        $stmt = self::db()->prepare(
            'INSERT INTO visit_extension_requests (visit_ID, pal_ID, extra_minutes, extra_points, status) VALUES (?,?,?,?,\'Pending\')'
        );
        $stmt->execute([$visitId, $palPalId, $extraMinutes, $cost]);
        $rid = (int) self::db()->lastInsertId();

        SystemAudit::record($palUserId, 'VISIT_EXTENSION_REQUESTED', 'visit_extension', $rid, 'visit=' . $visitId . ' minutes=' . $extraMinutes . ' est_points=' . $cost);

        $title = 'Visit extension requested';
        $body = 'Your Pal requested +' . $extraMinutes . ' min for visit #' . $visitId . ' (about ' . $cost . ' SilverPoints). Approve or decline in Extensions.';
        $su = Senior::seniorUserIdFromSeniorRow($seniorId);
        if ($su !== null) {
            Notification::enqueue($su, 'System', $title, $body);
        }
        foreach (FamilyProxy::proxiesLinkedToSeniorSeniorId($seniorId) as $px) {
            Notification::enqueue((int) $px, 'System', $title, $body);
        }

        return $rid;
    }

    /** @return list<array<string,mixed>> */
    public static function listPendingForSeniorHousehold(int $actingUserId, string $role): array {
        self::ensureTables();
        if ($role === 'Senior') {
            $sp = Senior::profileByUserId($actingUserId);
            if (!$sp) {
                return [];
            }
            $sid = (int) ($sp['senior_ID'] ?? 0);
            $stmt = self::db()->prepare(
                "
                SELECT er.*,
                       vr.scheduled_start, vr.scheduled_end, vr.status AS visit_status,
                       sc.category_name,
                       CONCAT(IFNULL(pu.Fname,''),' ',IFNULL(pu.Lname,'')) AS pal_name
                FROM visit_extension_requests er
                INNER JOIN visit_requests vr ON er.visit_ID = vr.visit_ID
                INNER JOIN pal_profiles pp ON er.pal_ID = pp.pal_ID
                INNER JOIN users pu ON pp.User_ID = pu.User_ID
                INNER JOIN service_categories sc ON vr.category_ID = sc.category_ID
                WHERE er.status = 'Pending' AND vr.senior_ID = ?
                ORDER BY er.requested_at DESC
                LIMIT 50
                "
            );
            $stmt->execute([$sid]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($role === 'FamilyProxy') {
            $ids = FamilyProxy::seniorsForProxyUser($actingUserId);
            if ($ids === []) {
                return [];
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = self::db()->prepare(
                "
                SELECT er.*,
                       vr.scheduled_start, vr.scheduled_end, vr.status AS visit_status, vr.senior_ID,
                       sc.category_name,
                       CONCAT(IFNULL(pu.Fname,''),' ',IFNULL(pu.Lname,'')) AS pal_name,
                       CONCAT(IFNULL(su.Fname,''),' ',IFNULL(su.Lname,'')) AS senior_name
                FROM visit_extension_requests er
                INNER JOIN visit_requests vr ON er.visit_ID = vr.visit_ID
                INNER JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
                INNER JOIN users su ON sp.User_ID = su.User_ID
                INNER JOIN pal_profiles pp ON er.pal_ID = pp.pal_ID
                INNER JOIN users pu ON pp.User_ID = pu.User_ID
                INNER JOIN service_categories sc ON vr.category_ID = sc.category_ID
                WHERE er.status = 'Pending' AND vr.senior_ID IN ($ph)
                ORDER BY er.requested_at DESC
                LIMIT 50
                "
            );
            $stmt->execute($ids);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return [];
    }

    public static function approve(int $requestId, int $actingUserId, string $role): void {
        self::ensureTables();
        $stmt = self::db()->prepare('SELECT * FROM visit_extension_requests WHERE request_ID = ? AND status = \'Pending\' LIMIT 1');
        $stmt->execute([$requestId]);
        $er = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$er) {
            throw new RuntimeException('Request not found or already handled.');
        }
        $visitId = (int) ($er['visit_ID'] ?? 0);
        $v = Visit::byId($visitId);
        if (!$v) {
            throw new RuntimeException('Visit missing.');
        }
        $seniorId = (int) ($v['senior_ID'] ?? 0);
        if ($role === 'Senior') {
            $sp = Senior::profileByUserId($actingUserId);
            if (!$sp || (int) ($sp['senior_ID'] ?? 0) !== $seniorId) {
                throw new RuntimeException('Not authorized.');
            }
        } elseif ($role === 'FamilyProxy') {
            if (!FamilyProxy::proxyCanManageSenior($actingUserId, $seniorId)) {
                throw new RuntimeException('Not authorized.');
            }
        } else {
            throw new RuntimeException('Not authorized.');
        }

        $catId = (int) ($v['category_ID'] ?? 0);
        $extraMin = (int) ($er['extra_minutes'] ?? 0);
        $costStored = (int) ($er['extra_points'] ?? 0);
        $costRecalc = self::extraPointsForMinutes($catId, $extraMin);
        $cost = $costStored > 0 ? $costStored : $costRecalc;
        $seniorUserId = Senior::seniorUserIdFromSeniorRow($seniorId);
        if ($seniorUserId === null) {
            throw new RuntimeException('Senior user not found.');
        }

        self::db()->beginTransaction();
        try {
            $stmtV = self::db()->prepare('SELECT * FROM visit_requests WHERE visit_ID = ? FOR UPDATE');
            $stmtV->execute([$visitId]);
            $v2 = $stmtV->fetch(PDO::FETCH_ASSOC);
            if (!$v2 || strtolower(trim(str_replace('_', '-', (string) ($v2['status'] ?? '')))) !== 'live') {
                self::db()->rollBack();
                throw new RuntimeException('Visit is no longer active; extension cannot be approved.');
            }
            if (Senior::pointsBalance($seniorId) < $cost) {
                self::db()->rollBack();
                throw new RuntimeException('Insufficient SilverPoints to approve this extension now.');
            }

            $stmtUp = self::db()->prepare(
                'UPDATE visit_requests SET
                    scheduled_end = DATE_ADD(scheduled_end, INTERVAL ? MINUTE),
                    points_reserved = COALESCE(points_reserved,0) + ?,
                    is_extended = 1,
                    extension_minutes = COALESCE(extension_minutes,0) + ?
                 WHERE visit_ID = ? LIMIT 1'
            );
            $stmtUp->execute([$extraMin, $cost, $extraMin, $visitId]);

            try {
                self::db()->prepare(
                    "UPDATE escrow SET points_locked = COALESCE(points_locked,0) + ? WHERE visit_ID = ? AND status IN ('Locked','In_Mission')"
                )->execute([$cost, $visitId]);
            } catch (\Throwable $e) {
            }

            Senior::adjustPoints($seniorId, -$cost);
            $balAfter = Senior::pointsBalance($seniorId);
            Points::recordLedger(
                $seniorUserId,
                $visitId,
                'Booking_Reserve',
                -$cost,
                max(0, $balAfter),
                'Visit extension approved (request #' . $requestId . ') +' . $extraMin . ' min'
            );

            $updExt = self::db()->prepare(
                "UPDATE visit_extension_requests SET status='Approved', resolved_at=NOW(), resolved_by_User_ID=? WHERE request_ID=? AND status='Pending'"
            );
            $updExt->execute([$actingUserId, $requestId]);
            if ($updExt->rowCount() === 0) {
                self::db()->rollBack();
                throw new RuntimeException('Could not finalize extension request.');
            }

            self::db()->commit();
        } catch (\Throwable $e) {
            if (self::db()->inTransaction()) {
                self::db()->rollBack();
            }
            throw $e;
        }

        SystemAudit::record($actingUserId, 'VISIT_EXTENSION_APPROVED', 'visit_extension', $requestId, 'visit=' . $visitId . ' cost=' . $cost);

        $pal = Pal::profileByPalId((int) ($er['pal_ID'] ?? 0));
        $puid = (int) ($pal['User_ID'] ?? 0);
        if ($puid > 0) {
            Notification::enqueue($puid, 'System', 'Extension approved', 'Visit #' . $visitId . ': +' . $extraMin . ' minutes. ' . $cost . ' SilverPoints were reserved from the senior household.');
        }
    }

    public static function reject(int $requestId, int $actingUserId, string $role, string $reason): void {
        self::ensureTables();
        $reason = trim(substr($reason, 0, 500));
        if ($reason === '') {
            throw new InvalidArgumentException('Please give a short reason for declining.');
        }
        $stmt = self::db()->prepare('SELECT * FROM visit_extension_requests WHERE request_ID = ? AND status = \'Pending\' LIMIT 1');
        $stmt->execute([$requestId]);
        $er = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$er) {
            throw new RuntimeException('Request not found or already handled.');
        }
        $visitId = (int) ($er['visit_ID'] ?? 0);
        $v = Visit::byId($visitId);
        if (!$v) {
            throw new RuntimeException('Visit missing.');
        }
        $seniorId = (int) ($v['senior_ID'] ?? 0);
        if ($role === 'Senior') {
            $sp = Senior::profileByUserId($actingUserId);
            if (!$sp || (int) ($sp['senior_ID'] ?? 0) !== $seniorId) {
                throw new RuntimeException('Not authorized.');
            }
        } elseif ($role === 'FamilyProxy') {
            if (!FamilyProxy::proxyCanManageSenior($actingUserId, $seniorId)) {
                throw new RuntimeException('Not authorized.');
            }
        } else {
            throw new RuntimeException('Not authorized.');
        }

        $stmt = self::db()->prepare(
            "UPDATE visit_extension_requests SET status='Rejected', resolved_at=NOW(), resolved_by_User_ID=?, reject_reason=? WHERE request_ID=? AND status='Pending'"
        );
        $stmt->execute([$actingUserId, $reason, $requestId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Could not reject request.');
        }

        SystemAudit::record($actingUserId, 'VISIT_EXTENSION_REJECTED', 'visit_extension', $requestId, $reason);

        $pal = Pal::profileByPalId((int) ($er['pal_ID'] ?? 0));
        $puid = (int) ($pal['User_ID'] ?? 0);
        if ($puid > 0) {
            Notification::enqueue($puid, 'System', 'Extension declined', 'Visit #' . $visitId . ': ' . $reason);
        }
    }
}
