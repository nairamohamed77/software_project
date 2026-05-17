<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
require_once dirname(__DIR__, 2) . '/models/BackgroundCheck.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $palIdProfile = (int) ($_POST['pal_id'] ?? 0);
        if (($palIdProfile) <= 0) {
            throw new InvalidArgumentException('Missing Pal profile reference.');
        }
        if ((string) ($_POST['decision'] ?? '') === 'approve') {
            BackgroundCheck::approvePal($palIdProfile, currentUserId());
            $msg = 'Pal approved and unlocked.';
        } elseif ((string) ($_POST['decision'] ?? '') === 'reject') {
            $reason = substr(trim((string) ($_POST['reject_reason'] ?? '')), 0, 500);
            if ($reason === '') {
                throw new InvalidArgumentException('Reason required.');
            }
            BackgroundCheck::rejectPal($palIdProfile, $reason);
            $msg = 'Pal dossier flagged for follow-up.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}

$list = BackgroundCheck::pendingForAdmin();

$pageTitle = 'Background Checks — CareNest';
$active = 'checks';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">

    <?php if ($msg !== ''): ?>
        <?php $isFailure = strtolower($msg) !== 'pal approved and unlocked.' && strtolower($msg) !== 'pal dossier flagged for follow-up.'; ?>
        <div class="alert-cn <?= $isFailure ? 'alert-cn-danger' : 'alert-cn-success' ?>"><?= e($msg) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body">
        <h1 class="h4 mb-4"><?= e('Supporting documentation review desk') ?></h1>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                <tr>
                    <th><?= e('Pal name') ?></th>
                    <th><?= e('Record snapshot') ?></th>
                    <th><?= e('Status cues') ?></th>
                    <th><?= e('Actions') ?></th>
                </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $row): ?>
                        <?php
                        $palPid = (int) ($row['profile_pal_id'] ?? 0);
                        $docHint = trim(
                            implode(
                                ' · ',
                                array_filter([
                                    $row['id_document_url'] ?? '',
                                    $row['criminal_record_url'] ?? '',
                                ])
                            )
                        );
                        if ($docHint === '') {
                            $docHint = '—';
                        }

                        ?>
                        <tr>
                            <td><?= e(trim(($row['pal_fname'] ?? '') . ' ' . ($row['pal_lname'] ?? ''))) ?></td>
                            <td style="font-size:.9rem;color:var(--text-secondary);"><?= e($docHint) ?></td>
                            <td><?= e((string) ($row['check_status']
                                    ?? ($row['status']
                                    ?? ($row['pal_verification_status'] ?? '')))) ?></td>
                            <td style="vertical-align: top;">
                                <?php $modalId = 'detailsModal' . $palPid; ?>
                                <button type="button" class="cn-btn cn-btn-outline cn-btn-sm mb-2"
                                        data-bs-toggle="modal" data-bs-target="#<?= e($modalId) ?>"><?= e('Show Details') ?></button>

                                <form method="post" class="my-3">
                                    <input type="hidden" name="pal_id" value="<?= (int) $palPid ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm me-2" style="border-color:var(--success);"><?= e('Approve') ?></button>
                                </form>

                                <form method="post" class="d-flex flex-column gap-3">
                                    <input type="hidden" name="pal_id" value="<?= (int) $palPid ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <textarea class="cn-input" rows="3" name="reject_reason" placeholder="<?= e('Thoughtful rationale for rework') ?>" style="font-size:.95rem;"></textarea>
                                    <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm" style="border-color:var(--danger);"><?= e('Reject with notes') ?></button>
                                </form>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="4" class="p-0 border-0">
                                <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content" style="background:var(--cream);border-radius:var(--radius-md);">
                                            <div class="modal-header">
                                                <h5 class="modal-title"><?= e('Pal Registration Details') ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e('Close') ?>"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-3" style="color:var(--text-secondary);">
                                                    <div class="col-md-6"><strong>Name:</strong> <?= e(trim(($row['pal_fname'] ?? '') . ' ' . ($row['pal_lname'] ?? ''))) ?></div>
                                                    <div class="col-md-6"><strong>Email:</strong> <?= e((string) ($row['pal_email'] ?? '—')) ?></div>
                                                    <div class="col-md-6"><strong>Phone:</strong> <?= e((string) ($row['pal_phone'] ?? '—')) ?></div>
                                                    <div class="col-md-6"><strong>National ID:</strong> <?= e((string) ($row['national_id_number'] ?? '—')) ?></div>
                                                    <div class="col-md-6"><strong>Skill Badge:</strong> <?= e((string) ($row['badge_name'] ?? '—')) ?></div>
                                                    <div class="col-md-6"><strong>Verification Status:</strong> <?= e((string) ($row['check_status'] ?? 'Pending')) ?></div>
                                                    <div class="col-12">
                                                        <strong>Uploaded Files:</strong>
                                                        <ul class="mb-0 mt-2">
                                                            <li>
                                                                <?php if (!empty($row['profile_photo_url'])): ?>
                                                                    <a href="<?= e(carenest_url((string) $row['profile_photo_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e('Personal photo') ?></a>
                                                                <?php else: ?>
                                                                    <?= e('Personal photo: —') ?>
                                                                <?php endif; ?>
                                                            </li>
                                                            <li>
                                                                <?php if (!empty($row['badge_certificate_url'])): ?>
                                                                    <a href="<?= e(carenest_url((string) $row['badge_certificate_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e('Skill badge certificate') ?></a>
                                                                <?php else: ?>
                                                                    <?= e('Skill badge certificate: —') ?>
                                                                <?php endif; ?>
                                                            </li>
                                                            <li>
                                                                <?php if (!empty($row['id_document_url'])): ?>
                                                                    <a href="<?= e(carenest_url((string) $row['id_document_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e('ID / background document') ?></a>
                                                                <?php else: ?>
                                                                    <?= e('ID / background document: —') ?>
                                                                <?php endif; ?>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$list): ?>
                        <tr><td colspan="4" style="color:var(--text-secondary);"><?= e('No dossiers awaiting review.') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="small mt-4" style="color:var(--text-secondary);">
            <?= e('Preview files from senior_care/uploads/documents respecting privacy policies.') ?></div>
    </div>

</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
