<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
require_once dirname(__DIR__, 2) . '/models/Admin.php';
require_once dirname(__DIR__, 2) . '/models/BackgroundCheck.php';
require_once dirname(__DIR__, 2) . '/models/SkillBadge.php';
require_once dirname(__DIR__, 2) . '/models/User.php';
require_once dirname(__DIR__, 2) . '/models/WelfareInactivity.php';
require_once dirname(__DIR__, 2) . '/models/Notification.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['dash_clear_recent_notifications'])) {
        $clearIds = array_map('intval', (array) ($_POST['clear_ids'] ?? []));
        Notification::deleteIdsForAdmin($clearIds);
        header('Location: ' . preg_replace('#\?.*$#', '', (string) ($_SERVER['REQUEST_URI'] ?? '') ));
        exit;
    }
    if (isset($_POST['dash_delete_notification'])) {
        $nid = (int) ($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            Notification::deleteByIdForAdmin($nid);
        }
        header('Location: ' . preg_replace('#\?.*$#', '', (string) ($_SERVER['REQUEST_URI'] ?? '') ));
        exit;
    }
}

$stats = Admin::overviewStats();
$notifications = Admin::recentNotifications(15);
$checks = BackgroundCheck::pendingForAdmin();
$badgesPending = SkillBadge::pendingCount();

$db = Database::getInstance()->getConnection();
$adminUser = User::findById(currentUserId()) ?: [];
$adminName = trim((string) ($adminUser['Fname'] ?? '') . ' ' . (string) ($adminUser['Lname'] ?? ''));
if ($adminName === '') {
    $adminName = 'Admin';
}
$h = (int) date('G');
$greeting = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');

$welfareOpen = WelfareInactivity::countOpenCases();

