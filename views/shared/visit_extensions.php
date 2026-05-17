<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireLogin();
$role = currentRole();
if (!in_array($role, ['Senior', 'FamilyProxy'], true)) {
    header('Location: ' . carenest_url('views/shared/error.php?code=403'));
    exit;
}

require_once dirname(__DIR__, 2) . '/models/VisitExtension.php';

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['_action'] ?? '');
    $rid = (int) ($_POST['request_id'] ?? 0);
    try {
        if ($act === 'approve' && $rid > 0) {
            VisitExtension::approve($rid, currentUserId(), $role);
            $msg = 'Extension approved. Visit end time and SilverPoints were updated.';
        } elseif ($act === 'reject' && $rid > 0) {
            VisitExtension::reject($rid, currentUserId(), $role, (string) ($_POST['reject_reason'] ?? ''));
            $msg = 'Extension declined. The visit keeps its previous scheduled end time.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

VisitExtension::ensureTables();
$pending = VisitExtension::listPendingForSeniorHousehold(currentUserId(), $role);

$pageTitle = 'Visit extensions — CareNest';
$active = 'extensions';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php
$sidebar = $role === 'Senior' ? 'sidebar_senior.php' : 'sidebar_proxy.php';
include dirname(__DIR__) . '/layouts/' . $sidebar;
?>

<main class="main-content">
    <h1 class="h5 mb-3"><?= e('Visit time extensions') ?></h1>
    <p class="small text-secondary mb-4"><?= e('When your Pal needs more time during a Live visit, they can request extra minutes. Approve only if you agree; SilverPoints are charged from the senior household balance when you approve.') ?></p>

    <?php if ($msg !== ''): ?>
        <div class="alert-cn alert-cn-success mb-3"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div class="alert-cn alert-cn-danger mb-3"><?= e($err) ?></div>
    <?php endif; ?>

    <?php if (!$pending): ?>
        <div class="cn-card cn-card-body text-secondary small"><?= e('No pending extension requests.') ?></div>
    <?php else: ?>
        <?php foreach ($pending as $row): ?>
            <?php
            $rid = (int) ($row['request_ID'] ?? 0);
            $vid = (int) ($row['visit_ID'] ?? 0);
            $mins = (int) ($row['extra_minutes'] ?? 0);
            $pts = (int) ($row['extra_points'] ?? 0);
            $palName = trim((string) ($row['pal_name'] ?? ''));
            $cat = (string) ($row['category_name'] ?? '');
            $senLabel = trim((string) ($row['senior_name'] ?? ''));
            ?>
            <div class="cn-card cn-card-body mb-3">
                <div class="fw-bold mb-1"><?= e('Visit') ?> #<?= $vid ?> · <?= e($cat) ?></div>
                <?php if ($role === 'FamilyProxy' && $senLabel !== ''): ?>
                    <div class="small text-secondary mb-2"><?= e('Senior') ?>: <?= e($senLabel) ?></div>
                <?php endif; ?>
                <p class="small mb-2"><?= e('Pal') ?>: <strong><?= e($palName !== '' ? $palName : 'Pal') ?></strong></p>
                <p class="small mb-3"><?= e('Requested extra time') ?>: <strong><?= $mins ?></strong> <?= e('minutes') ?> ·
                    <strong><?= $pts ?></strong> <?= e('SilverPoints (estimate)') ?></p>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="_action" value="approve">
                        <input type="hidden" name="request_id" value="<?= $rid ?>">
                        <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm"><?= e('Approve') ?></button>
                    </form>
                    <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                        <input type="hidden" name="_action" value="reject">
                        <input type="hidden" name="request_id" value="<?= $rid ?>">
                        <input type="text" name="reject_reason" class="cn-input" style="min-width:220px;" required minlength="5" maxlength="500" placeholder="<?= e('Reason if declining…') ?>">
                        <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Decline') ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
