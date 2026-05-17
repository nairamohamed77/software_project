<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class Points {
    public const INSURANCE_RATE = 0.05;

    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    public static function recordLedger(int $userId, ?int $visitId, string $entryType, int $deltaPoints, int $balanceAfter, ?string $description = null): void {
        if ($description !== null) {
            try {
                $stmt = self::db()->prepare('INSERT INTO silverpoints_ledger (User_ID, visit_ID, entry_type, points_amount, balance_after, description) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$userId, $visitId, $entryType, $deltaPoints, $balanceAfter, $description]);
                return;
            } catch (\Throwable $e) {
                // fall through to minimal columns
            }
        }
        $stmt = self::db()->prepare('INSERT INTO silverpoints_ledger (User_ID, visit_ID, entry_type, points_amount, balance_after) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $visitId, $entryType, $deltaPoints, $balanceAfter]);
    }

    /** @return list<array<string,mixed>> */
    public static function ledgerForUser(int $userId, int $limit = 50): array {
        $limit = max(1, min(100, $limit));
        $stmt = self::db()->prepare('SELECT ledger_ID AS id, visit_ID AS visit_id, entry_type, points_amount, balance_after, description FROM silverpoints_ledger WHERE User_ID = ? ORDER BY ledger_ID DESC LIMIT ' . $limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function insuranceCut(int $lockedPoints): int {
        return (int) ceil($lockedPoints * self::INSURANCE_RATE);
    }
}
