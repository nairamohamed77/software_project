<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
requireRolesJson(['Senior', 'FamilyProxy']);

$cid = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

require_once dirname(__DIR__) . '/models/Pal.php';
$catalog = Pal::catalogForBookingWithBadges();

$out = [];
foreach ($catalog as $r) {
    $out[] = [
        'pal_ID' => (int) ($r['pal_ID'] ?? 0),
        'user_ID' => (int) ($r['user_ID'] ?? 0),
        'fname' => (string) ($r['Fname'] ?? ''),
        'lname' => (string) ($r['Lname'] ?? ''),
        'rating_avg' => (float) ($r['rating_avg'] ?? 0),
        'travel_radius_km' => (int) ($r['travel_radius_km'] ?? 0),
        'is_available' => (int) ($r['is_available'] ?? 0),
        'verification_status' => (string) ($r['verification_status'] ?? ''),
        'account_status' => (string) ($r['account_status'] ?? ''),
        'user_is_active' => (int) ($r['user_is_active'] ?? 0),
        'has_active_assignment' => (int) ($r['has_active_assignment'] ?? 0),
        'badges' => $r['badges'] ?? [],
    ];
}

echo json_encode([
    'category_id' => $cid,
    'pals' => $out,
]);
