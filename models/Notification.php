<?php

declare(strict_types=1);



require_once dirname(__DIR__) . '/config/database.php';



final class Notification {

    /** @var list<string> */

    private const TYPES = [

        'Visit_Confirmed',

        'Visit_Cancelled',

        'Visit_Reminder',

        'Visit_Started',

        'Visit_Completed',

        'Emergency_Alert',

        'Points_Update',

        'Badge_Awarded',

        'Background_Approved',

        'Background_Rejected',

        'Welfare_Check',

        'Admin_Broadcast',

        'System',

    ];



    private static PDO $db;



    private static function db(): PDO {

        if (!isset(self::$db)) {

            self::$db = Database::getInstance()->getConnection();

        }

        return self::$db;

    }



    public static function countUnread(int $userId): int {

        $stmt = self::db()->prepare('SELECT COUNT(*) AS c FROM notifications WHERE User_ID = ? AND COALESCE(is_read, 0) = 0');

        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        return (int) ($row['c'] ?? 0);

    }



    /** @return list<array<string,mixed>> */

    public static function forUser(int $userId, int $limit = 50): array {

        $stmt = self::db()->prepare('SELECT notification_ID AS id, type, title, message_body, COALESCE(is_read,0) AS is_read FROM notifications WHERE User_ID = ? ORDER BY notification_ID DESC LIMIT ' . max(1, min(100, $limit)));

        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    }



    public static function markRead(int $notificationId, int $ownerUserId): bool {

        $stmt = self::db()->prepare('UPDATE notifications SET is_read = 1 WHERE notification_ID = ? AND User_ID = ?');

        return $stmt->execute([$notificationId, $ownerUserId]);

    }



    public static function enqueue(int $userId, string $type, string $title, string $message): void {

        if (!in_array($type, self::TYPES, true)) {

            $type = 'System';

        }

        $stmt = self::db()->prepare('INSERT INTO notifications (User_ID, type, title, message_body, is_read) VALUES (?, ?, ?, ?, 0)');

        $stmt->execute([$userId, $type, $title, $message]);

    }



    /** Permanently delete one notification if it belongs to the given user (admin’s own inbox). */

    public static function deleteByIdForOwner(int $ownerUserId, int $notificationId): bool {

        if ($notificationId <= 0 || $ownerUserId <= 0) {

            return false;

        }

        try {

            $stmt = self::db()->prepare('DELETE FROM notifications WHERE notification_ID = ? AND User_ID = ?');

            return $stmt->execute([$notificationId, $ownerUserId]) && $stmt->rowCount() > 0;

        } catch (\Throwable $e) {

            return false;

        }

    }



    /** @deprecated Prefer deleteByIdForOwner (scoped). */

    public static function deleteByIdForAdmin(int $notificationId): bool {

        if ($notificationId <= 0) {

            return false;

        }

        try {

            $stmt = self::db()->prepare('DELETE FROM notifications WHERE notification_ID = ?');

            return $stmt->execute([$notificationId]) && $stmt->rowCount() > 0;

        } catch (\Throwable $e) {

            return false;

        }

    }



    /** Admin-only: permanently delete notifications by IDs (dashboard batch clear). */

    public static function deleteIdsForAdmin(array $notificationIds): int {

        $ids = [];

        foreach ($notificationIds as $id) {

            $id = (int) $id;

            if ($id > 0) {

                $ids[$id] = $id;

            }

        }

        if ($ids === []) {

            return 0;

        }

        try {

            $marks = implode(',', array_fill(0, count($ids), '?'));

            $del = self::db()->prepare('DELETE FROM notifications WHERE notification_ID IN (' . $marks . ')');

            return $del->execute(array_values($ids)) ? $del->rowCount() : 0;

        } catch (\Throwable $e) {

            return 0;

        }

    }



    /** Deletes notifications that belong to this user only (admin inbox purge). */

    public static function deleteIdsForUser(int $ownerUserId, array $notificationIds): int {

        $ownerUserId = max(1, $ownerUserId);

        $ids = [];

        foreach ($notificationIds as $id) {

            $id = (int) $id;

            if ($id > 0) {

                $ids[$id] = $id;

            }

        }

        if ($ids === []) {

            return 0;

        }

        try {

            $marks = implode(',', array_fill(0, count($ids), '?'));

            $params = array_merge(array_values($ids), [$ownerUserId]);

            $del = self::db()->prepare(

                'DELETE FROM notifications WHERE notification_ID IN (' . $marks . ') AND User_ID = ?'

            );

            return $del->execute($params) ? $del->rowCount() : 0;

        } catch (\Throwable $e) {

            return 0;

        }

    }

}

