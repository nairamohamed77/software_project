<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Pal');

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'all')));

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID=? LIMIT 1');
$stmt->execute([currentUserId()]);
$pId = (int) ($stmt->fetch()['pal_ID'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitId = (int) ($_POST['visit_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    if ($visitId > 0 && $action !== '') {
        require_once dirname(__DIR__, 2) . '/models/Visit.php';
        try {
            if ($action === 'complete') {
                Visit::completeVisitWithReport($visitId, $pId, (string) ($_POST['after_body'] ?? ''));
                header('Location: ' . carenest_url('views/pal/visit_report_pdf.php?visit_id=' . $visitId));
                exit;
            }
            if ($action === 'checkin') {
                Visit::checkIn($visitId, $pId);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_requests_error'] = $e->getMessage();
        }
    }
    header('Location: ' . carenest_url('views/pal/requests.php?tab=' . rawurlencode($tab !== '' ? $tab : 'all')));
    exit;
}
$statusFilterSql = '';
if ($tab !== 'all') {
    $map = [
        'pending' => "LOWER(TRIM(vr.status))='pending'",
        'accepted' => "LOWER(TRIM(vr.status))='accepted'",
        'completed' => "LOWER(TRIM(vr.status))='completed'",
        'cancelled' => "LOWER(TRIM(vr.status))='cancelled'",
    ];
    if (isset($map[$tab])) {
        $statusFilterSql = ' AND ' . $map[$tab];
    }
}

$sql =
    "
SELECT vr.*, CONCAT(IFNULL(u.Fname,''),' ', IFNULL(u.Lname,'')) AS senior_name,
       sc.category_name
FROM visit_requests vr
JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
JOIN users u ON sp.User_ID = u.User_ID
JOIN service_categories sc ON vr.category_ID = sc.category_ID
WHERE vr.pal_ID = ?
{$statusFilterSql}
ORDER BY vr.scheduled_start DESC
LIMIT 200
";
$stmt2 = $db->prepare($sql);
$stmt2->execute([$pId]);
$rows = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Visit Requests — CareNest';
$active = 'requests';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_pal.php'; ?>

<main class="main-content">
    <?php if (!empty($_SESSION['flash_requests_error'])): ?>
        <?php $er = $_SESSION['flash_requests_error']; unset($_SESSION['flash_requests_error']); ?>
        <div class="alert-cn alert-cn-danger mb-3"><?= e((string) $er) ?></div>
    <?php endif; ?>
    <?php foreach (['All' => 'all', 'Pending' => 'pending', 'Accepted' => 'accepted', 'Completed' => 'completed', 'Cancelled' => 'cancelled'] as $lbl => $key): ?>
        <a href="<?= carenest_url('views/pal/requests.php?tab=' . rawurlencode($key)) ?>" class="cn-btn <?= $tab === strtolower($lbl) ? 'cn-btn-primary' : 'cn-btn-outline' ?> cn-btn-sm mb-4 me-2"><?= e($lbl) ?></a>
    <?php endforeach; ?>

    <div class="cn-table-wrap">
        <table class="cn-table mb-0">
            <thead>
                <tr>
                    <th><?= e('Senior') ?></th>
                    <th><?= e('Service') ?></th>
                    <th><?= e('Scheduled') ?></th>
                    <th><?= e('Duration') ?></th>
                    <th><?= e('Points') ?></th>
                    <th><?= e('Status') ?></th>
                    <th><?= e('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $start = strtotime((string) ($row['scheduled_start'] ?? ''));
                    $end = strtotime((string) ($row['scheduled_end'] ?? ''));
                    $dur = ($start && $end) ? max(1, (int) round(($end - $start) / 3600)) : 1;
                    $st = strtolower((string) ($row['status'] ?? ''));
                    ?>
                    <tr>
                        <td><?= e(trim((string) ($row['senior_name'] ?? ''))) ?></td>
                        <td><?= e((string) ($row['category_name'] ?? '')) ?></td>
                        <td><?= e((string) ($row['scheduled_start'] ?? '')) ?></td>
                        <td><?= $dur ?> <?= e('hrs') ?></td>
                        <td><?= (int) ($row['points_reserved'] ?? 0) ?></td>
                        <td><?= e((string) ($row['status'] ?? '')) ?></td>
                        <td>
                            <?php if ($st === 'pending'): ?>
                                <button type="button" class="cn-btn cn-btn-outline cn-btn-sm approve-pal-action" style="margin-right:.5rem;" data-visit="<?= (int) ($row['visit_ID'] ?? 0) ?>"><?= e('Accept') ?></button>
                                <button type="button" class="cn-btn cn-btn-outline cn-btn-sm reject-pal-action" data-visit="<?= (int) ($row['visit_ID'] ?? 0) ?>"><?= e('Reject') ?></button>
                            <?php elseif ($st === 'accepted' || $st === 'live'): ?>
                                <?php $vidNum = (int) ($row['visit_ID'] ?? 0); ?>
                                <div class="d-flex flex-column gap-2 align-items-start">
                                    <?php if ($st === 'accepted'): ?>
                                        <form action="<?= carenest_url('views/pal/schedule.php') ?>" method="post" style="margin:0;">
                                            <input type="hidden" name="visit_id" value="<?= $vidNum ?>">
                                            <input type="hidden" name="action" value="checkin">
                                            <button class="cn-btn cn-btn-outline cn-btn-sm" type="submit"><?= e('Check-in') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($st === 'live'): ?>
                                        <form action="<?= carenest_url('views/pal/requests.php?tab=' . rawurlencode($tab !== '' ? $tab : 'all')) ?>" method="post" style="margin:0;min-width:16rem;">
                                            <input type="hidden" name="visit_id" value="<?= $vidNum ?>">
                                            <input type="hidden" name="action" value="complete">
                                            <textarea name="after_body" class="cn-input mb-2" rows="2" required minlength="12" placeholder="<?= e('After-visit summary (required to complete)') ?>"></textarea>
                                            <button class="cn-btn cn-btn-primary cn-btn-sm" type="submit"><?= e('Complete visit') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <a class="cn-btn cn-btn-primary cn-btn-sm" href="<?= carenest_url('views/pal/schedule.php') ?>"><?= e('Open schedule') ?></a>
                                    <span class="small" style="color:var(--text-secondary);max-width:14rem;"><?= e('Finish only via “Complete visit” on the schedule — not when the slot ends.') ?></span>
                                </div>
                            <?php elseif ($st === 'completed'): ?>
                                <a class="cn-btn cn-btn-outline cn-btn-sm" href="<?= e(carenest_url('views/pal/visit_report_pdf.php?visit_id=' . $vidNum)) ?>"><?= e('Download PDF report') ?></a>
                            <?php else: ?>
                                <span style="color:var(--text-secondary);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" style="color:var(--text-secondary);"><?= e('Nothing to show.') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
document.body.addEventListener('click', (ev)=>{
   const accept = ev.target.closest('.approve-pal-action');
   if(accept){
     const fd = new FormData(); fd.append('visit_id', accept.dataset.visit||'');
     fetch('<?= carenest_url('ajax/accept_visit.php') ?>', {method:'POST', credentials:'same-origin', body:fd}).then(()=>location.reload());
   }
   const reject = ev.target.closest('.reject-pal-action');
   if(reject){
     const reason=prompt('Why pass?','')||'Unavailable';
     const fd=new FormData(); fd.append('visit_id', reject.dataset.visit||''); fd.append('reason', reason);
     fetch('<?= carenest_url('ajax/reject_visit.php') ?>',{method:'POST', credentials:'same-origin', body:fd}).then(()=>location.reload());
   }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
