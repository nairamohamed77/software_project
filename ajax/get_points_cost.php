<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
requireRolesJson(['Senior', 'FamilyProxy']);

$id = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
if ($id <= 0) {
    echo json_encode(['error' => 'category_id']);
    exit;
}

require_once dirname(__DIR__) . '/models/ServiceCategory.php';
$row = ServiceCategory::byId($id);
if ($row === null) {
    echo json_encode(['error' => 'not_found']);
    exit;
}

echo json_encode([
    'cost' => (int) ($row['base_points_cost'] ?? 0),
    'category_name' => (string) ($row['category_name'] ?? ''),
]);
