<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Visit.php';
require_once __DIR__ . '/Senior.php';
require_once __DIR__ . '/FamilyProxy.php';

final class Rating {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /** @return array<string,mixed>|null */
    public static function forVisit(int $visitId): ?array {
        $stmt = self::db()->prepare('SELECT * FROM ratings WHERE visit_ID = ? LIMIT 1');
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * One rating per visit (senior household rates the assigned Pal after completion).
     *
     * @throws RuntimeException on validation / duplicate
     */
    public static function submitForVisit(int $visitId, int $actingUserId, string $actingRole, int $stars, ?string $comment): void {
        $stars = (int) $stars;
        if ($stars < 1 || $stars > 5) {
            throw new RuntimeException('Please choose a rating from 1 to 5 stars.');
        }

        $v = Visit::byId($visitId);
        if (!$v) {
            throw new RuntimeException('Visit not found.');
        }

        $st = strtolower(trim(str_replace('_', '-', (string) ($v['status'] ?? ''))));
        if ($st !== 'completed') {
            throw new RuntimeException('You can only rate a completed visit.');
        }

        $visitSeniorId = (int) ($v['senior_ID'] ?? 0);
        $palId = (int) ($v['pal_ID'] ?? 0);
        if ($visitSeniorId <= 0 || $palId <= 0) {
            throw new RuntimeException('This visit cannot be rated.');
        }

        if (self::forVisit($visitId) !== null) {
            throw new RuntimeException('This visit was already rated.');
        }

        if ($actingRole === 'Senior') {
            $prof = Senior::profileByUserId($actingUserId);
            $mySenior = (int) ($prof['senior_ID'] ?? 0);
            if ($mySenior !== $visitSeniorId) {
                throw new RuntimeException('You cannot rate this visit.');
            }
        } elseif ($actingRole === 'FamilyProxy') {
            if (!FamilyProxy::proxyCanManageSenior($actingUserId, $visitSeniorId)) {
                throw new RuntimeException('You cannot rate visits for this senior.');
            }
        } else {
            throw new RuntimeException('Only seniors or linked family proxies can submit ratings.');
        }

        $comment = trim((string) $comment);
        if (strlen($comment) > 4000) {
            $comment = substr($comment, 0, 4000);
        }

        $score = (float) $stars;

        self::db()->beginTransaction();
        try {
            $ins = self::db()->prepare(
                'INSERT INTO ratings (visit_ID, senior_ID, pal_ID, rating_score, comment, is_public) VALUES (?,?,?,?,?,1)'
            );
            $ins->execute([$visitId, $visitSeniorId, $palId, $score, $comment !== '' ? $comment : null]);

            self::refreshPalAggregate($palId);
            self::db()->commit();
        } catch (\Throwable $e) {
            self::db()->rollBack();
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                throw new RuntimeException('This visit was already rated.');
            }
            throw $e;
        }
    }

    private static function refreshPalAggregate(int $palId): void {
        $stmt = self::db()->prepare('SELECT ROUND(AVG(rating_score), 2) AS a, COUNT(*) AS c FROM ratings WHERE pal_ID = ?');
        $stmt->execute([$palId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $avg = isset($row['a']) && $row['a'] !== null ? (float) $row['a'] : 0.0;
        $cnt = (int) ($row['c'] ?? 0);

        $upd = self::db()->prepare('UPDATE pal_profiles SET rating_avg = ?, total_ratings = ? WHERE pal_ID = ?');
        $upd->execute([$avg, $cnt, $palId]);
    }
}
