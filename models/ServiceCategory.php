<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

final class ServiceCategory {
    private static PDO $db;

    private static function db(): PDO {
        if (!isset(self::$db)) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /** @return list<array<string,mixed>> */
    public static function active(): array {
        $stmt = self::db()->query("SELECT category_ID AS id, category_name, base_points_cost, COALESCE(icon,'') AS icon FROM service_categories WHERE COALESCE(is_active,1)=1 ORDER BY category_name ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $id): ?array {
        $stmt = self::db()->prepare("SELECT category_ID AS id, category_name, base_points_cost, COALESCE(icon,'') AS icon FROM service_categories WHERE category_ID = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // --- Admin CRUD (Create, Read, Update, Delete) ---

    /** @return list<array<string,mixed>> */
    public static function allAdmin(): array {
        $stmt = self::db()->query(
            'SELECT category_ID, category_name, description, COALESCE(icon,\'\') AS icon,
                    base_points_cost, cost_per_extra_hour, requires_badge, COALESCE(is_active,1) AS is_active, created_at
             FROM service_categories
             ORDER BY category_name ASC'
        );

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<string,mixed>|null */
    public static function findAdminById(int $id): ?array {
        $stmt = self::db()->prepare(
            'SELECT category_ID, category_name, description, COALESCE(icon,\'\') AS icon,
                    base_points_cost, cost_per_extra_hour, requires_badge, COALESCE(is_active,1) AS is_active, created_at
             FROM service_categories WHERE category_ID = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function createRow(
        string $categoryName,
        string $description,
        string $icon,
        int $basePointsCost,
        int $costPerExtraHour,
        ?string $requiresBadge,
        bool $isActive
    ): int {
        $categoryName = trim(substr($categoryName, 0, 100));
        if ($categoryName === '') {
            throw new InvalidArgumentException('Category name is required.');
        }
        if ($basePointsCost < 0 || $costPerExtraHour < 0) {
            throw new InvalidArgumentException('Points must be zero or positive.');
        }
        $icon = trim(substr($icon, 0, 100));
        if ($icon === '') {
            $icon = 'fa-hand-holding-heart';
        }
        $requiresBadge = $requiresBadge !== null && trim($requiresBadge) !== ''
            ? trim(substr($requiresBadge, 0, 100))
            : null;

        $stmt = self::db()->prepare(
            'INSERT INTO service_categories
             (category_name, description, icon, base_points_cost, cost_per_extra_hour, requires_badge, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $stmt->execute([
                $categoryName,
                substr($description, 0, 65535),
                $icon,
                $basePointsCost,
                $costPerExtraHour,
                $requiresBadge,
                $isActive ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new InvalidArgumentException('A category with this name already exists.');
            }
            throw $e;
        }

        return (int) self::db()->lastInsertId();
    }

    public static function updateRow(
        int $categoryId,
        string $categoryName,
        string $description,
        string $icon,
        int $basePointsCost,
        int $costPerExtraHour,
        ?string $requiresBadge,
        bool $isActive
    ): void {
        if (!self::findAdminById($categoryId)) {
            throw new RuntimeException('Category not found.');
        }
        $categoryName = trim(substr($categoryName, 0, 100));
        if ($categoryName === '') {
            throw new InvalidArgumentException('Category name is required.');
        }
        if ($basePointsCost < 0 || $costPerExtraHour < 0) {
            throw new InvalidArgumentException('Points must be zero or positive.');
        }
        $icon = trim(substr($icon, 0, 100));
        if ($icon === '') {
            $icon = 'fa-hand-holding-heart';
        }
        $requiresBadge = $requiresBadge !== null && trim($requiresBadge) !== ''
            ? trim(substr($requiresBadge, 0, 100))
            : null;

        $stmt = self::db()->prepare(
            'UPDATE service_categories SET
                category_name = ?, description = ?, icon = ?, base_points_cost = ?, cost_per_extra_hour = ?,
                requires_badge = ?, is_active = ?
             WHERE category_ID = ? LIMIT 1'
        );
        try {
            $stmt->execute([
                $categoryName,
                substr($description, 0, 65535),
                $icon,
                $basePointsCost,
                $costPerExtraHour,
                $requiresBadge,
                $isActive ? 1 : 0,
                $categoryId,
            ]);
        } catch (\PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new InvalidArgumentException('A category with this name already exists.');
            }
            throw $e;
        }
    }

    public static function deleteRow(int $categoryId): void {
        $chk = self::db()->prepare('SELECT COUNT(*) AS c FROM visit_requests WHERE category_ID = ?');
        $chk->execute([$categoryId]);
        $n = (int) ($chk->fetch()['c'] ?? 0);
        if ($n > 0) {
            throw new RuntimeException(
                'Cannot delete: ' . $n . ' visit(s) reference this category. Turn off "Active" instead of deleting.'
            );
        }
        $stmt = self::db()->prepare('DELETE FROM service_categories WHERE category_ID = ? LIMIT 1');
        $stmt->execute([$categoryId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Category not found.');
        }
    }
}
