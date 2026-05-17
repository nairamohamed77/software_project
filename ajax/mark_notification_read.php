<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

$pid = isset($_POST['notification_id']) ? (int) $_POST['notification_id'] : 0;

require_once dirname(__DIR__) . '/models/Notification.php';
if ($pid <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

Notification::markRead($pid, currentUserId());
echo json_encode(['success' => true, 'unread_count' => Notification::countUnread(currentUserId())]);
