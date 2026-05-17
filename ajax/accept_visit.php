<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
requireRole('Pal');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID = ? LIMIT 1');
$stmt->execute([currentUserId()]);
$palPid = (int) ($stmt->fetch()['pal_ID'] ?? 0);

$visitId = isset($_POST['visit_id']) ? (int) $_POST['visit_id'] : 0;
if ($palPid <= 0 || $visitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

require_once dirname(__DIR__) . '/models/Visit.php';
try {
    Visit::accept($visitId, $palPid);
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
