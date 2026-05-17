<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Pal');
require_once dirname(__DIR__, 2) . '/models/Pal.php';
require_once dirname(__DIR__, 2) . '/models/Visit.php';
require_once dirname(__DIR__, 2) . '/models/VisitReport.php';
require_once dirname(__DIR__, 2) . '/models/Dispute.php';
require_once dirname(__DIR__, 2) . '/models/VisitExtension.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID=? LIMIT 1');
$stmt->execute([currentUserId()]);
$pId = (int) ($stmt->fetch()['pal_ID'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitId = (int) ($_POST['visit_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($visitId > 0) {
        $vidPalCheck = Visit::byId($visitId);
        if (!$vidPalCheck || (int) ($vidPalCheck['pal_ID'] ?? 0) !== $pId) {
            http_response_code(403);
            exit('Forbidden');
        }
        try {
            if ($action === 'checkin') {
                Visit::checkIn($visitId, $pId);
            } elseif ($action === 'during_report') {
                $body = trim((string) ($_POST['during_body'] ?? ''));
                Visit::saveDuringProgress($visitId, $pId, $body);
            } elseif ($action === 'complete') {
                $after = trim((string) ($_POST['after_body'] ?? ''));
                Visit::completeVisitWithReport($visitId, $pId, $after);
                header('Location: ' . carenest_url('views/pal/visit_report_pdf.php?visit_id=' . $visitId));
                exit;
            } elseif ($action === 'request_extension') {
                VisitExtension::ensureTables();
                $mins = (int) ($_POST['extension_minutes'] ?? 30);
                VisitExtension::request($visitId, $pId, $mins, currentUserId());
                $_SESSION['flash_schedule_success'] = 'Extension request sent. The senior or family proxy will review it.';
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_schedule_error'] = $e->getMessage();
        }
    }
    $redView = (int) ($_POST['redirect_view'] ?? 0);
    $loc = 'views/pal/schedule.php';
    if ($redView > 0) {
        $loc .= '?view=' . $redView . '#extend';
    } elseif (isset($_GET['view'])) {
        $loc .= '?view=' . (int) ($_GET['view'] ?? 0);
    }
    header('Location: ' . carenest_url($loc));
    exit;
}

$pageTitle = 'Pal Schedule — CareNest';
$active = 'schedule';

$stmtVis = $db->prepare(
    "
    SELECT vr.*, CONCAT(IFNULL(u.Fname,''),' ',IFNULL(u.Lname,'')) AS senior_name,
           sc.category_name,
           e.points_locked AS escrow_points, e.status AS escrow_status
    FROM visit_requests vr
    JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
    JOIN users u ON sp.User_ID = u.User_ID
    JOIN service_categories sc ON vr.category_ID = sc.category_ID
    LEFT JOIN escrow e ON e.visit_ID = vr.visit_ID
    WHERE vr.pal_ID = ?
      AND DATE(vr.scheduled_start) >= CURDATE()-INTERVAL 1 DAY
      AND DATE(vr.scheduled_start) <= CURDATE()+INTERVAL 45 DAY
    ORDER BY vr.scheduled_start ASC
    LIMIT 200
    "
);
$stmtVis->execute([$pId]);
$lists = $stmtVis->fetchAll(PDO::FETCH_ASSOC) ?: [];

$viewId = (int) ($_GET['view'] ?? 0);
$detailVisit = null;
$detailReports = [];
$detailEscrow = null;
if ($viewId > 0) {
    $dv = Visit::byId($viewId);
    if ($dv && (int) ($dv['pal_ID'] ?? 0) === $pId) {
        $detailVisit = $dv;
        $detailReports = VisitReport::listByVisit($viewId);
        try {
            $es = $db->prepare('SELECT * FROM escrow WHERE visit_ID = ? LIMIT 1');
            $es->execute([$viewId]);
            $detailEscrow = $es->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $detailEscrow = null;
        }
    }
}

function cn_pal_visit_status_slug(string $s): string {
    return strtolower(str_replace('_', '-', trim($s)));
}

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_pal.php'; ?>

<main class="main-content">

    <?php if (!empty($_SESSION['flash_schedule_error'])): ?>
        <?php $er = $_SESSION['flash_schedule_error']; unset($_SESSION['flash_schedule_error']); ?>
        <div class="alert-cn alert-cn-danger mb-4"><?= e((string) $er) ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_schedule_success'])): ?>
        <?php $ok = $_SESSION['flash_schedule_success']; unset($_SESSION['flash_schedule_success']); ?>
        <div class="alert-cn alert-cn-success mb-4"><?= e((string) $ok) ?></div>
    <?php endif; ?>

    <p class="small mb-4" style="color:var(--text-secondary);">
        <?= e('A visit stays Live until you submit “Complete visit” with your after-report. The scheduled end time is for planning only — it does not close the visit.') ?>
    </p>

    <?php if ($detailVisit !== null): ?>
        <?php $detailIsLive = false; $detailExtPending = false; ?>
        <div class="cn-card cn-card-body mb-4">
            <h2 class="h5 mb-3"><?= e('Visit details') ?> #<?= (int) ($detailVisit['visit_ID'] ?? 0) ?></h2>
            <p style="color:var(--text-secondary);"><?= e((string) ($detailVisit['task_details'] ?? '')) ?></p>
            <?php if ($detailEscrow): ?>
                <div class="cn-summary-soft cn-card cn-card-body mt-3">
                    <strong><?= e('SilverPoints in escrow (held for this visit)') ?>:</strong>
                    <?= (int) ($detailEscrow['points_locked'] ?? 0) ?>
                    — <span class="text-secondary"><?= e((string) ($detailEscrow['status'] ?? '')) ?></span>
                </div>
            <?php endif; ?>
            <h3 class="h6 mt-4"><?= e('Pal reports (notes & after-visit)') ?></h3>
            <?php if ($detailReports): ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($detailReports as $rp): ?>
                        <li class="mb-3 pb-3 border-bottom" style="border-color:var(--border)!important;">
                            <span class="badge-status badge-pending"><?= e((string) ($rp['phase'] ?? '')) ?></span>
                            <span class="small text-secondary"><?= e((string) ($rp['created_at'] ?? '')) ?></span>
                            <div class="mt-1"><?= nl2br(e((string) ($rp['body'] ?? ''))) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="small text-secondary mb-0"><?= e('No reports filed yet.') ?></p>
            <?php endif; ?>
            <?php
            $dvid = (int) ($detailVisit['visit_ID'] ?? 0);
            $detailIsLive = false;
            $detailExtPending = false;
            if ($dvid > 0) {
                Dispute::ensureTables();
                $dOpen = Dispute::hasOpenDispute($dvid) ? Dispute::openDisputeIdForVisit($dvid) : null;
                $dCan = Dispute::visitEligibleForDispute($detailVisit) && !$dOpen;
                $detailIsLive = cn_pal_visit_status_slug((string) ($detailVisit['status'] ?? '')) === 'live';
                if ($detailIsLive) {
                    VisitExtension::ensureTables();
                    $detailExtPending = VisitExtension::hasPendingForVisit($dvid);
                }
            } else {
                $dOpen = null;
                $dCan = false;
            }
            ?>
            <?php if ($dOpen): ?>
                <p class="small mt-3 mb-0"><a href="<?= e(carenest_url('views/shared/dispute_thread.php?dispute_id=' . $dOpen)) ?>"><?= e('View open dispute case') ?></a></p>
            <?php elseif ($dCan): ?>
                <p class="small mt-3 mb-0"><a href="<?= e(carenest_url('views/shared/raise_dispute.php?visit_id=' . $dvid)) ?>"><?= e('File a dispute about this visit') ?></a></p>
            <?php endif; ?>
            <?php if (cn_pal_visit_status_slug((string) ($detailVisit['status'] ?? '')) === 'completed'): ?>
                <a class="cn-btn cn-btn-primary cn-btn-sm mt-3 me-2" href="<?= carenest_url('views/pal/visit_report_pdf.php?visit_id=' . $dvid) ?>"><?= e('Download PDF report') ?></a>
            <?php endif; ?>
            <a class="cn-btn cn-btn-outline cn-btn-sm mt-3" href="<?= carenest_url('views/pal/schedule.php') ?>"><?= e('Back to schedule') ?></a>
        </div>
        <?php if ($detailVisit !== null && !empty($detailIsLive)): ?>
            <div id="extend" class="cn-card cn-card-body mb-4">
                <h3 class="h6 mb-2"><?= e('Request visit extension') ?></h3>
                <p class="small text-secondary mb-3"><?= e('If you need more time to finish the visit, request extra minutes. The senior or family proxy must approve; SilverPoints are deducted from their balance when approved.') ?></p>
                <?php if (!empty($detailExtPending)): ?>
                    <p class="small text-secondary mb-0"><?= e('An extension request is already pending for this visit.') ?></p>
                <?php else: ?>
                    <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
                        <input type="hidden" name="visit_id" value="<?= (int) ($detailVisit['visit_ID'] ?? 0) ?>">
                        <input type="hidden" name="action" value="request_extension">
                        <input type="hidden" name="redirect_view" value="<?= (int) $viewId ?>">
                        <div>
                            <label class="form-label small mb-1"><?= e('Extra minutes') ?></label>
                            <select name="extension_minutes" class="cn-input">
                                <?php foreach ([15, 30, 45, 60, 90, 120, 150, 180] as $em): ?>
                                    <option value="<?= $em ?>" <?= $em === 30 ? ' selected' : '' ?>><?= $em ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm"><?= e('Send request') ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($lists as $row): ?>
        <?php
        $vid = (int) ($row['visit_ID'] ?? 0);
        $st = cn_pal_visit_status_slug((string) ($row['status'] ?? ''));
        $escPts = (int) ($row['escrow_points'] ?? 0);
        $escStat = (string) ($row['escrow_status'] ?? '');
        Dispute::ensureTables();
        $hasPalRow = (int) ($row['pal_ID'] ?? 0) > 0;
        $canDisp = $hasPalRow && Dispute::visitEligibleForDispute($row) && !Dispute::hasOpenDispute($vid);
        $openDisp = Dispute::hasOpenDispute($vid) ? Dispute::openDisputeIdForVisit($vid) : null;
        ?>
        <div class="cn-card cn-card-body mb-3">
            <div class="fw-bold"><?= e(trim((string) ($row['senior_name'] ?? ''))) ?></div>
            <div style="color:var(--text-secondary);"><?= e((string) ($row['category_name'] ?? '')) ?> <?= e((string) ($row['scheduled_start'] ?? '')) ?></div>
            <div class="mt-2"><?= e('Status') ?>: <strong><?= e((string) ($row['status'] ?? '')) ?></strong></div>
            <?php if ($escPts > 0): ?>
                <div class="small mt-2" style="color:var(--text-secondary);">
                    <?= e('SilverPoints held in database (escrow)') ?>: <strong><?= $escPts ?></strong>
                    <?php if ($escStat !== ''): ?><span> — <?= e($escStat) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mt-4 d-flex flex-column gap-3 align-items-stretch">
                <?php if ($st === 'accepted'): ?>
                    <form method="post" class="w-100">
                        <input type="hidden" name="visit_id" value="<?= $vid ?>">
                        <input type="hidden" name="action" value="during_report">
                        <label class="cn-label"><?= e('Write report') ?></label>
                        <textarea name="during_body" class="cn-input mb-2" rows="3" placeholder="<?= e('Plans, prep, route, or notes — available as soon as you accept. Add more anytime after check-in.') ?>"></textarea>
                        <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm"><?= e('Save report') ?></button>
                    </form>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <form method="post" class="d-inline m-0">
                            <input type="hidden" name="visit_id" value="<?= $vid ?>">
                            <input type="hidden" name="action" value="checkin">
                            <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Check-in') ?></button>
                        </form>
                        <span class="small" style="color:var(--text-secondary);"><?= e('Use check-in when you start the visit on site (moves status to Live).') ?></span>
                        <a href="<?= carenest_url('views/pal/schedule.php?view=' . $vid) ?>" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Details') ?></a>
                    </div>
                <?php elseif ($st === 'live'): ?>
                    <div class="d-flex flex-column flex-lg-row flex-wrap gap-3">
                    <form method="post" class="flex-grow-1" style="min-width:min(100%, 320px);">
                        <input type="hidden" name="visit_id" value="<?= $vid ?>">
                        <input type="hidden" name="action" value="during_report">
                        <label class="cn-label"><?= e('Write report (in-visit)') ?></label>
                        <textarea name="during_body" class="cn-input mb-2" rows="2" placeholder="<?= e('What you are doing, observations during the visit…') ?>"></textarea>
                        <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Save report') ?></button>
                    </form>
                    <form method="post" class="flex-grow-1" style="min-width:min(100%, 320px);">
                        <input type="hidden" name="visit_id" value="<?= $vid ?>">
                        <input type="hidden" name="action" value="complete">
                        <label class="cn-label"><?= e('After-visit report (required to finish)') ?></label>
                        <textarea name="after_body" class="cn-input mb-2" rows="3" required placeholder="<?= e('Summary after the visit: tasks done, senior condition, follow-ups…') ?>"></textarea>
                        <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm"><?= e('Complete visit') ?></button>
                    </form>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <a href="<?= carenest_url('views/pal/schedule.php?view=' . $vid) ?>" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Details') ?></a>
                        <a href="<?= carenest_url('views/pal/schedule.php?view=' . $vid . '#extend') ?>" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Request extension') ?></a>
                    </div>
                <?php else: ?>
                    <span class="small text-secondary"><?= e('No actions for this status on this screen.') ?></span>
                    <a href="<?= carenest_url('views/pal/schedule.php?view=' . $vid) ?>" class="cn-btn cn-btn-outline cn-btn-sm align-self-start"><?= e('Details') ?></a>
                <?php endif; ?>
                <div class="small mt-3" style="color:var(--text-secondary);">
                    <?php if ($openDisp): ?>
                        <a href="<?= e(carenest_url('views/shared/dispute_thread.php?dispute_id=' . $openDisp)) ?>"><?= e('Dispute case open') ?></a>
                    <?php elseif ($canDisp): ?>
                        <a href="<?= e(carenest_url('views/shared/raise_dispute.php?visit_id=' . $vid)) ?>"><?= e('File a dispute') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$lists): ?>
        <div class="cn-card cn-card-body" style="color:var(--text-secondary);"><?= e('No upcoming visits on your calendar yet.') ?></div>
    <?php endif; ?>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
