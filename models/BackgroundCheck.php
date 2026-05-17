<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Notification.php';

final class BackgroundCheck {
    private static PDO $db;
    private static bool $schemaReady = false;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    private static function ensureSchema(): void {
        if (self::$schemaReady) {
            return;
        }
        try {
            self::db()->exec('ALTER TABLE background_checks ADD COLUMN IF NOT EXISTS national_id_number VARCHAR(30) NULL AFTER criminal_record_url');
        } catch (\Throwable $e) {
            // Older MySQL variants may not support IF NOT EXISTS on ADD COLUMN.
            try {
                self::db()->exec('ALTER TABLE background_checks ADD COLUMN national_id_number VARCHAR(30) NULL');
            } catch (\Throwable $ignored) {
            }
        }
        self::$schemaReady = true;
    }

    public static function ensureReady(): void {
        self::ensureSchema();
    }

    /** @return list<array<string,mixed>> */
    public static function pendingForAdmin(): array {
        self::ensureSchema();
        $stmt = self::db()->prepare(
            '
            SELECT bc.check_ID,
                   bc.status AS check_status,
                   bc.id_document_url,
                   bc.criminal_record_url,
                   bc.national_id_number,
                   bc.reference_1_name,
                   bc.reference_2_name,
                   bc.created_at,
                   bc.pal_ID AS profile_pal_id,
                   pp.verification_status AS pal_verification_status,
                   u.User_ID AS user_id,
                   u.Fname AS pal_fname,
                   u.Lname AS pal_lname,
                   u.email AS pal_email,
                   u.phone AS pal_phone,
                   u.profile_photo_url AS profile_photo_url,
                   sb.badge_name AS badge_name,
                   sb.certificate_url AS badge_certificate_url
            FROM background_checks bc
            JOIN pal_profiles pp ON bc.pal_ID = pp.pal_ID
            JOIN users u ON u.User_ID = pp.User_ID
            LEFT JOIN skill_badges sb ON sb.badge_ID = (
                SELECT badge_ID
                FROM skill_badges
                WHERE pal_ID = bc.pal_ID
                ORDER BY badge_ID DESC
                LIMIT 1
            )
            WHERE bc.status IN (\'Pending\', \'In_Progress\')
            ORDER BY bc.check_ID DESC
            LIMIT 120
            '
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function approvePal(int $palProfileId, ?int $verifiedByAdminUserId = null): void {
        $stmtBc = self::db()->prepare("UPDATE background_checks SET status='Passed', reviewed_at=NOW() WHERE pal_ID=?");
        $stmtBc->execute([$palProfileId]);

        $stmt = self::db()->prepare("UPDATE pal_profiles SET verification_status='Approved' WHERE pal_ID=? LIMIT 1");
        $stmt->execute([$palProfileId]);

        $stmtBadges = self::db()->prepare(
            "UPDATE skill_badges
             SET verification_status = 'Verified',
                 verified_by = COALESCE(?, verified_by),
                 issued_at = COALESCE(issued_at, NOW())
             WHERE pal_ID = ?
               AND verification_status = 'Pending'"
        );
        $stmtBadges->execute([$verifiedByAdminUserId ?: null, $palProfileId]);

        $stmtUser = self::db()->prepare('SELECT User_ID FROM pal_profiles WHERE pal_ID=? LIMIT 1');
        $stmtUser->execute([$palProfileId]);
        $uid = (int) ($stmtUser->fetch()['User_ID'] ?? 0);
        if ($uid <= 0) {
            throw new RuntimeException('Pal not found.');
        }

        $stmtUpUser = self::db()->prepare("UPDATE users SET is_active=1, account_status='Active' WHERE User_ID=? LIMIT 1");
        $stmtUpUser->execute([$uid]);

        Notification::enqueue($uid, 'Background_Approved', 'Verification approved', 'Your Pal dossier cleared — welcome to CareNest!');
    }

    public static function rejectPal(int $palProfileId, string $reason): void {
        $stmtBc = self::db()->prepare("UPDATE background_checks SET status='Failed', notes=?, reviewed_at=NOW() WHERE pal_ID=?");
        $stmtBc->execute([substr($reason, 0, 2500), $palProfileId]);

        $stmt = self::db()->prepare("UPDATE pal_profiles SET verification_status='Rejected' WHERE pal_ID=? LIMIT 1");
        $stmt->execute([$palProfileId]);

        $stmtBadges = self::db()->prepare(
            "UPDATE skill_badges
             SET verification_status = 'Rejected',
                 verified_by = NULL
             WHERE pal_ID = ?
               AND verification_status IN ('Pending', 'Verified')"
        );
        $stmtBadges->execute([$palProfileId]);

        $stmtUser = self::db()->prepare('SELECT User_ID FROM pal_profiles WHERE pal_ID=? LIMIT 1');
        $stmtUser->execute([$palProfileId]);
        $uid = (int) ($stmtUser->fetch()['User_ID'] ?? 0);

        Notification::enqueue($uid, 'Background_Rejected', 'Verification needs attention', $reason);
    }
}
