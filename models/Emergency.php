<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Pal.php';
require_once __DIR__ . '/FamilyProxy.php';
require_once __DIR__ . '/Notification.php';

final class Emergency {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /** @param array<string,mixed> $seniorHealth */
    public static function triggerPanic(int $seniorUserId, int $seniorId, array $seniorHealth, ?string $location): int {
        $snapshot = implode("\n", array_filter([
            'Medical Notes: ' . (string) ($seniorHealth['medical_notes'] ?? ''),
            'Allergies: ' . (string) ($seniorHealth['allergies'] ?? ''),
            'Mobility: ' . (string) ($seniorHealth['mobility_level'] ?? ''),
        ]));

        self::db()->beginTransaction();
        try {
            $loc = substr(trim((string) ($location ?? '')), 0, 2000);

            $stmtThread = self::db()->prepare(
                'INSERT INTO emergency_threads (senior_ID, triggered_by, status, priority_level, senior_location, senior_medical_snapshot)
                 VALUES (?,?,?,?,?,?)'
            );
            $stmtThread->execute([$seniorId, $seniorUserId, 'Open', 'Critical', $loc, $snapshot]);

            $tid = (int) self::db()->lastInsertId();

            $stmtMsg = self::db()->prepare(
                'INSERT INTO emergency_messages (thread_ID, sender_User_ID, message_text, location_snapshot, medical_snapshot, message_type)
                 VALUES (?,?,?,?,?,?)'
            );
            $msgText = 'Panic button initiated.';
            $stmtMsg->execute([$tid, $seniorUserId, $msgText, $loc, $snapshot, 'Alert']);

            self::broadcastNotifications($tid, $seniorId, $seniorUserId, $snapshot, $location);

            self::db()->commit();
            return $tid;
        } catch (\Throwable $e) {
            self::db()->rollBack();
            throw $e;
        }
    }

    private static function broadcastNotifications(int $tid, int $seniorId, int $seniorUserId, string $snapshot, ?string $location): void {
        $title = 'Emergency alert #' . $tid;
        $body = trim($snapshot . "\nWhere: " . ($location ?? ''));

        try {
            $stmtAdmin = self::db()->query("SELECT User_ID FROM users WHERE role_type='Admin' AND COALESCE(is_active,0)=1");
            foreach (($stmtAdmin ? $stmtAdmin->fetchAll(PDO::FETCH_ASSOC) : []) as $adm) {
                Notification::enqueue((int) ($adm['User_ID'] ?? 0), 'Emergency_Alert', $title, $body);
            }
        } catch (\Throwable $e) {
        }

        foreach (FamilyProxy::proxiesLinkedToSeniorSeniorId($seniorId) as $pu) {
            Notification::enqueue((int) $pu, 'Emergency_Alert', $title, $body);
        }

        Notification::enqueue($seniorUserId, 'Emergency_Alert', 'Alert sent', 'Responders were notified for thread #' . $tid . '.');

        try {
            $stmtVisit = self::db()->prepare("SELECT pal_ID FROM visit_requests WHERE senior_ID=? AND status='Live' ORDER BY scheduled_start DESC LIMIT 1");
            $stmtVisit->execute([$seniorId]);
            $vr = $stmtVisit->fetch(PDO::FETCH_ASSOC);
            if ($vr && !empty($vr['pal_ID'])) {
                $pal = Pal::profileByPalId((int) $vr['pal_ID']);
                if ($pal) {
                    Notification::enqueue((int) ($pal['User_ID'] ?? 0), 'Emergency_Alert', $title, 'Active visit panic — thread #' . $tid);
                }
            }
        } catch (\Throwable $e) {
        }
    }
}
