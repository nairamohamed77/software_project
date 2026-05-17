<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Senior.php';
require_once __DIR__ . '/User.php';

final class FamilyProxy {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /** @return list<int> User IDs of proxies linked to a senior_profile.senior_ID */
    public static function proxiesLinkedToSeniorSeniorId(int $seniorId): array {
        $out = [];

        foreach (
            [
                ['col' => 'senior_ID', 'prox' => 'proxy_User_ID'],
                ['col' => 'senior_ID', 'prox' => 'proxy_user_ID'],
                ['col' => 'senior_ID', 'prox' => 'Proxy_User_ID'],
                ['col' => 'Senior_ID', 'prox' => 'proxy_User_ID'],
                ['col' => 'Senior_ID', 'prox' => 'proxy_user_ID'],
                ['col' => 'Senior_ID', 'prox' => 'Proxy_User_ID'],
            ] as $pair
        ) {
            try {
                $c = '`' . $pair['col'] . '`';
                $p = '`' . $pair['prox'] . '`';
                $stmt = self::db()->prepare("SELECT DISTINCT $p AS pid FROM proxy_senior_link WHERE $c = ?");
                $stmt->execute([$seniorId]);
                foreach (($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [] as $row) {
                    if (!empty($row['pid'])) {
                        $out[] = (int) $row['pid'];
                    }
                }
                break;
            } catch (\Throwable $e) {
                // Try next casing
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<int> senior_profile.senior_ID values accessible to proxy user */
    public static function seniorsForProxyUser(int $proxyUserId): array {
        $out = [];

        foreach (
            [
                ['col' => 'senior_ID', 'prox' => 'proxy_User_ID'],
                ['col' => 'senior_ID', 'prox' => 'proxy_user_ID'],
                ['col' => 'senior_ID', 'prox' => 'Proxy_User_ID'],
                ['col' => 'Senior_ID', 'prox' => 'proxy_User_ID'],
                ['col' => 'Senior_ID', 'prox' => 'proxy_user_ID'],
                ['col' => 'Senior_ID', 'prox' => 'Proxy_User_ID'],
            ] as $pair
        ) {
            try {
                $c = '`' . $pair['col'] . '`';
                $p = '`' . $pair['prox'] . '`';
                $stmt = self::db()->prepare("SELECT DISTINCT $c AS sid FROM proxy_senior_link WHERE $p = ?");
                $stmt->execute([$proxyUserId]);
                foreach (($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [] as $row) {
                    if (!empty($row['sid'])) {
                        $out[] = (int) $row['sid'];
                    }
                }
                break;
            } catch (\Throwable $e) {
                // Continue
            }
        }

        return array_values(array_unique($out));
    }

    public static function proxyCanManageSenior(int $proxyUserId, int $seniorId): bool {
        return in_array($seniorId, self::seniorsForProxyUser($proxyUserId), true);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function relationshipRowsForProxy(int $proxyUserId): array {
        foreach (['proxy_User_ID', 'proxy_user_ID', 'Proxy_User_ID'] as $col) {
            try {
                $stmt = self::db()->prepare(
                    "SELECT senior_ID, relationship_type, COALESCE(is_primary, 0) AS is_primary
                     FROM proxy_senior_link WHERE `$col` = ?"
                );
                $stmt->execute([$proxyUserId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                continue;
            }
        }

        return [];
    }

    /**
     * Linked seniors with names and household SilverPoints (senior_profiles.points_balance).
     *
     * @return list<array{senior_ID:int,senior_user_id:int,Fname:string,Lname:string,email:string,points_balance:int,relationship_type:string,is_primary:int}>
     */
    public static function linkedSeniorsWithProfiles(int $proxyUserId): array {
        $ids = self::seniorsForProxyUser($proxyUserId);
        if ($ids === []) {
            return [];
        }

        $relRows = self::relationshipRowsForProxy($proxyUserId);
        $relBySenior = [];
        foreach ($relRows as $row) {
            $sid = (int) ($row['senior_ID'] ?? $row['senior_id'] ?? 0);
            if ($sid > 0) {
                $relBySenior[$sid] = $row;
            }
        }

        $out = [];
        foreach ($ids as $sid) {
            $su = Senior::seniorUserIdFromSeniorRow($sid);
            if ($su === null) {
                continue;
            }
            $u = User::findById($su);
            if ($u === null) {
                continue;
            }
            $meta = $relBySenior[$sid] ?? [];
            $out[] = [
                'senior_ID' => $sid,
                'senior_user_id' => $su,
                'Fname' => (string) ($u['Fname'] ?? ''),
                'Lname' => (string) ($u['Lname'] ?? ''),
                'email' => (string) ($u['email'] ?? ''),
                'points_balance' => Senior::pointsBalance($sid),
                'relationship_type' => (string) ($meta['relationship_type'] ?? ''),
                'is_primary' => (int) ($meta['is_primary'] ?? 0),
            ];
        }

        usort(
            $out,
            static function (array $a, array $b): int {
                $cmp = ($b['is_primary'] ?? 0) <=> ($a['is_primary'] ?? 0);
                return $cmp !== 0 ? $cmp : strcmp((string) ($a['Lname'] ?? ''), (string) ($b['Lname'] ?? ''));
            }
        );

        return $out;
    }
}
