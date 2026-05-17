<?php
declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** @SuppressWarnings Unused for future CSRF/expiry */
function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . carenest_url('views/auth/login.php'));
        exit;
    }
}

function requireRole(string $role): void {
    requireLogin();
    $current = $_SESSION['role'] ?? '';
    $allowed = preg_split('/[|,]/', $role);
    $allowed = array_map('trim', $allowed);
    if (!in_array($current, $allowed, true)) {
        header('Location: ' . carenest_url('views/shared/error.php?code=403'));
        exit;
    }
}

function requirePermission(string $permissionKey): void {
    requireLogin();
    require_once dirname(__DIR__) . '/models/Authorization.php';
    $role = currentRole();
    if (!Authorization::roleHas($role, $permissionKey)) {
        header('Location: ' . carenest_url('views/shared/error.php?code=403'));
        exit;
    }
}

function currentUserId(): int {
    return (int) ($_SESSION['user_id'] ?? 0);
}

function currentRole(): string {
    return (string) ($_SESSION['role'] ?? '');
}

/** For AJAX endpoints: respond with JSON 403 unless role matches. */
function requireRolesJson(array $roles): void {
    requireLogin();
    if (!in_array(currentRole(), $roles, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}
