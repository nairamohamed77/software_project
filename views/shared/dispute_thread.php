<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireLogin();
$role = currentRole();
$disputeId = (int) ($_GET['dispute_id'] ?? 0);
if ($disputeId <= 0) {
    header('Location: ' . carenest_url('views/shared/error.php?code=400'));
    exit;
}

require_once dirname(__DIR__, 2) . '/models/Dispute.php';
require_once dirname(__DIR__, 2) . '/models/VisitReport.php';
require_once dirname(__DIR__, 2) . '/models/SystemAudit.php';

$d = Dispute::findWithVisit($disputeId);
if (!$d) {
    header('Location: ' . carenest_url('views/shared/error.php?code=404'));
    exit;
}

$visitId = (int) ($d['visit_ID'] ?? 0);
$isAdmin = $role === 'Admin';
$isParty = in_array($role, ['Senior', 'Pal', 'FamilyProxy'], true) && Dispute::userIsPartyToVisit(currentUserId(), $role, $d);
if (!$isAdmin && !$isParty) {
    header('Location: ' . carenest_url('views/shared/error.php?code=403'));
    exit;
}

SystemAudit::ensureTable();
$caseAudit = SystemAudit::forEntity('dispute', $disputeId, 200);
$reports = VisitReport::listByVisit($visitId);
$emergency = Dispute::emergencyMessagesForVisit($visitId);

$back = match ($role) {
    'Senior' => carenest_url('views/senior/visit_history.php'),
    'Pal' => carenest_url('views/pal/schedule.php'),
    'FamilyProxy' => carenest_url('views/proxy/visit_history.php'),
    'Admin' => carenest_url('views/admin/disputes.php?id=' . $disputeId),
    default => carenest_url('views/shared/error.php?code=403'),
};

$pageTitle = 'Dispute #' . $disputeId . ' — CareNest';
$active = '';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php
if (!$isAdmin) {
    $sidebar = match ($role) {
        'Senior' => 'sidebar_senior.php',
        'Pal' => 'sidebar_pal.php',
        'FamilyProxy' => 'sidebar_proxy.php',
        default => null,
    };
    if ($sidebar) {
        include dirname(__DIR__) . '/layouts/' . $sidebar;
    }
} else {
    include dirname(__DIR__) . '/layouts/sidebar_admin.php';
}
?>

<main class="main-content">
    <?php if (isset($_GET['created'])): ?>
        <div class="alert-cn alert-cn-success mb-3"><?= e('Dispute submitted. Admins have been notified.') ?></div>
    <?php endif; ?>
    <div class="cn-card cn-card-body mb-4">
        <h1 class="h5 mb-2"><?= e('Dispute') ?> #<?= $disputeId ?> — <?= e('Visit') ?> #<?= $visitId ?></h1>
        <div class="small mb-2" style="color:var(--text-secondary);">
            <?= e('Status') ?>: <strong><?= e((string) ($d['status'] ?? '')) ?></strong>
            <?php if (!empty($d['resolution'])): ?> · <?= e('Resolution') ?>: <strong><?= e((string) $d['resolution']) ?></strong><?php endif; ?>
        </div>
        <p class="mb-1"><strong><?= e('Raised by') ?>:</strong> <?= e(trim((string) ($d['raised_by_name'] ?? ''))) ?></p>
        <p class="small" style="color:var(--text-secondary);"><?= nl2br(e((string) ($d['description'] ?? ''))) ?></p>
        <?php if (!empty($d['evidence_url'])): ?>
            <p class="mt-2 mb-0"><a href="<?= e(carenest_url((string) $d['evidence_url'])) ?>" target="_blank" rel="noopener"><?= e('View evidence file') ?></a></p>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <a class="cn-btn cn-btn-outline cn-btn-sm mt-3" href="<?= e(carenest_url('views/admin/disputes.php?id=' . $disputeId)) ?>"><?= e('Admin resolution desk') ?></a>
        <?php endif; ?>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h2 class="h6 mb-3"><?= e('Visit summary') ?></h2>
        <p class="small mb-1"><strong><?= e('Visit status') ?>:</strong> <?= e((string) ($d['visit_status'] ?? '')) ?></p>
        <p class="small mb-1"><strong><?= e('Scheduled') ?>:</strong> <?= e((string) ($d['scheduled_start'] ?? '')) ?></p>
        <p class="small mb-1"><strong><?= e('Points reserved / paid') ?>:</strong> <?= (int) ($d['points_reserved'] ?? 0) ?> / <?= (int) ($d['points_paid'] ?? 0) ?></p>
        <p class="small mb-0"><?= nl2br(e((string) ($d['task_details'] ?? ''))) ?></p>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h2 class="h6 mb-3"><?= e('Pal visit reports (summary / notes)') ?></h2>
        <?php if ($reports): ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($reports as $rp): ?>
                    <li class="mb-2 pb-2 border-bottom" style="border-color:var(--border)!important;">
                        <span class="badge-status badge-pending"><?= e((string) ($rp['phase'] ?? '')) ?></span>
                        <span class="small text-secondary"><?= e((string) ($rp['created_at'] ?? '')) ?></span>
                        <div class="mt-1 small"><?= nl2br(e((string) ($rp['body'] ?? ''))) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="small text-secondary mb-0"><?= e('No Pal reports on file for this visit.') ?></p>
        <?php endif; ?>
    </div>

    <?php if ($emergency): ?>
        <div class="cn-card cn-card-body mb-4">
            <h2 class="h6 mb-3"><?= e('Emergency thread messages (if any)') ?></h2>
            <ul class="list-unstyled mb-0 small">
                <?php foreach ($emergency as $em): ?>
                    <li class="mb-2"><?= e((string) ($em['created_at'] ?? '')) ?> — <?= e((string) ($em['message_type'] ?? '')) ?>: <?= nl2br(e((string) ($em['message_text'] ?? ''))) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4">
        <h2 class="h6 mb-3"><?= e('Case audit trail') ?></h2>
        <p class="small text-secondary mb-3"><?= e('Dispute chat was retired; actions and admin requests are recorded below. Use notifications and visit reports to add context.') ?></p>
        <?php foreach ($caseAudit as $a): ?>
            <div class="mb-3 pb-3 border-bottom" style="border-color:var(--border)!important;">
                <span class="small text-secondary"><?= e((string) ($a['created_at'] ?? '')) ?></span>
                <strong class="d-block"><?= e((string) ($a['action_type'] ?? '')) ?></strong>
                <span class="small"><?= e(trim((string) ($a['actor_name'] ?? ''))) ?></span>
                <?php if (!empty($a['details'])): ?>
                    <div class="mt-1 small"><?= nl2br(e((string) $a['details'])) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$caseAudit): ?>
            <p class="small text-secondary mb-0"><?= e('No audit entries yet.') ?></p>
        <?php endif; ?>
    </div>

    <a href="<?= e($back) ?>" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Back') ?></a>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
