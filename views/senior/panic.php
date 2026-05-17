<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Senior');
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/Emergency.php';

$sProfile = Senior::profileByUserId(currentUserId());
$sId = $sProfile !== null ? (int) ($sProfile['senior_ID'] ?? 0) : 0;
$h = Senior::healthBySeniorId($sId) ?: [];

$confirmed = isset($_POST['confirm_panic']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirmed) {
    Emergency::triggerPanic(currentUserId(), $sId, $h ?? [], $_SESSION['panic_location_hint'] ?? null);
    $_SESSION['flash_panic_sent'] = 1;
    header('Location: ' . carenest_url('views/senior/panic.php?s=1'));
    exit;
}

if (isset($_GET['set_loc']) && ($_GET['set_loc']) !== '') {
    $_SESSION['panic_location_hint'] = substr((string) $_GET['set_loc'], 0, 200);
}

$sentFlash = ($_GET['s'] ?? '') === '1';

$pageTitle = 'Emergency Assistance — CareNest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= carenest_url('public/css/style.css') ?>">
</head>
<body class="panic-overlay">
<?php if ($sentFlash): ?>
    <div class="cn-card cn-card-body" style="background:var(--cream);color:var(--text-primary);max-width:520px;">
        <div class="h3 fw-bold"><?= e('Alert queued') ?></div>
        <p><?= e('Help is coordinating with responders, admins, proxies, and your active Pal (if visiting). Stay where you feel safest.') ?></p>
        <a class="cn-btn cn-btn-outline" href="<?= carenest_url('views/senior/dashboard.php') ?>"><?= e('Return Home') ?></a>
    </div>
<?php else: ?>
    <div class="cn-card cn-card-body" style="background:var(--cream);color:var(--text-primary);max-width:620px;">
        <div style="font-size:3rem;">&#128680;</div>
        <h1><?= e('EMERGENCY ALERT') ?></h1>
        <p><?= e('Send an urgent alert immediately? Proxies and CareNest responders will receive your latest comfort profile summary.') ?></p>
        <form method="post" class="mt-4">
            <input type="hidden" name="confirm_panic" value="1">
            <button type="submit" class="cn-btn cn-btn-block" style="background:var(--danger);font-size:1.2rem;color:var(--text-white);min-height:72px;">
                <?= e('Send emergency alert') ?>
            </button>
        </form>
        <div class="mt-4">
            <a href="<?= carenest_url('views/senior/dashboard.php') ?>" style="color:var(--text-secondary);font-weight:600;"><?= e('Cancel — return safely') ?></a>
        </div>
    </div>
<?php endif; ?>
</body>
</html>