$recentVisits = [];
try {
    $stmtRv = $db->query(
        "
        SELECT vr.visit_ID AS visit_id,
               CONCAT(IFNULL(su.Fname,''), ' ', IFNULL(su.Lname,'')) senior_name,
               CONCAT(IFNULL(pu.Fname,''), ' ', IFNULL(pu.Lname,'')) pal_name,
               sc.category_name,
               vr.status,
               vr.scheduled_start
        FROM visit_requests vr
        LEFT JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
        LEFT JOIN users su ON sp.User_ID = su.User_ID
        LEFT JOIN pal_profiles pp ON vr.pal_ID = pp.pal_ID
        LEFT JOIN users pu ON pp.User_ID = pu.User_ID
        LEFT JOIN service_categories sc ON vr.category_ID = sc.category_ID
        ORDER BY vr.scheduled_start DESC
        LIMIT 10
        "
    );
    $recentVisits = $stmtRv ? ($stmtRv->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (\Throwable $e) {
}

$pageTitle = 'Admin Dashboard — CareNest';
$active = 'dashboard';

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">

    <div class="cn-card cn-card-body mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h1 class="h4 mb-1"><?= e($greeting . ', ' . $adminName) ?></h1>
                <div class="small" style="color:var(--text-secondary);"><?= e('Here is what needs your attention today.') ?></div>
            </div>
            <div class="small" style="color:var(--text-secondary);"><?= e(date('l, M j, Y')) ?></div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <?php foreach ([
            ['lbl' => 'Total users', 'val' => $stats['users'] ?? 0, 'tone' => 'var(--sage-dark)'],
            ['lbl' => 'Visits today', 'val' => $stats['visits_today'] ?? 0, 'tone' => 'var(--success)'],
            ['lbl' => 'Pending approvals', 'val' => $stats['pending_approvals'] ?? 0, 'tone' => 'var(--warning)'],
            ['lbl' => 'Active emergencies', 'val' => $stats['active_emergencies'] ?? 0, 'tone' => 'var(--danger)'],
                         ] as $card): ?>
            <div class="col-lg-3 col-md-6">
                <div class="cn-card stat-card cn-card-body">
                    <div class="stat-icon" style="background:<?= e($card['tone']) ?>;"><i class="fas fa-chart-pie"></i></div>
                    <div>
                        <div class="h3 mb-0"><?= (int) $card['val'] ?></div>
                        <div style="color:var(--text-secondary);"><?= e($card['lbl']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-8">
            <div class="cn-card cn-card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h2 class="h5 mb-0"><?= e('Recent activity') ?></h2>
                    <?php if ($notifications): ?>
                        <form method="post" class="no-print mb-0" onsubmit="return confirm('Clear all notifications currently shown on this dashboard feed? Users will lose these inbox rows permanently.');">
                            <?php foreach ($notifications as $n): ?>
                                <input type="hidden" name="clear_ids[]" value="<?= (int) ($n['id'] ?? 0) ?>">
                            <?php endforeach; ?>
                            <button type="submit" name="dash_clear_recent_notifications" value="1" class="btn btn-sm btn-outline-danger"><?= e('Clear activity feed') ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php foreach ($notifications as $n): ?>
                    <div class="py-3 border-bottom" style="border-color:var(--border)!important;">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold"><?= e((string) ($n['title'] ?? 'Update')) ?></div>
                                <div style="color:var(--text-secondary);" class="small"><?= e((string) ($n['message_body'] ?? '')) ?></div>
                                <div style="color:var(--text-light);" class="small"><?= e('User ID ' . ($n['user_id'] ?? '?')) ?></div>
                            </div>
                            <form method="post" class="no-print flex-shrink-0 mb-0">
                                <input type="hidden" name="notification_id" value="<?= (int) ($n['id'] ?? 0) ?>">
                                <button type="submit" name="dash_delete_notification" value="1" class="btn btn-sm btn-link text-danger text-nowrap"><?= e('Remove') ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (!$notifications): ?>
                    <div style="color:var(--text-secondary);"><?= e('Quiet day — cherish the pause.') ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="cn-card cn-card-body mb-4">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h3 class="h6 mb-0"><?= e('Background checks') ?></h3>
                    <a href="<?= carenest_url('views/admin/background_checks.php') ?>" class="small"><?= e('Manage') ?></a>
                </div>
                <?php foreach (array_slice($checks, 0, 6) as $ck): ?>
                    <div class="py-2 border-bottom small" style="border-color:var(--border)!important;">
                        <?= e(trim(($ck['pal_fname'] ?? '') . ' ' . ($ck['pal_lname'] ?? ''))) ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$checks): ?>
                    <div style="color:var(--text-secondary);" class="small"><?= e('No pending dossiers.') ?></div>
                <?php endif; ?>
            </div>
            <div class="cn-card cn-card-body mb-4">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h3 class="h6 mb-0"><?= e('Welfare checks') ?></h3>
                    <a href="<?= carenest_url('views/admin/welfare_checks.php') ?>" class="small"><?= e('Manage') ?></a>
                </div>
                <div class="h4 mb-1"><?= (int) $welfareOpen ?></div>
                <div style="color:var(--text-secondary);" class="small"><?= e('Open inactive-user cases') ?></div>
            </div>
            <div class="cn-card cn-card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h3 class="h6 mb-0"><?= e('Skill badges') ?></h3>
                    <a href="<?= carenest_url('views/admin/skill_badges.php') ?>" class="small"><?= e('Review') ?></a>
                </div>
                <div class="h4 mb-1"><?= (int) $badgesPending ?></div>
                <div style="color:var(--text-secondary);" class="small"><?= e('Awaiting verification') ?></div>
            </div>
        </div>
    </div>

    <section class="cn-card cn-card-body">
        <h2 class="h5 mb-3"><?= e('Recent visits') ?></h2>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                <tr>
                    <th><?= e('Senior') ?></th>
                    <th><?= e('Pal') ?></th>
                    <th><?= e('Service') ?></th>
                    <th><?= e('Status') ?></th>
                    <th><?= e('Date') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentVisits as $rv): ?>
                    <tr>
                        <td><?= e(trim((string) ($rv['senior_name'] ?? ''))) ?></td>
                        <td><?= e(trim((string) ($rv['pal_name'] ?? ''))) ?></td>
                        <td><?= e((string) ($rv['category_name'] ?? '')) ?></td>
                        <td><?= e((string) ($rv['status'] ?? '')) ?></td>
                        <td><?= e((string) ($rv['scheduled_start'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recentVisits): ?>
                    <tr><td colspan="5" style="color:var(--text-secondary);"><?= e('No visits yet.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
