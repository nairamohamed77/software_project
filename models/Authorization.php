<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class Authorization {
    private static PDO $db;
    private static bool $ready = false;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }

        return self::$db;
    }

    public static function ensureTables(): void {
        if (self::$ready) {
            return;
        }
        self::db()->exec(
            "CREATE TABLE IF NOT EXISTS permissions (
                permission_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                permission_key VARCHAR(100) NOT NULL UNIQUE,
                label VARCHAR(150) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::db()->exec(
            "CREATE TABLE IF NOT EXISTS role_permissions (
                role_type ENUM('Senior','Pal','FamilyProxy','Admin') NOT NULL,
                permission_key VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (role_type, permission_key),
                CONSTRAINT fk_role_perm_key FOREIGN KEY (permission_key) REFERENCES permissions(permission_key) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $defaults = [
            ['manage_users', 'Manage users'],
            ['manage_service_categories', 'Manage service categories'],
            ['manage_welfare_checks', 'Manage welfare checks'],
            ['view_audit_logs', 'View audit logs'],
            ['resolve_disputes', 'Resolve disputes'],
            ['book_visit', 'Create bookings'],
            ['request_visit_extension', 'Request visit extension'],
            ['approve_visit_extension', 'Approve visit extension'],
            ['raise_dispute', 'Raise dispute'],
        ];
        $insPerm = self::db()->prepare(
            "INSERT INTO permissions (permission_key, label) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label)"
        );
        foreach ($defaults as [$key, $label]) {
            $insPerm->execute([$key, $label]);
        }

        // Admin gets all default permissions.
        $insRole = self::db()->prepare(
            "INSERT IGNORE INTO role_permissions (role_type, permission_key) VALUES ('Admin', ?)"
        );
        foreach ($defaults as [$key, $_label]) {
            $insRole->execute([$key]);
        }

        // Minimal non-admin defaults.
        self::grant('Senior', 'book_visit');
        self::grant('Senior', 'approve_visit_extension');
        self::grant('Senior', 'raise_dispute');
        self::grant('Pal', 'request_visit_extension');
        self::grant('Pal', 'raise_dispute');
        self::grant('FamilyProxy', 'book_visit');
        self::grant('FamilyProxy', 'approve_visit_extension');
        self::grant('FamilyProxy', 'raise_dispute');

        self::$ready = true;
    }

    public static function grant(string $roleType, string $permissionKey): void {
        $stmt = self::db()->prepare(
            "INSERT IGNORE INTO role_permissions (role_type, permission_key) VALUES (?, ?)"
        );
        $stmt->execute([$roleType, $permissionKey]);
    }

    public static function revoke(string $roleType, string $permissionKey): void {
        $stmt = self::db()->prepare(
            "DELETE FROM role_permissions WHERE role_type = ? AND permission_key = ? LIMIT 1"
        );
        $stmt->execute([$roleType, $permissionKey]);
    }

    public static function roleHas(string $roleType, string $permissionKey): bool {
        self::ensureTables();
        $stmt = self::db()->prepare(
            "SELECT 1 FROM role_permissions WHERE role_type = ? AND permission_key = ? LIMIT 1"
        );
        $stmt->execute([$roleType, $permissionKey]);

        return (bool) $stmt->fetchColumn();
    }
}

