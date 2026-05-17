<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
require_once dirname(__DIR__, 2) . '/models/Dispute.php';
require_once dirname(__DIR__, 2) . '/models/VisitReport.php';

$msg = '';
$err = '';
$detailId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $detailId > 0) {
    $action = (string) ($_POST['_action'] ?? '');
    try {
        if ($action === 'request_info') {
            Dispute::requestMoreInfo($detailId, currentUserId(), (string) ($_POST['info_note'] ?? ''));
            $msg = 'Parties were asked for more information.';
        } elseif ($action === 'release_pal') {
            Dispute::resolveReleasePal($detailId, currentUserId(), (string) ($_POST['resolution_notes'] ?? ''));
            $msg = 'Resolved: Pal payment stands (no SilverPoints clawback).';
        } elseif ($action === 'refund_senior') {
            Dispute::resolveRefundSenior($detailId, currentUserId(), (string) ($_POST['resolution_notes'] ?? ''));
            $msg = 'Resolved: SilverPoints returned to senior household (clawed from Pal up to available balance).';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$list = Dispute::listForAdmin(120);
$detail = $detailId > 0 ? Dispute::findWithVisit($detailId) : null;
if ($detailId > 0 && !$detail) {
    $err = $err === '' ? 'Dispute not found.' : $err;
}
$detailAudit = $detail ? Dispute::auditLog($detailId) : [];
$detailReports = $detail ? VisitReport::listByVisit((int) ($detail['visit_ID'] ?? 0)) : [];
$detailEmergency = $detail ? Dispute::emergencyMessagesForVisit((int) ($detail['visit_ID'] ?? 0)) : [];

$pageTitle = 'Disputes — CareNest';
$active = 'disputes';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">
    <?php if ($msg !== ''): ?>
        <div class="alert-cn alert-cn-success mb-3"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div class="alert-cn alert-cn-danger mb-3"><?= e($err) ?></div>
    <?php endif; ?>

    <?php if ($detail): ?>
        <div class="cn-card cn-card-body mb-4">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
                <h1 class="h5 mb-0"><?= e('Dispute') ?> #<?= $detailId ?> · <?= e('Visit') ?> #<?= (int) ($detail['visit_ID'] ?? 0) ?></h1>
                <a href="<?= e(carenest_url('views/admin/disputes.php')) ?>" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('All disputes') ?></a>
            </div>
            <p class="small mb-2"><strong><?= e('Case status') ?>:</strong> <?= e((string) ($detail['status'] ?? '')) ?>
                <?php if (!empty($detail['resolution'])): ?> · <strong><?= e((string) $detail['resolution']) ?></strong><?php endif; ?></p>
            <p class="small mb-2"><strong><?= e('Visit status') ?>:</strong> <?= e((string) ($detail['visit_status'] ?? '')) ?></p>
            <p class="small mb-2"><strong><?= e('Raised by') ?>:</strong> <?= e(trim((string) ($detail['raised_by_name'] ?? ''))) ?> (<?= e((string) ($detail['raised_by_email'] ?? '')) ?>)</p>
            <p class="small mb-2"><strong><?= e('SilverPoints') ?>:</strong> <?= e('reserved') ?> <?= (int) ($detail['points_reserved'] ?? 0) ?> · <?= e('paid to Pal') ?> <?= (int) ($detail['points_paid'] ?? 0) ?></p>
            <div class="mt-3">
                <strong><?= e('Complaint') ?></strong>
                <div class="small mt-1" style="color:var(--text-secondary);"><?= nl2br(e((string) ($detail['description'] ?? ''))) ?></div>
            </div>
            <?php if (!empty($detail['evidence_url'])): ?>
                <p class="mt-2 mb-0"><a href="<?= e(carenest_url((string) $detail['evidence_url'])) ?>" target="_blank" rel="noopener"><?= e('Evidence file') ?></a></p>
            <?php endif; ?>
        </div>

        <div class="cn-card cn-card-body mb-4">
            <h2 class="h6 mb-3"><?= e('Visit summary') ?></h2>
            <p class="small mb-1"><?= e((string) ($detail['scheduled_start'] ?? '')) ?> → <?= e((string) ($detail['scheduled_end'] ?? '')) ?></p>
            <p class="small mb-0"><?= nl2br(e((string) ($detail['task_details'] ?? ''))) ?></p>
            <?php if (!empty($detail['special_instructions'])): ?>
                <p class="small mt-2 mb-0"><strong><?= e('Special instructions') ?>:</strong> <?= nl2br(e((string) $detail['special_instructions'])) ?></p>
            <?php endif; ?>
        </div>

        <div class="cn-card cn-card-body mb-4">
            <h2 class="h6 mb-3"><?= e('Pal reports') ?></h2>
            <?php if ($detailReports): ?>
                <?php foreach ($detailReports as $rp): ?>
                    <div class="mb-2 pb-2 border-bottom small" style="border-color:var(--border)!important;">
                        <span class="badge-status badge-pending"><?= e((string) ($rp['phase'] ?? '')) ?></span>
                        <?= e((string) ($rp['created_at'] ?? '')) ?>
                        <div class="mt-1"><?= nl2br(e((string) ($rp['body'] ?? ''))) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="small text-secondary mb-0"><?= e('No Pal reports.') ?></p>
            <?php endif; ?>
        </div>

        <?php if ($detailEmergency): ?>
            <div class="cn-card cn-card-body mb-4">
                <h2 class="h6 mb-3"><?= e('Emergency messages (visit-linked)') ?></h2>
                <?php foreach ($detailEmergency as $em): ?>
                    <div class="small mb-2"><?= e((string) ($em['created_at'] ?? '')) ?> — <?= nl2br(e((string) ($em['message_text'] ?? ''))) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="cn-card cn-card-body mb-4">
            <h2 class="h6 mb-3"><?= e('System audit trail (dispute)') ?></h2>
            <p class="small text-secondary mb-3"><?= e('Party chat was removed; UC-34 logs are shown here for this dispute.') ?></p>
            <?php foreach ($detailAudit as $a): ?>
                <div class="small mb-2 border-bottom pb-2" style="border-color:var(--border)!important;">
                    <?= e((string) ($a['created_at'] ?? '')) ?> — <strong><?= e((string) ($a['action_type'] ?? '')) ?></strong>
                    (<?= e(trim((string) ($a['actor_name'] ?? ''))) ?>)
                    <?php if (!empty($a['details'])): ?><div class="text-secondary"><?= e((string) $a['details']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$detailAudit): ?>
                <p class="small text-secondary mb-0"><?= e('No entries yet.') ?></p>
            <?php endif; ?>
        </div>

        <?php if (in_array((string) ($detail['status'] ?? ''), ['Open', 'Awaiting_Info'], true)): ?>
            <div class="cn-card cn-card-body mb-4">
                <h2 class="h6 mb-3"><?= e('Alternate flow — request more info') ?></h2>
                <form method="post" class="row g-2">
                    <input type="hidden" name="_action" value="request_info">
                    <div class="col-12">
                        <textarea name="info_note" class="cn-input" rows="3" required minlength="8" maxlength="7500" placeholder="<?= e('What do you need from the Senior, Pal, or Proxy?') ?>"></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Request more information') ?></button>
                    </div>
                </form>
            </div>

            <div class="cn-card cn-card-body mb-4">
                <h2 class="h6 mb-3"><?= e('Resolve — decision') ?></h2>
                <p class="small mb-3" style="color:var(--text-secondary);"><?= e('Refund senior: claw back up to the Pal’s current SilverPoints balance (capped by net paid / reserved), credit the senior household. Release Pal: close case with no point movement.') ?></p>

                <form method="post" class="mb-4 pb-4 border-bottom" style="border-color:var(--border)!important;">
                    <input type="hidden" name="_action" value="refund_senior">
                    <label class="form-label small"><?= e('Resolution notes (required)') ?></label>
                    <textarea name="resolution_notes" class="cn-input mb-2" rows="2" required minlength="5" maxlength="8000"></textarea>
                    <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm" style="border-color:var(--success);"><?= e('Refund senior (from Pal balance)') ?></button>
                </form>

                <form method="post">
                    <input type="hidden" name="_action" value="release_pal">
                    <label class="form-label small"><?= e('Resolution notes (required)') ?></label>
                    <textarea name="resolution_notes" class="cn-input mb-2" rows="2" required minlength="5" maxlength="8000"></textarea>
                    <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Release Pal (no clawback)') ?></button>
                </form>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="cn-card cn-card-body">
            <h1 class="h5 mb-4"><?= e('Mediate disputes (UC-31)') ?></h1>
            <div class="cn-table-wrap">
                <table class="cn-table mb-0">
                    <thead>
                    <tr>
                        <th><?= e('ID') ?></th>
                        <th><?= e('Visit') ?></th>
                        <th><?= e('Raised by') ?></th>
                        <th><?= e('Status') ?></th>
                        <th><?= e('When') ?></th>
                        <th><?= e('Action') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($list as $row): ?>
                        <?php $did = (int) ($row['dispute_ID'] ?? 0); ?>
                        <tr>
                            <td><?= $did ?></td>
                            <td>#<?= (int) ($row['visit_ID'] ?? 0) ?> · <?= e((string) ($row['visit_status'] ?? '')) ?></td>
                            <td><?= e(trim((string) ($row['raised_by_name'] ?? ''))) ?></td>
                            <td><span class="badge-status <?= (string) ($row['status'] ?? '') === 'Resolved' ? 'badge-completed' : 'badge-pending' ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                            <td class="small"><?= e((string) ($row['created_at'] ?? '')) ?></td>
                            <td><a class="cn-btn cn-btn-outline cn-btn-sm" href="<?= e(carenest_url('views/admin/disputes.php?id=' . $did)) ?>"><?= e('Review') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$list): ?>
                        <tr><td colspan="6" class="text-secondary"><?= e('No disputes yet.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
