<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Senior.php';
require_once __DIR__ . '/Pal.php';
require_once __DIR__ . '/Points.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/FamilyProxy.php';
require_once __DIR__ . '/VisitReport.php';
require_once __DIR__ . '/patterns/VisitObserver.php';
require_once __DIR__ . '/patterns/VisitStatusAbstraction.php';

final class Visit {
    private static PDO $db;
    private static VisitSubject $subject;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    private static function subject(): VisitSubject {
        if (!isset(self::$subject)) {
            self::$subject = new VisitSubject();
            self::$subject->attach(new VisitNotificationObserver());
        }
        return self::$subject;
    }

    /**
     * @param array<string,mixed> $visitRow
     */
    private static function assertTransition(array $visitRow, string $nextStatus, string $errorMessage): void {
        $current = (string) ($visitRow['status'] ?? '');
        $statusRule = VisitStatusRuleFactory::fromDbStatus($current);
        if (!$statusRule->allows($nextStatus)) {
            throw new RuntimeException($errorMessage);
        }
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $visitId): ?array {
        $stmt = self::db()->prepare('SELECT * FROM visit_requests WHERE visit_ID = ? LIMIT 1');
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{n1:string,n2:?string} $notesPayload
     */
    public static function createBooking(
        int $seniorId,
        int $seniorUserId,
        int $palPalId,
        int $palUserId,
        int $categoryId,
        string $start,
        string $end,
        int $pointsReserved,
        array $notesPayload = ['n1' => '', 'n2' => ''],
    ): int {
        $cost = max(1, $pointsReserved);
        $balance = Senior::pointsBalance($seniorId);
        if ($balance < $cost) {
            throw new RuntimeException('Insufficient SilverPoints balance.');
        }

        self::db()->beginTransaction();
        try {
            $n1 = (string) $notesPayload['n1'];
            $n2 = (string) ($notesPayload['n2'] ?? '');

            $stmt = self::db()->prepare(
                'INSERT INTO visit_requests (senior_ID, pal_ID, category_ID, status, scheduled_start, scheduled_end, points_reserved, task_details, special_instructions)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$seniorId, $palPalId, $categoryId, 'Pending', $start, $end, $cost, $n1, $n2]);

            $visitId = (int) self::db()->lastInsertId();

            $stmtEsc = self::db()->prepare("INSERT INTO escrow (visit_ID, senior_ID, points_locked, status) VALUES (?, ?, ?, 'Locked')");
            $stmtEsc->execute([$visitId, $seniorId, $cost]);

            Senior::adjustPoints($seniorId, -$cost);
            $balAfterSenior = Senior::pointsBalance($seniorId);
            Points::recordLedger(
                $seniorUserId,
                $visitId,
                'Booking_Reserve',
                -$cost,
                $balAfterSenior,
                'Points reserved for visit #' . $visitId
            );

            self::db()->commit();
            self::subject()->notify('BOOKED', [
                'visit_id' => $visitId,
                'senior_id' => $seniorId,
                'pal_user_id' => $palUserId,
            ]);
            return $visitId;
        } catch (\Throwable $e) {
            self::db()->rollBack();
            throw $e;
        }
    }

    public static function accept(int $visitId, int $palPalId): void {
        $v = self::byId($visitId);
        if (!$v || (int) ($v['pal_ID'] ?? 0) !== $palPalId) {
            throw new RuntimeException('Invalid visit.');
        }
        self::assertTransition($v, 'accepted', 'Visit cannot be accepted in its current status.');
        $stmt = self::db()->prepare("UPDATE visit_requests SET status='Accepted' WHERE visit_ID=?");
        $stmt->execute([$visitId]);

        self::subject()->notify('ACCEPTED', [
            'visit_id' => $visitId,
            'senior_id' => (int) ($v['senior_ID'] ?? 0),
        ]);
    }

    public static function reject(int $visitId, int $palPalId, ?string $reason): void {
        $v = self::byId($visitId);
        if (!$v || (int) ($v['pal_ID'] ?? 0) !== $palPalId) {
            throw new RuntimeException('Invalid visit.');
        }
        self::assertTransition($v, 'cancelled', 'Visit cannot be rejected in its current status.');
        $activeAssignStmt = self::db()->prepare(
            "SELECT 1
             FROM visit_requests
             WHERE pal_ID = ?
               AND visit_ID <> ?
               AND LOWER(TRIM(REPLACE(status, '_', '-'))) IN ('accepted', 'live', 'en-route')
             LIMIT 1"
        );
        $activeAssignStmt->execute([$palPalId, $visitId]);
        $actionType = $activeAssignStmt->fetchColumn() ? 'Unavailable' : 'Rejected';
        $stmtIns = self::db()->prepare("INSERT INTO pal_passed_requests (visit_ID, pal_ID, reason, action_type) VALUES (?,?,?, ?)");
        $stmtIns->execute([$visitId, $palPalId, substr($reason ?? '', 0, 255), $actionType]);

        $su = Senior::seniorUserIdFromSeniorRow((int) $v['senior_ID']);

        try {
            $stmt = self::db()->prepare("UPDATE visit_requests SET status='Cancelled' WHERE visit_ID=?");
            $stmt->execute([$visitId]);
            if ($su !== null) {
                $locked = (int) ($v['points_reserved'] ?? 0);
                if ($locked > 0) {
                    $seniorId = (int) ($v['senior_ID'] ?? 0);
                    Senior::adjustPoints($seniorId, $locked);
                    $bal = Senior::pointsBalance($seniorId);
                    Points::recordLedger($su, $visitId, 'Cancellation_Refund', $locked, $bal, 'Refund after Pal declined visit #' . $visitId);
                }
            }
            $stmtE = self::db()->prepare("UPDATE escrow SET status='Returned_To_Senior', released_at=NOW() WHERE visit_ID=? AND status='Locked'");
            $stmtE->execute([$visitId]);
            self::subject()->notify('REJECTED', [
                'visit_id' => $visitId,
                'senior_id' => (int) ($v['senior_ID'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            // tolerated
        }
    }

    public static function checkIn(int $visitId, int $palPalId): void {
        $v = self::byId($visitId);
        if (!$v || (int) ($v['pal_ID'] ?? 0) !== $palPalId) {
            throw new RuntimeException('Invalid visit.');
        }
        self::assertTransition($v, 'live', 'Visit not awaiting check-in.');
        $stmt = self::db()->prepare("UPDATE visit_requests SET status='Live', actual_checkin = COALESCE(actual_checkin, NOW()) WHERE visit_ID=?");
        $stmt->execute([$visitId]);

        try {
            self::db()->prepare("UPDATE escrow SET status = 'In_Mission' WHERE visit_ID = ? AND status = 'Locked'")->execute([$visitId]);
        } catch (\Throwable $e) {
            // Escrow ENUM may not include In_Mission — funds stay Locked until completion; still reserved.
        }
    }

    /** Pal notes: allowed once Accepted (before check-in) and while Live; stored as During reports. */
    public static function saveDuringProgress(int $visitId, int $palPalId, string $body): void {
        $v = self::byId($visitId);
        if (!$v || (int) ($v['pal_ID'] ?? 0) !== $palPalId) {
            throw new RuntimeException('Invalid visit.');
        }
        $prev = strtolower(trim(str_replace('_', '-', (string) ($v['status'] ?? ''))));
        if ($prev !== 'live' && $prev !== 'accepted') {
            throw new RuntimeException('You can write a report after you accept, or add more notes after check-in.');
        }
        $body = trim($body);
        if (strlen($body) < 8) {
            throw new RuntimeException('Please write at least a few words for this update.');
        }
        VisitReport::ensureTable();
        VisitReport::add($visitId, $palPalId, 'During', $body);
    }

    /**
     * Ends visit: requires after-report, releases escrow to Pal, marks Completed.
     * Visits are never auto-completed when scheduled_end passes — only this path (Pal submits Complete) finalizes.
     */
    public static function completeVisitWithReport(int $visitId, int $palPalId, string $afterReport): void {
        $v = self::byId($visitId);
        if (!$v || (int) ($v['pal_ID'] ?? 0) !== $palPalId) {
            throw new RuntimeException('Invalid visit.');
        }
        self::assertTransition($v, 'completed', 'Check in first, then complete the visit when you are done.');

        $afterReport = trim($afterReport);
        if (strlen($afterReport) < 12) {
            throw new RuntimeException('After-visit report is required (at least 12 characters).');
        }

        VisitReport::ensureTable();
        self::db()->beginTransaction();
        try {
            VisitReport::add($visitId, $palPalId, 'After', $afterReport);

            try {
                $stmt = self::db()->prepare('UPDATE visit_requests SET health_observation = ? WHERE visit_ID = ?');
                $stmt->execute([substr($afterReport, 0, 8000), $visitId]);
            } catch (\Throwable $e) {
                // Column may be missing on older DBs
            }

            self::releaseEscrowAndMarkCompleteNoOuterTxn($visitId, $palPalId, $v);
            self::db()->commit();
            self::subject()->notify('COMPLETED', [
                'visit_id' => $visitId,
                'senior_id' => (int) ($v['senior_ID'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            self::db()->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $v visit row from byId
     */
    private static function releaseEscrowAndMarkCompleteNoOuterTxn(int $visitId, int $palPalId, array $v): void {
        $stmtE = self::db()->prepare("SELECT escrow_ID, COALESCE(points_locked,0) AS pts FROM escrow WHERE visit_ID=? AND status IN ('Locked','In_Mission') ORDER BY escrow_ID DESC LIMIT 1 FOR UPDATE");
        try {
            $stmtE->execute([$visitId]);
        } catch (\Throwable $e) {
            $stmtE = self::db()->prepare("SELECT escrow_ID, COALESCE(points_locked,0) AS pts FROM escrow WHERE visit_ID=? AND status IN ('Locked','In_Mission') ORDER BY escrow_ID DESC LIMIT 1");
            $stmtE->execute([$visitId]);
        }
        $erow = $stmtE->fetch(PDO::FETCH_ASSOC);
        if (!$erow) {
            // If points were already released in a prior attempt, allow safe completion.
            $stmtReleased = self::db()->prepare("SELECT escrow_ID, COALESCE(points_locked,0) AS pts FROM escrow WHERE visit_ID = ? AND status = 'Released_To_Pal' ORDER BY escrow_ID DESC LIMIT 1");
            $stmtReleased->execute([$visitId]);
            $releasedRow = $stmtReleased->fetch(PDO::FETCH_ASSOC);
            if (!$releasedRow) {
                // Legacy/inconsistent rows may miss escrow entirely. Reconstruct payout
                // from visit_points_reserved so the Pal still receives earned balance.
                $stmtVisit = self::db()->prepare('SELECT COALESCE(points_paid,0) AS points_paid, COALESCE(points_reserved,0) AS points_reserved FROM visit_requests WHERE visit_ID = ? LIMIT 1');
                $stmtVisit->execute([$visitId]);
                $visitRow = $stmtVisit->fetch(PDO::FETCH_ASSOC) ?: [];
                $pointsPaid = (int) ($visitRow['points_paid'] ?? 0);
                $locked = (int) ($visitRow['points_reserved'] ?? 0);

                if ($pointsPaid <= 0 && $locked > 0) {
                    // Business update: Pal receives the full locked amount (no insurance deduction).
                    $palPayout = max(0, $locked);

                    // Keep escrow coherent even if original row is missing.
                    // escrow.visit_ID is UNIQUE, so upsert instead of inserting duplicates.
                    $stmtUpsertEsc = self::db()->prepare(
                        "INSERT INTO escrow (visit_ID, senior_ID, points_locked, status, released_at)
                         VALUES (?, ?, ?, 'Released_To_Pal', NOW())
                         ON DUPLICATE KEY UPDATE
                           senior_ID = VALUES(senior_ID),
                           points_locked = GREATEST(points_locked, VALUES(points_locked)),
                           status = 'Released_To_Pal',
                           released_at = COALESCE(released_at, NOW())"
                    );
                    $stmtUpsertEsc->execute([$visitId, (int) ($v['senior_ID'] ?? 0), $locked]);

                    $stmtPb = self::db()->prepare(
                        'UPDATE pal_profiles SET points_balance = COALESCE(points_balance,0) + ?, total_visits_completed = COALESCE(total_visits_completed,0) + 1 WHERE pal_ID = ?'
                    );
                    $stmtPb->execute([$palPayout, $palPalId]);

                    $pProf = Pal::profileByPalId($palPalId);
                    $palUserId = (int) ($pProf['User_ID'] ?? 0);
                    $balStmt = self::db()->prepare('SELECT COALESCE(points_balance,0) AS b FROM pal_profiles WHERE pal_ID=? LIMIT 1');
                    $balStmt->execute([$palPalId]);
                    $balanceAfterPal = (int) ($balStmt->fetch()['b'] ?? 0);
                    Points::recordLedger($palUserId, $visitId, 'Visit_Payment', $palPayout, $balanceAfterPal, 'Recovered payment for visit #' . $visitId . ' (missing escrow row).');
                    $pointsPaid = $palPayout;
                }

                $stmt = self::db()->prepare("UPDATE visit_requests SET status='Completed', actual_checkout=COALESCE(actual_checkout, NOW()), points_paid=? WHERE visit_ID=?");
                $stmt->execute([max(0, $pointsPaid), $visitId]);
                return;
            }
            $stmt = self::db()->prepare("UPDATE visit_requests SET status='Completed', actual_checkout=COALESCE(actual_checkout, NOW()) WHERE visit_ID=?");
            $stmt->execute([$visitId]);
            return;
        }
        $locked = (int) ($erow['pts'] ?? 0);
        // Business update: Pal receives the full locked amount (no insurance deduction).
        $palPayout = max(0, $locked);

        $stmt = self::db()->prepare("UPDATE escrow SET status='Released_To_Pal', released_at=NOW() WHERE escrow_ID=?");
        $stmt->execute([(int) $erow['escrow_ID']]);

        $stmt = self::db()->prepare("UPDATE visit_requests SET status='Completed', actual_checkout=NOW(), points_paid=? WHERE visit_ID=?");
        $stmt->execute([$palPayout, $visitId]);

        $stmtPb = self::db()->prepare(
            'UPDATE pal_profiles SET points_balance = COALESCE(points_balance,0) + ?, total_visits_completed = COALESCE(total_visits_completed,0) + 1 WHERE pal_ID = ?'
        );
        $stmtPb->execute([$palPayout, $palPalId]);

        $pProf = Pal::profileByPalId($palPalId);
        $palUserId = (int) ($pProf['User_ID'] ?? 0);

        $balStmt = self::db()->prepare('SELECT COALESCE(points_balance,0) AS b FROM pal_profiles WHERE pal_ID=? LIMIT 1');
        $balStmt->execute([$palPalId]);
        $balanceAfterPal = (int) ($balStmt->fetch()['b'] ?? 0);

        Points::recordLedger($palUserId, $visitId, 'Visit_Payment', $palPayout, $balanceAfterPal, 'Full payment for visit #' . $visitId);

    }

    /** @deprecated Completion is only via completeVisitWithReport from the Pal schedule (after-visit report). */
    public static function checkOut(int $visitId, int $palPalId): void {
        throw new RuntimeException('Use “Complete visit” on your Pal schedule with your after-visit report. Visits do not auto-finish when the time slot ends.');
    }
}
