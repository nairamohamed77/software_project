<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class User {
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
            self::db()->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS national_id_number VARCHAR(30) NULL AFTER phone');
        } catch (\Throwable $e) {
            try {
                self::db()->exec('ALTER TABLE users ADD COLUMN national_id_number VARCHAR(30) NULL');
            } catch (\Throwable $ignored) {
            }
        }
        self::$schemaReady = true;
    }

    public static function ensureReady(): void {
        self::ensureSchema();
    }

    public static function normalizeLoginEmail(string $email): string {
        return strtolower(trim($email));
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function isAccountLoginAllowed(array $user): bool {
        $isActive = (int) ($user['is_active'] ?? 0);
        $acct = strtolower(trim((string) ($user['account_status'] ?? '')));

        return $isActive === 1 && $acct === 'active';
    }

    /**
     * Parse role specs used by requireRole(), e.g. "Admin|Senior" or "Admin, Senior".
     *
     * @return list<string>
     */
    public static function parseAllowedRoles(string $roleSpec): array {
        $allowed = preg_split('/[|,]/', $roleSpec) ?: [];
        $allowed = array_map(static fn (string $r): string => trim($r), $allowed);

        return array_values(array_filter($allowed, static fn (string $r): bool => $r !== ''));
    }

    public static function roleIsAllowed(string $currentRole, string $roleSpec): bool {
        return in_array($currentRole, self::parseAllowedRoles($roleSpec), true);
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array {
        $stmt = self::db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function findByNationalId(string $nationalId): ?array {
        self::ensureSchema();
        $stmt = self::db()->prepare('SELECT * FROM users WHERE national_id_number = ? LIMIT 1');
        $stmt->execute([$nationalId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array {
        $stmt = self::db()->prepare('SELECT * FROM users WHERE User_ID = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array{fpath:string,type:string}? $photoMeta */
    public static function register(
        string $fname,
        string $lname,
        string $email,
        string $phone,
        string $nationalIdNumber,
        string $passwordPlain,
        string $roleType,
        ?array $photoMeta,
    ): array {
        self::ensureSchema();
        $email = strtolower(trim($email));
        $existing = self::findByEmail($email);
        if ($existing !== null) {
            return ['ok' => false, 'message' => 'Email already registered.'];
        }
        if ($nationalIdNumber !== '' && self::findByNationalId($nationalIdNumber) !== null) {
            return ['ok' => false, 'message' => 'National ID already registered.'];
        }

        self::db()->beginTransaction();
        try {
            $photoPath = null;
            if ($photoMeta !== null && $photoMeta['fpath'] !== '') {
                $photoPath = $photoMeta['fpath'];
            }

            // Column names tolerate optional profile_photo_url / Phone / Phone_number
            $cols = '`Fname`,`Lname`,`email`,`password_hash`,`role_type`,`is_active`,`account_status`';
            $vals = '?,?,?,?,?,0,?';
            $params = [$fname, $lname, $email, password_hash($passwordPlain, PASSWORD_BCRYPT, ['cost' => 12]), $roleType, 'Pending'];

            self::db()->prepare(
                "INSERT INTO users ($cols, phone, national_id_number, profile_photo_url) VALUES ($vals, ?, ?, ?)"
            )->execute([
                ...$params,
                $phone !== '' ? $phone : null,
                $nationalIdNumber !== '' ? $nationalIdNumber : null,
                ($photoPath !== null && $photoPath !== '') ? $photoPath : 'default.png',
            ]);

            $userId = (int) self::db()->lastInsertId();

            if ($roleType === 'Senior') {
                $stmt = self::db()->prepare('INSERT INTO senior_profiles (User_ID, points_balance, subscription_tier, emergency_contact_name) VALUES (?, ?, ?, ?)');
                $stmt->execute([$userId, 0, 'Standard', null]);
                $stmt = self::db()->prepare('SELECT senior_ID FROM senior_profiles WHERE User_ID = ? LIMIT 1');
                $stmt->execute([$userId]);
                $seniorProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $seniorId = (int) ($seniorProfile['senior_ID'] ?? 0);
                if ($seniorId > 0) {
                    $stmt = self::db()->prepare('INSERT INTO health_records (senior_ID, medical_notes, allergies, mobility_level, must_acknowledge) VALUES (?, ?, ?, ?, 0)');
                    $stmt->execute([$seniorId, '', '', 'Full']);
                }
            } elseif ($roleType === 'Pal') {
                $stmt = self::db()->prepare('INSERT INTO pal_profiles (User_ID, verification_status, rating_avg, points_balance, is_available, travel_radius_km) VALUES (?, ?, 0.0, 0, 0, 25)');
                $stmt->execute([$userId, 'Pending']);
            }

            self::db()->commit();
            return ['ok' => true, 'user_id' => $userId];
        } catch (\Throwable $e) {
            self::db()->rollBack();
            return ['ok' => false, 'message' => 'Registration could not complete. Verify database matches carenest_setup.sql'];
        }
    }

    public static function tryLogin(string $email, string $password): ?array {
        $email = self::normalizeLoginEmail($email);
        $user = self::findByEmail($email);
        if ($user === null) {
            return null;
        }
        $hash = (string) ($user['password_hash'] ?? '');
        if (!password_verify($password, $hash)) {
            return null;
        }
        if (!self::isAccountLoginAllowed($user)) {
            return null;
        }

        try {
            $upd = self::db()->prepare('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE User_ID = ?');
            $upd->execute([(int) $user['User_ID']]);
        } catch (\Throwable $e) {
            // Column may not exist
        }

        return $user;
    }

    /** @param array<string,mixed> $user */
    public static function dashboardPathFor(array $user): string {
        $role = (string) ($user['role_type'] ?? '');
        return match ($role) {
            'Senior' => 'views/senior/dashboard.php',
            'Pal' => 'views/pal/dashboard.php',
            'FamilyProxy' => 'views/proxy/dashboard.php',
            'Admin' => 'views/admin/dashboard.php',
            default => 'views/shared/error.php?code=403',
        };
    }
}
