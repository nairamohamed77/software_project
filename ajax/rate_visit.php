<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
requireRolesJson(['Senior', 'FamilyProxy']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$visitId = (int) ($_POST['visit_id'] ?? 0);
$stars = (int) ($_POST['stars'] ?? 0);
$comment = isset($_POST['comment']) ? (string) $_POST['comment'] : '';

if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing visit.']);
    exit;
}

require_once dirname(__DIR__) . '/models/Rating.php';

try {
    Rating::submitForVisit($visitId, currentUserId(), currentRole(), $stars, $comment);
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
