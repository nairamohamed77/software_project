<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireLogin();
$role = currentRole();
if (!in_array($role, ['Senior', 'Pal', 'FamilyProxy'], true)) {
    header('Location: ' . carenest_url('views/shared/error.php?code=403'));
    exit;
}

require_once dirname(__DIR__, 2) . '/models/Dispute.php';
require_once dirname(__DIR__, 2) . '/models/Visit.php';

$visitId = (int) ($_GET['visit_id'] ?? $_POST['visit_id'] ?? 0);
$msg = '';
$err = '';

$visit = $visitId > 0 ? Dispute::visitRow($visitId) : null;
if ($visitId > 0 && !$visit) {
    $err = 'Visit not found.';
}
$partyOk = $visit && Dispute::userIsPartyToVisit(currentUserId(), $role, $visit);
if ($visit && !$partyOk) {
    $err = 'You are not allowed to dispute this visit.';
}
$eligible = $visit && Dispute::visitEligibleForDispute($visit);
if ($visit && $partyOk && !$eligible) {
    $err = 'This visit cannot be disputed yet (needs an assigned Pal).';
}
$already = $visit && Dispute::hasOpenDispute($visitId);
$openDisputeId = $already ? Dispute::openDisputeIdForVisit($visitId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $partyOk && $eligible && !$already && $err === '') {
    try {
        $desc = (string) ($_POST['description'] ?? '');
        $evidenceUrl = null;
        if (!empty($_FILES['evidence']['name']) && is_uploaded_file((string) ($_FILES['evidence']['tmp_name'] ?? ''))) {
            $ext = strtolower(pathinfo((string) $_FILES['evidence']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) {
                throw new RuntimeException('Evidence file must be JPG, PNG, GIF, WebP, or PDF.');
            }
            $root = dirname(__DIR__, 2);
            $dir = $root . '/uploads/disputes/';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $fname = 'd_' . $visitId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $dest = $dir . $fname;
            if (!move_uploaded_file((string) $_FILES['evidence']['tmp_name'], $dest)) {
                throw new RuntimeException('Could not save evidence file.');
            }
            $evidenceUrl = 'uploads/disputes/' . $fname;
        }
        $newId = Dispute::create($visitId, currentUserId(), $desc, $evidenceUrl);
        header('Location: ' . carenest_url('views/shared/dispute_thread.php?dispute_id=' . $newId . '&created=1'));
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$back = match ($role) {
    'Senior' => carenest_url('views/senior/visit_history.php'),
    'Pal' => carenest_url('views/pal/schedule.php'),
    'FamilyProxy' => carenest_url('views/proxy/visit_history.php'),
    default => carenest_url('views/shared/error.php?code=403'),
};

$pageTitle = 'Raise dispute — CareNest';
$active = '';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php
$sidebar = match ($role) {
    'Senior' => 'sidebar_senior.php',
    'Pal' => 'sidebar_pal.php',
    'FamilyProxy' => 'sidebar_proxy.php',
    default => null,
};
if ($sidebar) {
    include dirname(__DIR__) . '/layouts/' . $sidebar;
}
?>

<main class="main-content">
    <div class="cn-card cn-card-body">
        <h1 class="h4 mb-3"><?= e('Raise a dispute') ?></h1>
        <p class="small mb-4" style="color:var(--text-secondary);"><?= e('Describe the issue. An admin will review the visit summary, Pal notes, and any evidence. SilverPoints may be adjusted if the admin rules in your favor.') ?></p>

        <?php if ($err !== ''): ?>
            <div class="alert-cn alert-cn-danger mb-3"><?= e($err) ?></div>
        <?php endif; ?>
        <?php if ($already && $openDisputeId): ?>
            <div class="alert-cn alert-cn-success mb-3"><?= e('A dispute is already open for this visit.') ?>
                <a href="<?= e(carenest_url('views/shared/dispute_thread.php?dispute_id=' . $openDisputeId)) ?>" class="alert-link"><?= e('View case') ?></a>
            </div>
        <?php endif; ?>

        <?php if ($visit && $partyOk && $eligible && !$already): ?>
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="visit_id" value="<?= $visitId ?>">
                <div class="col-12">
                    <label class="form-label small"><?= e('What went wrong?') ?></label>
                    <textarea name="description" class="cn-input" rows="6" required minlength="10" maxlength="12000" placeholder="<?= e('Be specific: dates, what was promised vs delivered, safety, payment…') ?>"></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label small"><?= e('Photo / PDF evidence (optional)') ?></label>
                    <input type="file" name="evidence" class="form-control cn-input border" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                </div>
                <div class="col-12">
                    <button type="submit" class="cn-btn cn-btn-outline"><?= e('Submit dispute') ?></button>
                    <a href="<?= e($back) ?>" class="cn-btn cn-btn-outline ms-2"><?= e('Cancel') ?></a>
                </div>
            </form>
        <?php else: ?>
            <a href="<?= e($back) ?>" class="cn-btn cn-btn-outline"><?= e('Back') ?></a>
        <?php endif; ?>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
