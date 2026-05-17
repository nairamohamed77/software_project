<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Pal');
require_once dirname(__DIR__, 2) . '/models/Pal.php';
require_once dirname(__DIR__, 2) . '/models/Notification.php';

function cn_pal_dash_visit_status_slug(string $s): string {
    return strtolower(str_replace('_', '-', trim($s)));
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT * FROM pal_profiles WHERE User_ID=? LIMIT 1');
$stmt->execute([currentUserId()]);
$p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$pId = isset($p['pal_ID']) ? (int) $p['pal_ID'] : 0;

$name = htmlspecialchars((string) ($_SESSION['fname'] ?? ''));

$pageTitle = 'Pal Dashboard — CareNest';
$active = 'dashboard';

$ratingAvg = (float) ($p['rating_avg'] ?? 0);
$completed = Pal::visitsCompletedTotal($pId);
$monthEarned = Pal::pointsEarnedThisMonth(currentUserId());
$pendingCount = Pal::pendingRequestCount($pId);

$pendingRows = Pal::pendingTableRows($pId);
$todayRows = Pal::todayScheduleRows($pId);

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_pal.php'; ?>

<main class="main-content">
    <section class="cn-card cn-card-body mb-4">
        <div class="d-flex flex-column flex-xl-row gap-4 justify-content-between align-items-start">
            <div>
                <h1><?= e("Welcome back, {$name}") ?> &#127775;</h1>
                <p style="color:var(--text-secondary);"><?= e('Warmly serve neighbors safely and with pride.') ?></p>
            </div>
            <div class="toggle-switch cn-card cn-card-body d-flex gap-4 align-items-center">
                <div>
                    <div class="small text-uppercase" style="color:var(--text-secondary);"><?= e('Availability') ?></div>
                    <div class="fw-bold" id="availability-label"><?= (!empty($p['is_available'])) ? 'Available on map' : 'Unavailable' ?></div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" style="cursor:pointer;width:56px;height:26px;"
                           type="checkbox" id="avail-toggle"
                        <?= (!empty($p['is_available'])) ? 'checked' : '' ?> data-endpoint="<?= carenest_url('ajax/toggle_availability.php') ?>">
                </div>
            </div>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background:var(--caramel);"><i class="fas fa-star"></i></div>
                <div>
                    <div class="h4 mb-0"><?= number_format($ratingAvg ?: 5, 2) ?></div>
                    <div style="color:var(--text-secondary);"><?= e('Rating average') ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background:var(--success);"><i class="fas fa-check"></i></div>
                <div>
                    <div class="h4 mb-0"><?= $completed ?></div>
                    <div style="color:var(--text-secondary);"><?= e('Visits finished') ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background:var(--sage);"><i class="fas fa-wallet"></i></div>
                <div>
                    <div class="h4 mb-0"><?= $monthEarned ?></div>
                    <div style="color:var(--text-secondary);"><?= e('Earned points (Mo)') ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="cn-card stat-card cn-card-body">
                <div class="stat-icon" style="background:var(--warning);"><i class="fas fa-clock"></i></div>
                <div>
                    <div class="h4 mb-0"><?= $pendingCount ?></div>
                    <div style="color:var(--text-secondary);"><?= e('Needs response') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h2 class="h5 mb-3"><?= e('Pending requests') ?></h2>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead><tr><th><?= e('Senior') ?></th><th><?= e('Service') ?></th><th><?= e('Scheduled') ?></th><th><?= e('Points') ?></th><th><?= e('Actions') ?></th></tr></thead>
                <tbody>
                <?php foreach ($pendingRows as $row): ?>
                    <tr class="pending-row">
                        <td><?= e(trim(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? ''))) ?></td>
                        <td><?= e((string) ($row['category_name'] ?? '')) ?></td>
                        <td><?= e((string) ($row['scheduled_start'] ?? '')) ?></td>
                        <td><?= (int) ($row['points_reserved'] ?? 0) ?></td>
                        <td>
                            <button type="button" class="cn-btn cn-btn-outline cn-btn-sm text-success approve-pal-action" style="border-color:var(--success);margin-right:.5rem;" data-visit="<?= (int) ($row['visit_ID'] ?? 0) ?>"><?= e('Accept') ?></button>
                            <button type="button" class="cn-btn cn-btn-outline cn-btn-sm text-danger reject-pal-action" style="border-color:var(--danger);" data-visit="<?= (int) ($row['visit_ID'] ?? 0) ?>"><?= e('Reject') ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pendingRows): ?>
                    <tr><td colspan="5" style="color:var(--text-secondary);"><?= e('Relax while we line up bookings for you.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="cn-card cn-card-body">
        <h2 class="h5 mb-3"><?= e("Today's itinerary") ?></h2>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($todayRows as $row): ?>
                <?php
                $vid = (int) ($row['visit_ID'] ?? 0);
                $st = cn_pal_dash_visit_status_slug((string) ($row['status'] ?? ''));
                $escPts = (int) ($row['escrow_points'] ?? 0);
                $escStat = (string) ($row['escrow_status'] ?? '');
                ?>
                <div class="cn-card cn-card-body d-flex flex-column gap-3">
                    <div class="d-flex gap-4 flex-column flex-xl-row justify-content-between">
                        <div>
                            <div class="fw-bold"><?= e(trim(($row['Fname'] ?? '') . ' ' . ($row['Lname'] ?? ''))) ?></div>
                            <div style="color:var(--text-secondary);"><?= e((string) ($row['category_name'] ?? '')) ?> <?= e((string) ($row['scheduled_start'] ?? '')) ?></div>
                            <div class="small mt-1"><?= e('Status') ?>: <strong><?= e((string) ($row['status'] ?? '')) ?></strong></div>
                            <?php if ($escPts > 0): ?>
                                <div class="small mt-2" style="color:var(--text-secondary);">
                                    <?= e('Points locked in escrow') ?>: <strong><?= $escPts ?></strong><?= $escStat !== '' ? ' — ' . e($escStat) : '' ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-column gap-2 flex-grow-1" style="min-width:min(100%, 280px);">
                            <?php if ($st === 'accepted'): ?>
                                <form method="post" action="<?= carenest_url('views/pal/schedule.php') ?>" class="d-flex flex-column gap-1">
                                    <input type="hidden" name="visit_id" value="<?= $vid ?>">
                                    <input type="hidden" name="action" value="during_report">
                                    <textarea name="during_body" class="cn-input" rows="2" style="min-height:52px;font-size:0.9rem;" placeholder="<?= e('Write report (plans, prep…) — right after you accept.') ?>"></textarea>
                                    <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm"><?= e('Save report') ?></button>
                                </form>
                                <div class="d-flex gap-2 flex-wrap align-items-center">
                                    <form method="post" action="<?= carenest_url('views/pal/schedule.php') ?>" class="m-0">
                                        <input type="hidden" name="visit_id" value="<?= $vid ?>">
                                        <input type="hidden" name="action" value="checkin">
                                        <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Check-in') ?></button>
                                    </form>
                                    <span class="small" style="color:var(--text-secondary);"><?= e('On site → check in') ?></span>
                                </div>
                            <?php elseif ($st === 'live'): ?>
                                <form method="post" action="<?= carenest_url('views/pal/schedule.php') ?>" class="d-flex flex-column gap-1">
                                    <input type="hidden" name="visit_id" value="<?= $vid ?>">
                                    <input type="hidden" name="action" value="during_report">
                                    <textarea name="during_body" class="cn-input" rows="1" style="min-height:44px;font-size:0.9rem;" placeholder="<?= e('Write report (in-visit)…') ?>"></textarea>
                                    <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Save report') ?></button>
                                </form>
                                <form method="post" action="<?= carenest_url('views/pal/schedule.php') ?>" class="d-flex flex-column gap-1">
                                    <input type="hidden" name="visit_id" value="<?= $vid ?>">
                                    <input type="hidden" name="action" value="complete">
                                    <textarea name="after_body" class="cn-input" rows="2" style="min-height:64px;font-size:0.9rem;" required placeholder="<?= e('After-visit report (required)…') ?>"></textarea>
                                    <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm"><?= e('Complete visit') ?></button>
                                </form>
                            <?php endif; ?>
                            <a href="<?= carenest_url('views/pal/schedule.php?view=' . $vid) ?>" class="cn-btn cn-btn-outline cn-btn-sm"><?= e('Details') ?></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$todayRows): ?>
                <div style="color:var(--text-secondary);"><?= e('No visits today — enjoy restorative time off.') ?></div>
            <?php endif; ?>
        </div>
    </div>

</main>

<script>
document.getElementById('avail-toggle')?.addEventListener('change', function () {
    const fd = new FormData();
    fd.append('status', this.checked ? '1' : '0');
    fetch(this.dataset.endpoint, {method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(j=>{
            document.getElementById('availability-label').textContent = document.getElementById('avail-toggle').checked ? 'Available on map' : 'Unavailable';
        });
});

document.body.addEventListener('click', (ev)=>{
  const acc = ev.target.closest('.approve-pal-action');
  if(acc){
    const fd = new FormData(); fd.append('visit_id', acc.dataset.visit||'');
    fetch('<?= carenest_url('ajax/accept_visit.php') ?>', {method:'POST', credentials:'same-origin', body:fd})
      .then(()=>window.location.reload());
  }
  const rej = ev.target.closest('.reject-pal-action');
  if(rej){
    const reason = prompt('Optional reason for declining?','')||'Pass';
    const fd = new FormData(); fd.append('visit_id', rej.dataset.visit||''); fd.append('reason', reason);
    fetch('<?= carenest_url('ajax/reject_visit.php') ?>', {method:'POST', credentials:'same-origin', body:fd})
      .then(()=>window.location.reload());
  }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
