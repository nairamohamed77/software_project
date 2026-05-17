<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
requirePermission('manage_welfare_checks');
require_once dirname(__DIR__, 2) . '/models/WelfareInactivity.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['_action'] ?? '');
    try {
        if ($act === 'run_scan') {
            $days = (int) ($_POST['inactivity_days'] ?? WelfareInactivity::DEFAULT_INACTIVITY_DAYS);
            $r = WelfareInactivity::runScan($days);
            $msg = 'Scan complete: ' . $r['scanned'] . ' inactive senior(s) matched; ' . $r['created'] . ' new alert(s); ' . $r['skipped_open'] . ' skipped (open case already).';
        } elseif ($act === 'update_case') {
            $cid = (int) ($_POST['check_id'] ?? 0);
            $st = (string) ($_POST['new_status'] ?? '');
            WelfareInactivity::updateStatus($cid, currentUserId(), $st, (string) ($_POST['notes'] ?? ''));
            $msg = 'Welfare check updated.';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$list = WelfareInactivity::listForAdmin(120);
$openCount = WelfareInactivity::countOpenCases();

$pageTitle = 'Welfare checks — CareNest';
$active = 'welfare';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">
    <h1 class="h5 mb-2"><?= e('Inactive user welfare') ?></h1>

    <?php if ($msg !== ''): ?>
        <div class="alert-cn alert-cn-success mb-3"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
        <div class="alert-cn alert-cn-danger mb-3"><?= e($err) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
                <strong><?= e('Open cases') ?>:</strong> <?= (int) $openCount ?>
            </div>
            <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                <input type="hidden" name="_action" value="run_scan">
                <div>
                    <label class="form-label small mb-0"><?= e('Inactivity (days)') ?></label>
                    <input type="number" name="inactivity_days" class="cn-input" style="width:5rem;" min="1" max="90" value="<?= (int) WelfareInactivity::DEFAULT_INACTIVITY_DAYS ?>">
                </div>
                <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Run scan now') ?></button>
            </form>
        </div>
    </div>

    <div class="cn-table-wrap">
        <table class="cn-table mb-0">
            <thead>
            <tr>
                <th><?= e('ID') ?></th>
                <th><?= e('Senior') ?></th>
                <th><?= e('Last login') ?></th>
                <th><?= e('Trigger') ?></th>
                <th><?= e('Status') ?></th>
                <th><?= e('When') ?></th>
                <th><?= e('Update') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($list as $row): ?>
                <?php
                $cid = (int) ($row['check_ID'] ?? 0);
                $st = (string) ($row['status'] ?? '');
                ?>
                <tr>
                    <td><?= $cid ?></td>
                    <td>
                        <div class="fw-semibold"><?= e(trim((string) ($row['senior_name'] ?? ''))) ?></div>
                        <div class="small text-secondary"><?= e((string) ($row['senior_email'] ?? '')) ?> · senior_ID <?= (int) ($row['senior_ID'] ?? 0) ?></div>
                    </td>
                    <td class="small"><?= e((string) ($row['senior_last_login'] ?? '')) ?></td>
                    <td class="small" style="max-width:220px;"><?= e((string) ($row['trigger_reason'] ?? '')) ?></td>
                    <td><span class="badge-status <?= $st === 'Resolved' ? 'badge-completed' : 'badge-pending' ?>"><?= e($st) ?></span></td>
                    <td class="small"><?= e((string) ($row['created_at'] ?? '')) ?></td>
                    <td style="min-width:240px;">
                        <form method="post" class="d-flex flex-column gap-1">
                            <input type="hidden" name="_action" value="update_case">
                            <input type="hidden" name="check_id" value="<?= $cid ?>">
                            <select name="new_status" class="cn-input">
                                <?php foreach (['Pending', 'Contacted', 'Resolved', 'Escalated'] as $opt): ?>
                                    <option value="<?= e($opt) ?>" <?= $st === $opt ? ' selected' : '' ?>><?= e($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <textarea name="notes" class="cn-input" rows="2" placeholder="<?= e('Notes (required for Resolved / Escalated)') ?>"><?= e((string) ($row['resolution_notes'] ?? '')) ?></textarea>
                            <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Save') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
                <tr><td colspan="7" class="text-secondary"><?= e('No welfare check records yet.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
