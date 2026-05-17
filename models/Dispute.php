<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Visit.php';
require_once __DIR__ . '/Senior.php';
require_once __DIR__ . '/Pal.php';
require_once __DIR__ . '/FamilyProxy.php';
require_once __DIR__ . '/Points.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/SystemAudit.php';

final class Dispute {
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
        SystemAudit::dropLegacyDisputeSideTables();
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS disputes (
    dispute_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL,
    raised_by_User_ID INT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    evidence_url VARCHAR(500) DEFAULT NULL,
    status ENUM('Open', 'Awaiting_Info', 'Resolved') NOT NULL DEFAULT 'Open',
    resolution ENUM('Refund_Senior', 'Release_Pal') DEFAULT NULL,
    resolution_notes TEXT,
    resolved_by_User_ID INT UNSIGNED DEFAULT NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_dispute_status (status),
    KEY idx_dispute_visit (visit_ID),
    CONSTRAINT fk_disputes_visit FOREIGN KEY (visit_ID) REFERENCES visit_requests(visit_ID) ON DELETE CASCADE,
    CONSTRAINT fk_disputes_raised FOREIGN KEY (raised_by_User_ID) REFERENCES users(User_ID) ON DELETE CASCADE,
    CONSTRAINT fk_disputes_resolver FOREIGN KEY (resolved_by_User_ID) REFERENCES users(User_ID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
        try {
            self::db()->exec($sql);
        } catch (\Throwable $e) {
            // FK may fail on partial installs; retry minimal
            try {
                self::db()->exec(
                    'CREATE TABLE IF NOT EXISTS disputes (
                        dispute_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        visit_ID INT UNSIGNED NOT NULL,
                        raised_by_User_ID INT UNSIGNED NOT NULL,
                        description TEXT NOT NULL,
                        evidence_url VARCHAR(500) DEFAULT NULL,
                        status ENUM(\'Open\', \'Awaiting_Info\', \'Resolved\') NOT NULL DEFAULT \'Open\',
                        resolution ENUM(\'Refund_Senior\', \'Release_Pal\') DEFAULT NULL,
                        resolution_notes TEXT,
                        resolved_by_User_ID INT UNSIGNED DEFAULT NULL,
                        resolved_at TIMESTAMP NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        KEY idx_dispute_status (status),
                        KEY idx_dispute_visit (visit_ID)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } catch (\Throwable $e2) {
            }
        }

        self::$tablesReady = true;
    }

    private static function audit(int $disputeId, int $actorUserId, string $action, ?string $details): void {
        SystemAudit::record($actorUserId, $action, 'dispute', $disputeId, $details);
    }

    /** @return array<string,mixed>|null */
    public static function visitRow(int $visitId): ?array {
        $stmt = self::db()->prepare('SELECT * FROM visit_requests WHERE visit_ID = ? LIMIT 1');
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function userIsPartyToVisit(int $userId, string $role, array $visit): bool {
        $seniorId = (int) ($visit['senior_ID'] ?? 0);
        $palId = (int) ($visit['pal_ID'] ?? 0);
        if ($role === 'Senior') {
            $sp = Senior::profileByUserId($userId);

            return $sp !== null && (int) ($sp['senior_ID'] ?? 0) === $seniorId;
        }
        if ($role === 'Pal') {
            $pp = Pal::profileByUserId($userId);

            return $pp !== null && (int) ($pp['pal_ID'] ?? 0) === $palId;
        }
        if ($role === 'FamilyProxy') {
            return FamilyProxy::proxyCanManageSenior($userId, $seniorId);
        }

        return false;
    }

    public static function visitEligibleForDispute(array $visit): bool {
        $palId = (int) ($visit['pal_ID'] ?? 0);
        if ($palId <= 0) {
            return false;
        }
        $st = strtolower(trim(str_replace('_', '-', (string) ($visit['status'] ?? ''))));

        return in_array($st, ['accepted', 'en-route', 'live', 'completed', 'cancelled', 'rejected', 'no-show'], true);
    }

    public static function hasOpenDispute(int $visitId): bool {
        self::ensureTables();
        $stmt = self::db()->prepare(
            "SELECT COUNT(*) AS c FROM disputes WHERE visit_ID = ? AND status IN ('Open','Awaiting_Info')"
        );
        $stmt->execute([$visitId]);

        return (int) ($stmt->fetch()['c'] ?? 0) > 0;
    }

    public static function openDisputeIdForVisit(int $visitId): ?int {
        self::ensureTables();
        $stmt = self::db()->prepare(
            "SELECT dispute_ID FROM disputes WHERE visit_ID = ? AND status IN ('Open','Awaiting_Info') ORDER BY dispute_ID DESC LIMIT 1"
        );
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = (int) ($row['dispute_ID'] ?? 0);

        return $id > 0 ? $id : null;
    }

    public static function create(int $visitId, int $raisedByUserId, string $description, ?string $evidenceUrl): int {
        self::ensureTables();
        $visit = self::visitRow($visitId);
        if (!$visit) {
            throw new RuntimeException('Visit not found.');
        }
        if (!self::visitEligibleForDispute($visit)) {
            throw new RuntimeException('This visit cannot be disputed (needs an assigned Pal and an active or completed state).');
        }
        if (self::hasOpenDispute($visitId)) {
            throw new RuntimeException('A dispute is already open for this visit.');
        }
        $desc = trim($description);
        if (strlen($desc) < 10) {
            throw new RuntimeException('Please describe the issue in at least 10 characters.');
        }
        $stmt = self::db()->prepare(
            'INSERT INTO disputes (visit_ID, raised_by_User_ID, description, evidence_url, status) VALUES (?,?,?,?, \'Open\')'
        );
        $stmt->execute([
            $visitId,
            $raisedByUserId,
            substr($desc, 0, 12000),
            $evidenceUrl !== null && $evidenceUrl !== '' ? substr($evidenceUrl, 0, 500) : null,
        ]);
        $id = (int) self::db()->lastInsertId();
        self::audit($id, $raisedByUserId, 'DISPUTE_RAISED', 'visit_ID=' . $visitId);

        $title = 'Dispute opened';
        $body = 'A dispute was filed for visit #' . $visitId . ' (case #' . $id . '). An admin will review it.';
        self::notifyAdmins($title, $body);
        Notification::enqueue($raisedByUserId, 'System', $title, $body);

        return $id;
    }

    private static function notifyAdmins(string $title, string $body): void {
        try {
            $q = self::db()->query("SELECT User_ID FROM users WHERE role_type = 'Admin' AND COALESCE(is_active,0) = 1");
            foreach (($q ? $q->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                $uid = (int) ($r['User_ID'] ?? 0);
                if ($uid > 0) {
                    Notification::enqueue($uid, 'System', $title, $body);
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /** @return list<int> */
    private static function partyUserIdsForVisit(array $visit): array {
        $ids = [];
        $su = Senior::seniorUserIdFromSeniorRow((int) ($visit['senior_ID'] ?? 0));
        if ($su !== null) {
            $ids[] = $su;
        }
        $palId = (int) ($visit['pal_ID'] ?? 0);
        if ($palId > 0) {
            $pp = Pal::profileByPalId($palId);
            if ($pp) {
                $pu = (int) ($pp['User_ID'] ?? 0);
                if ($pu > 0) {
                    $ids[] = $pu;
                }
            }
        }
        foreach (FamilyProxy::proxiesLinkedToSeniorSeniorId((int) ($visit['senior_ID'] ?? 0)) as $px) {
            $ids[] = (int) $px;
        }

        return array_values(array_unique(array_filter($ids, static fn (int $x): bool => $x > 0)));
    }

    /** @return list<array<string,mixed>> */
    public static function listForAdmin(int $limit = 100): array {
        self::ensureTables();
        $lim = max(1, min(200, $limit));
        $stmt = self::db()->query(
            '
            SELECT d.*,
                   vr.status AS visit_status,
                   vr.points_reserved,
                   vr.points_paid,
                   vr.scheduled_start,
                   vr.task_details,
                   CONCAT(IFNULL(ru.Fname,\'\'),\' \',IFNULL(ru.Lname,\'\')) AS raised_by_name,
                   ru.email AS raised_by_email
            FROM disputes d
            INNER JOIN visit_requests vr ON d.visit_ID = vr.visit_ID
            INNER JOIN users ru ON d.raised_by_User_ID = ru.User_ID
            ORDER BY CASE d.status WHEN \'Open\' THEN 0 WHEN \'Awaiting_Info\' THEN 1 ELSE 2 END, d.dispute_ID DESC
            LIMIT ' . $lim
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string,mixed>|null */
    public static function findWithVisit(int $disputeId): ?array {
        self::ensureTables();
        $stmt = self::db()->prepare(
            '
            SELECT d.*,
                   vr.senior_ID, vr.pal_ID, vr.category_ID,
                   vr.status AS visit_status,
                   vr.scheduled_start, vr.scheduled_end,
                   vr.points_reserved, vr.points_paid,
                   vr.task_details, vr.special_instructions,
                   CONCAT(IFNULL(ru.Fname,\'\'),\' \',IFNULL(ru.Lname,\'\')) AS raised_by_name,
                   ru.email AS raised_by_email
            FROM disputes d
            INNER JOIN visit_requests vr ON d.visit_ID = vr.visit_ID
            INNER JOIN users ru ON d.raised_by_User_ID = ru.User_ID
            WHERE d.dispute_ID = ?
            LIMIT 1
            '
        );
        $stmt->execute([$disputeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function messages(int $disputeId): array {
        self::ensureTables();

        return [];
    }

    /** @return list<array<string,mixed>> */
    public static function auditLog(int $disputeId): array {
        self::ensureTables();

        return SystemAudit::forEntity('dispute', $disputeId, 200);
    }

    /** @return list<array<string,mixed>> */
    public static function emergencyMessagesForVisit(int $visitId): array {
        try {
            $stmt = self::db()->prepare(
                'SELECT em.message_text, em.message_type, em.created_at, em.sender_User_ID
                 FROM emergency_threads et
                 INNER JOIN emergency_messages em ON em.thread_ID = et.thread_ID
                 WHERE et.visit_ID = ?
                 ORDER BY em.message_ID ASC
                 LIMIT 200'
            );
            $stmt->execute([$visitId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function requestMoreInfo(int $disputeId, int $adminUserId, string $note): void {
        self::ensureTables();
        $note = trim($note);
        if (strlen($note) < 8) {
            throw new InvalidArgumentException('Please write a clear request (at least 8 characters).');
        }
        $d = self::findWithVisit($disputeId);
        if (!$d || !in_array((string) ($d['status'] ?? ''), ['Open', 'Awaiting_Info'], true)) {
            throw new RuntimeException('Dispute cannot be updated.');
        }
        $stmt = self::db()->prepare("UPDATE disputes SET status = 'Awaiting_Info' WHERE dispute_ID = ?");
        $stmt->execute([$disputeId]);
        self::audit($disputeId, $adminUserId, 'REQUEST_MORE_INFO', $note);

        $visitId = (int) ($d['visit_ID'] ?? 0);
        $title = 'Dispute #' . $disputeId . ' — more information needed';
        $body = 'An admin needs more details: ' . $note;
        foreach (self::partyUserIdsForVisit($d) as $uid) {
            Notification::enqueue($uid, 'System', $title, $body);
        }
    }

    public static function resolveReleasePal(int $disputeId, int $adminUserId, string $notes): void {
        self::ensureTables();
        $notes = trim($notes);
        if (strlen($notes) < 5) {
            throw new InvalidArgumentException('Please add resolution notes (at least 5 characters).');
        }
        $d = self::findWithVisit($disputeId);
        if (!$d || !in_array((string) ($d['status'] ?? ''), ['Open', 'Awaiting_Info'], true)) {
            throw new RuntimeException('Dispute cannot be resolved.');
        }
        $visitId = (int) ($d['visit_ID'] ?? 0);
        $palId = (int) ($d['pal_ID'] ?? 0);
        $pointsPaid = (int) ($d['points_paid'] ?? 0);
        $pointsReserved = (int) ($d['points_reserved'] ?? 0);

        self::db()->beginTransaction();
        try {
            // If Pal has not actually been paid yet, release the full reserved amount now.
            if ($pointsPaid <= 0 && $palId > 0 && $pointsReserved > 0) {
                self::db()->prepare(
                    "UPDATE pal_profiles SET points_balance = COALESCE(points_balance,0) + ? WHERE pal_ID = ?"
                )->execute([$pointsReserved, $palId]);
                self::db()->prepare(
                    "UPDATE visit_requests SET points_paid = ? WHERE visit_ID = ?"
                )->execute([$pointsReserved, $visitId]);
                try {
                    self::db()->prepare(
                        "UPDATE escrow SET status='Released_To_Pal', released_at=NOW() WHERE visit_ID = ? AND status IN ('Locked','In_Mission')"
                    )->execute([$visitId]);
                } catch (\Throwable $e) {
                }

                $palProf = Pal::profileByPalId($palId);
                $palUserId = (int) ($palProf['User_ID'] ?? 0);
                if ($palUserId > 0) {
                    $balStmt = self::db()->prepare('SELECT COALESCE(points_balance,0) AS b FROM pal_profiles WHERE pal_ID=? LIMIT 1');
                    $balStmt->execute([$palId]);
                    $balAfter = (int) ($balStmt->fetch()['b'] ?? 0);
                    Points::recordLedger(
                        $palUserId,
                        $visitId,
                        'Admin_Adjustment',
                        $pointsReserved,
                        max(0, $balAfter),
                        'Dispute #' . $disputeId . ' release to Pal — ' . substr($notes, 0, 200)
                    );
                }
            }

            $stmt = self::db()->prepare(
                "UPDATE disputes SET status='Resolved', resolution='Release_Pal', resolution_notes=?, resolved_by_User_ID=?, resolved_at=NOW() WHERE dispute_ID=? AND status IN ('Open','Awaiting_Info')"
            );
            $stmt->execute([substr($notes, 0, 8000), $adminUserId, $disputeId]);
            if ($stmt->rowCount() === 0) {
                self::db()->rollBack();
                throw new RuntimeException('Could not resolve dispute.');
            }
            self::audit($disputeId, $adminUserId, 'RESOLVED_RELEASE_PAL', $notes);
            self::db()->commit();
        } catch (\Throwable $e) {
            if (self::db()->inTransaction()) {
                self::db()->rollBack();
            }
            throw $e;
        }

        $title = 'Dispute resolved — Pal payment stands';
        $body = 'Case #' . $disputeId . ': ' . $notes;
        foreach (self::partyUserIdsForVisit($d) as $uid) {
            Notification::enqueue($uid, 'System', $title, $body);
        }
    }

    public static function resolveRefundSenior(int $disputeId, int $adminUserId, string $notes): void {
        self::ensureTables();
        $notes = trim($notes);
        if (strlen($notes) < 5) {
            throw new InvalidArgumentException('Please add resolution notes (at least 5 characters).');
        }
        $d = self::findWithVisit($disputeId);
        if (!$d || !in_array((string) ($d['status'] ?? ''), ['Open', 'Awaiting_Info'], true)) {
            throw new RuntimeException('Dispute cannot be resolved.');
        }
        $visitId = (int) ($d['visit_ID'] ?? 0);
        $seniorId = (int) ($d['senior_ID'] ?? 0);
        $palId = (int) ($d['pal_ID'] ?? 0);
        $pointsPaid = (int) ($d['points_paid'] ?? 0);
        $pointsReserved = (int) ($d['points_reserved'] ?? 0);
        $visitStatus = strtolower(trim(str_replace('_', '-', (string) ($d['visit_status'] ?? ''))));

        $seniorUserId = Senior::seniorUserIdFromSeniorRow($seniorId);
        if ($seniorUserId === null || $palId <= 0) {
            throw new RuntimeException('Cannot apply refund: missing senior or Pal on this visit.');
        }

        // Amount to move from Pal back to Senior: net paid to Pal when visit completed, capped by Pal balance and reserved points.
        $target = $pointsPaid > 0 ? $pointsPaid : max(0, $pointsReserved);
        if ($target <= 0 && $visitStatus === 'completed') {
            throw new RuntimeException('No recorded Pal payment to claw back; use “Release Pal” or document in notes.');
        }
        if ($target <= 0) {
            // e.g. cancelled before payout — refund up to reserved if still meaningful
            $target = max(0, $pointsReserved);
        }
        if ($target <= 0) {
            throw new RuntimeException('Nothing to refund in SilverPoints for this visit.');
        }

        self::db()->beginTransaction();
        try {
            $balPalStmt = self::db()->prepare('SELECT COALESCE(points_balance,0) AS b FROM pal_profiles WHERE pal_ID = ? FOR UPDATE');
            $balPalStmt->execute([$palId]);
            $palBal = (int) ($balPalStmt->fetch()['b'] ?? 0);
            $claw = min($target, $palBal);
            if ($claw <= 0) {
                self::db()->rollBack();
                throw new RuntimeException('Pal balance is zero; cannot claw back points. Escalate manually or choose “Release Pal”.');
            }

            self::db()->prepare('UPDATE pal_profiles SET points_balance = points_balance - ? WHERE pal_ID = ?')->execute([$claw, $palId]);
            Senior::adjustPoints($seniorId, $claw);

            $balPalStmt2 = self::db()->prepare('SELECT COALESCE(points_balance,0) AS b FROM pal_profiles WHERE pal_ID = ?');
            $balPalStmt2->execute([$palId]);
            $balPalAfter = (int) ($balPalStmt2->fetch()['b'] ?? 0);
            $balSenAfter = Senior::pointsBalance($seniorId);

            $palProf = Pal::profileByPalId($palId);
            $palUserIdForLedger = (int) ($palProf['User_ID'] ?? 0);
            Points::recordLedger(
                $palUserIdForLedger,
                $visitId,
                'Admin_Adjustment',
                -$claw,
                max(0, $balPalAfter),
                'Dispute #' . $disputeId . ' refund to senior — ' . substr($notes, 0, 200)
            );
            Points::recordLedger(
                $seniorUserId,
                $visitId,
                'Admin_Adjustment',
                $claw,
                max(0, $balSenAfter),
                'Dispute #' . $disputeId . ' credit from Pal — ' . substr($notes, 0, 200)
            );

            $stmt = self::db()->prepare(
                "UPDATE disputes SET status='Resolved', resolution='Refund_Senior', resolution_notes=?, resolved_by_User_ID=?, resolved_at=NOW() WHERE dispute_ID=? AND status IN ('Open','Awaiting_Info')"
            );
            $stmt->execute([substr($notes, 0, 8000), $adminUserId, $disputeId]);
            if ($stmt->rowCount() === 0) {
                self::db()->rollBack();
                throw new RuntimeException('Could not mark dispute resolved.');
            }
            self::audit($disputeId, $adminUserId, 'RESOLVED_REFUND_SENIOR', 'claw=' . $claw . ' | ' . $notes);
            self::db()->commit();
        } catch (\Throwable $e) {
            if (self::db()->inTransaction()) {
                self::db()->rollBack();
            }
            throw $e;
        }

        $title = 'Dispute resolved — SilverPoints returned to senior household';
        $body = 'Case #' . $disputeId . ': ' . $notes;
        foreach (self::partyUserIdsForVisit($d) as $uid) {
            Notification::enqueue($uid, 'System', $title, $body);
        }
    }
}
