<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
requireRole('Pal');

$db = Database::getInstance()->getConnection();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method']);
    exit;
}

$data = [];
$ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_contains($ctype, 'application/json')) {
    try {
        $data = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        $data = [];
    }
} elseif (!empty($_POST)) {
    $data = $_POST;
}

$status = isset($data['status']) ? (string) $data['status'] : '';
$flag = in_array((string) $status, ['1', 'true', 'on'], true)
    ? 1
    : (in_array((string) $status, ['0', 'false', 'off'], true) ? 0 : null);

if ($flag === null) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $db->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID = ? LIMIT 1');
$stmt->execute([currentUserId()]);
$pid = (int) ($stmt->fetch()['pal_ID'] ?? 0);

if ($pid <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

require_once dirname(__DIR__) . '/models/Pal.php';
Pal::setAvailability($pid, $flag);
echo json_encode(['success' => true]);
