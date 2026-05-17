<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Pal');
require_once dirname(__DIR__, 2) . '/models/Visit.php';
require_once dirname(__DIR__, 2) . '/models/VisitReport.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID=? LIMIT 1');
$stmt->execute([currentUserId()]);
$pId = (int) ($stmt->fetch()['pal_ID'] ?? 0);
if ($pId <= 0) {
    http_response_code(403);
    exit('Forbidden');
}

$visitId = (int) ($_GET['visit_id'] ?? 0);
if ($visitId <= 0) {
    http_response_code(404);
    exit('Not found');
}

$visit = Visit::byId($visitId);
if (!$visit || (int) ($visit['pal_ID'] ?? 0) !== $pId) {
    http_response_code(403);
    exit('Forbidden');
}

$stSlug = strtolower(str_replace('_', '-', trim((string) ($visit['status'] ?? ''))));
if ($stSlug !== 'completed') {
    http_response_code(400);
    exit('The PDF report is generated only after the visit is marked completed.');
}

$stmtDet = $db->prepare(
    "
    SELECT vr.*,
           CONCAT(TRIM(IFNULL(su.Fname,'')), ' ', TRIM(IFNULL(su.Lname,''))) AS senior_full_name,
           sc.category_name,
           CONCAT(TRIM(IFNULL(pu.Fname,'')), ' ', TRIM(IFNULL(pu.Lname,''))) AS pal_full_name
    FROM visit_requests vr
    LEFT JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
    LEFT JOIN users su ON sp.User_ID = su.User_ID
    LEFT JOIN service_categories sc ON vr.category_ID = sc.category_ID
    LEFT JOIN pal_profiles pp ON vr.pal_ID = pp.pal_ID
    LEFT JOIN users pu ON pp.User_ID = pu.User_ID
    WHERE vr.visit_ID = ?
    LIMIT 1
    "
);
$stmtDet->execute([$visitId]);
$detail = $stmtDet->fetch(PDO::FETCH_ASSOC) ?: $visit;

$reports = VisitReport::listByVisit($visitId);

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11pt; color: #222; }
        h1 { font-size: 16pt; margin: 0 0 10pt; }
        h2 { font-size: 11pt; margin: 14pt 0 6pt; border-bottom: 1px solid #ccc; }
        .muted { color: #555; font-size: 9pt; }
        .block { margin: 6pt 0; white-space: pre-wrap; word-wrap: break-word; }
        table.meta { border-collapse: collapse; width: 100%; margin: 10pt 0; }
        table.meta td { padding: 4pt 8pt 4pt 0; vertical-align: top; }
        .label { font-weight: bold; width: 30%; }
    </style>
</head>
<body>
<h1><?= $h('CareNest — visit completion report') ?></h1>
<p class="muted"><?= $h('Generated ') . $h(date('c')) ?></p>

<table class="meta">
    <tr><td class="label"><?= $h('Visit ID') ?></td><td><?= (int) $visitId ?></td></tr>
    <tr><td class="label"><?= $h('Senior') ?></td><td><?= $h(trim((string) ($detail['senior_full_name'] ?? ''))) ?></td></tr>
    <tr><td class="label"><?= $h('Pal') ?></td><td><?= $h(trim((string) ($detail['pal_full_name'] ?? ''))) ?></td></tr>
    <tr><td class="label"><?= $h('Service') ?></td><td><?= $h((string) ($detail['category_name'] ?? '')) ?></td></tr>
    <tr><td class="label"><?= $h('Scheduled') ?></td><td><?= $h((string) ($detail['scheduled_start'] ?? '')) ?> — <?= $h((string) ($detail['scheduled_end'] ?? '')) ?></td></tr>
    <tr><td class="label"><?= $h('Checkout') ?></td><td><?= $h((string) ($detail['actual_checkout'] ?? '')) ?></td></tr>
    <tr><td class="label"><?= $h('Points reserved') ?></td><td><?= (int) ($detail['points_reserved'] ?? 0) ?></td></tr>
    <tr><td class="label"><?= $h('Points paid (Pal)') ?></td><td><?= (int) ($detail['points_paid'] ?? 0) ?></td></tr>
    <tr><td class="label"><?= $h('Visit notes') ?></td><td class="block"><?= $h((string) ($detail['task_details'] ?? '')) ?></td></tr>
</table>

<h2><?= $h('Pal-submitted reports') ?></h2>
<?php if ($reports): ?>
    <?php foreach ($reports as $rp): ?>
        <p><strong><?= $h((string) ($rp['phase'] ?? '')) ?></strong>
            <span class="muted"><?= $h((string) ($rp['created_at'] ?? '')) ?></span></p>
        <div class="block"><?= nl2br($h((string) ($rp['body'] ?? ''))) ?></div>
    <?php endforeach; ?>
<?php else: ?>
    <p class="muted"><?= $h('No separate report rows (details may appear in visit notes).') ?></p>
<?php endif; ?>
</body>
</html>
<?php
$html = (string) ob_get_clean();

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF library is missing. Run `composer install` in the senior_care folder.';
    exit;
}

require_once $autoload;

try {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $fname = 'CareNest-visit-' . $visitId . '-report.pdf';
    $dompdf->stream($fname, ['Attachment' => true]);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Could not generate PDF.';
    exit;
}
