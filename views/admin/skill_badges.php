<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
require_once dirname(__DIR__, 2) . '/models/SkillBadge.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $badgeId = (int) ($_POST['badge_id'] ?? 0);
        if ($badgeId <= 0) {
            throw new InvalidArgumentException('Missing badge reference.');
        }
        if ((string) ($_POST['decision'] ?? '') === 'approve') {
            SkillBadge::verify($badgeId, currentUserId());
            $msg = 'Skill badge verified.';
        } elseif ((string) ($_POST['decision'] ?? '') === 'reject') {
            $reason = substr(trim((string) ($_POST['reject_reason'] ?? '')), 0, 500);
            SkillBadge::reject($badgeId, $reason);
            $msg = 'Skill badge marked as rejected.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}

$list = SkillBadge::pendingForAdmin();

$pageTitle = 'Skill Badges — CareNest';
$active = 'badges';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">

    <?php if ($msg !== ''): ?>
        <?php
        $msgL = strtolower($msg);
        $isSuccess = $msgL === 'skill badge verified.' || $msgL === 'skill badge marked as rejected.';
        ?>
        <div class="alert-cn <?= $isSuccess ? 'alert-cn-success' : 'alert-cn-danger' ?>"><?= e($msg) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body">
        <h1 class="h4 mb-2"><?= e('Skill badge reviews') ?></h1>
        <p class="small mb-4" style="color:var(--text-secondary);"><?= e('Review uploaded credentials and verify or reject each badge. Pals are notified of your decision.') ?></p>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                <tr>
                    <th><?= e('Pal') ?></th>
                    <th><?= e('Badge') ?></th>
                    <th><?= e('Submitted') ?></th>
                    <th><?= e('Certificate') ?></th>
                    <th><?= e('Actions') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <?php
                    $bid = (int) ($row['badge_ID'] ?? 0);
                    $palLabel = trim(($row['pal_fname'] ?? '') . ' ' . ($row['pal_lname'] ?? ''));
                    if ($palLabel === '') {
                        $palLabel = '—';
                    }
                    $submitted = (string) ($row['created_at'] ?? '');
                    if ($submitted !== '') {
                        try {
                            $submitted = date('M j, Y g:i a', strtotime($submitted));
                        } catch (Throwable $e) {
                        }
                    } else {
                        $submitted = '—';
                    }
                    $certUrl = trim((string) ($row['certificate_url'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= e($palLabel) ?></div>
                            <div class="small" style="color:var(--text-secondary);"><?= e((string) ($row['pal_email'] ?? '')) ?></div>
                            <div class="small" style="color:var(--text-light);"><?= e('Pal profile: ' . (string) ($row['pal_profile_status'] ?? '—')) ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= e((string) ($row['badge_name'] ?? '—')) ?></div>
                            <?php $desc = trim((string) ($row['description'] ?? '')); ?>
                            <?php if ($desc !== ''): ?>
                                <div class="small mt-1" style="color:var(--text-secondary);"><?= e($desc) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small" style="color:var(--text-secondary);"><?= e($submitted) ?></td>
                        <td>
                            <?php if ($certUrl !== ''): ?>
                                <a href="<?= e(carenest_url($certUrl)) ?>" target="_blank" rel="noopener noreferrer" class="small"><?= e('Open file') ?></a>
                            <?php else: ?>
                                <span class="small" style="color:var(--text-secondary);"><?= e('—') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: top; min-width: 14rem;">
                            <form method="post" class="mb-2">
                                <input type="hidden" name="badge_id" value="<?= $bid ?>">
                                <input type="hidden" name="decision" value="approve">
                                <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm" style="border-color:var(--success);"><?= e('Verify badge') ?></button>
                            </form>
                            <form method="post" class="d-flex flex-column gap-2">
                                <input type="hidden" name="badge_id" value="<?= $bid ?>">
                                <input type="hidden" name="decision" value="reject">
                                <textarea class="cn-input" rows="2" name="reject_reason" placeholder="<?= e('Reason for rejection') ?>" style="font-size:.95rem;"></textarea>
                                <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm" style="border-color:var(--danger);"><?= e('Reject') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$list): ?>
                    <tr><td colspan="5" style="color:var(--text-secondary);"><?= e('No skill badges awaiting review.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
