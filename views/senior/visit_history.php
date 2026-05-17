<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Senior');
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/Dispute.php';

$sProfile = Senior::profileByUserId(currentUserId());
$sId = $sProfile !== null ? (int) ($sProfile['senior_ID'] ?? 0) : 0;

$pageTitle = 'Visit History — CareNest';
$active = 'history';
$db = Database::getInstance()->getConnection();
$stmt = $db->prepare(
    '
    SELECT vr.*, sc.category_name,
           CONCAT(IFNULL(pe.Fname,"")," ", IFNULL(pe.Lname,"")) AS pal_name,
           r.rating_score AS rating_given,
           r.comment AS rating_comment
    FROM visit_requests vr
    LEFT JOIN service_categories sc ON vr.category_ID = sc.category_ID
    LEFT JOIN pal_profiles pp ON vr.pal_ID = pp.pal_ID
    LEFT JOIN users pe ON pp.User_ID = pe.User_ID
    LEFT JOIN ratings r ON r.visit_ID = vr.visit_ID
    WHERE vr.senior_ID = ?
    ORDER BY vr.scheduled_start DESC
    LIMIT 200
    ');
$stmt->execute([$sId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function cn_status_badge_class(?string $s): string {
    $t = strtolower(trim((string) $s));
    return match ($t) {
        'pending' => 'badge-pending',
        'accepted' => 'badge-accepted',
        'en-route' => 'badge-en-route',
        'live' => 'badge-live',
        'completed' => 'badge-completed',
        'cancelled' => 'badge-cancelled',
        default => 'badge-pending',
    };
}

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_senior.php'; ?>

<main class="main-content">
    <?php include dirname(__DIR__) . '/layouts/panic_button.php'; ?>
    <?php if (isset($_GET['booked'])): ?>
        <div class="alert-cn alert-cn-success mb-4"><?= e('Visit requested! Your Pal will confirm soon.') ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body">
        <h1 class="h4 mb-4"><?= e('Past and upcoming visits') ?></h1>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                <tr>
                    <th><?= e('Service') ?></th>
                    <th><?= e('Pal') ?></th>
                    <th><?= e('When') ?></th>
                    <th><?= e('Points') ?></th>
                    <th><?= e('Status') ?></th>
                    <th><?= e('Rate Pal') ?></th>
                    <th><?= e('Dispute') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $vr): ?>
                    <?php
                    $vid = (int) ($vr['visit_ID'] ?? 0);
                    $stRow = strtolower(trim((string) ($vr['status'] ?? '')));
                    $done = $stRow === 'completed';
                    $hasPal = (int) ($vr['pal_ID'] ?? 0) > 0;
                    $given = $vr['rating_given'] ?? null;
                    $rated = $given !== null && $given !== '';
                    Dispute::ensureTables();
                    $canDispute = $hasPal && $vr && Dispute::visitEligibleForDispute($vr) && !Dispute::hasOpenDispute($vid);
                    $openD = Dispute::hasOpenDispute($vid) ? Dispute::openDisputeIdForVisit($vid) : null;
                    ?>
                    <tr>
                        <td><?= e(trim((string) ($vr['category_name'] ?? 'Service'))) ?></td>
                        <td><?= e(trim((string) ($vr['pal_name'] ?? ''))) ?></td>
                        <td><?= e((string) ($vr['scheduled_start'] ?? '')) ?></td>
                        <td><?= (int) ($vr['points_reserved'] ?? 0) ?></td>
                        <td><span class="badge-status <?= e(cn_status_badge_class(isset($vr['status']) ? (string) $vr['status'] : null)) ?>">
                                <?= e((string) ($vr['status'] ?? '')) ?></span></td>
                        <td>
                            <?php if ($done && $hasPal): ?>
                                <?php if ($rated): ?>
                                    <div class="small">
                                        <strong><?= e(number_format((float) $given, 1)) ?>/5</strong>
                                        <?php if (!empty($vr['rating_comment'])): ?>
                                            <div class="text-secondary mt-1" style="max-width:12rem;"><?= e((string) $vr['rating_comment']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-1 cn-rate-box" data-visit-id="<?= $vid ?>" style="min-width:10rem;">
                                        <label class="small text-secondary mb-0"><?= e('Stars') ?></label>
                                        <select class="cn-input cn-input-sm cn-rate-stars" aria-label="<?= e('Rating') ?>">
                                            <option value=""><?= e('Choose…') ?></option>
                                            <?php for ($s = 5; $s >= 1; $s--): ?>
                                                <option value="<?= $s ?>"><?= $s ?> — <?= $s === 5 ? e('Excellent') : ($s === 4 ? e('Very good') : ($s === 3 ? e('Good') : ($s === 2 ? e('Fair') : e('Poor')))) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <input type="text" class="cn-input cn-input-sm cn-rate-comment" maxlength="4000" placeholder="<?= e('Optional comment') ?>">
                                        <button type="button" class="cn-btn cn-btn-primary cn-btn-sm cn-rate-submit"><?= e('Submit rating') ?></button>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($openD): ?>
                                <a href="<?= e(carenest_url('views/shared/dispute_thread.php?dispute_id=' . $openD)) ?>"><?= e('Open case') ?></a>
                            <?php elseif ($canDispute): ?>
                                <a href="<?= e(carenest_url('views/shared/raise_dispute.php?visit_id=' . $vid)) ?>"><?= e('File dispute') ?></a>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
document.querySelector('.cn-table-wrap')?.addEventListener('click', function (ev) {
    const btn = ev.target.closest('.cn-rate-submit');
    if (!btn) return;
    const box = btn.closest('.cn-rate-box');
    if (!box) return;
    const visitId = box.getAttribute('data-visit-id') || '';
    const sel = box.querySelector('.cn-rate-stars');
    const stars = parseInt(sel && sel.value ? sel.value : '0', 10);
    if (!stars) {
        alert('<?= e('Please choose a star rating first.') ?>');
        return;
    }
    const cmt = box.querySelector('.cn-rate-comment');
    const fd = new FormData();
    fd.append('visit_id', visitId);
    fd.append('stars', String(stars));
    fd.append('comment', cmt && cmt.value ? cmt.value : '');
    btn.disabled = true;
    fetch('<?= carenest_url('ajax/rate_visit.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.success) {
                location.reload();
                return;
            }
            alert(j.message || 'Could not save rating.');
            btn.disabled = false;
        })
        .catch(function () {
            alert('Network error.');
            btn.disabled = false;
        });
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
