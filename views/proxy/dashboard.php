<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('FamilyProxy');
require_once dirname(__DIR__, 2) . '/models/FamilyProxy.php';
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/User.php';

$linked = FamilyProxy::linkedSeniorsWithProfiles(currentUserId());
$seniorIds = array_map(static fn (array $r): int => (int) ($r['senior_ID'] ?? 0), $linked);
$seniorIds = array_values(array_filter($seniorIds, static fn (int $id): bool => $id > 0));

$pageTitle = 'Family Proxy — CareNest Dashboard';
$active = 'dashboard';

$snapshotRows = [];

if ($seniorIds !== []) {
    try {
        $db = Database::getInstance()->getConnection();
        $ph = implode(',', array_fill(0, count($seniorIds), '?'));
        $stmt = $db->prepare(
            "
            SELECT vr.visit_ID AS visit_id, vr.senior_ID, vr.scheduled_start, vr.status,
                   COALESCE(CONCAT(IFNULL(pe.Fname,''),' ', IFNULL(pe.Lname,'')), 'Pal TBD') pal_name,
                   sc.category_name
            FROM visit_requests vr
            LEFT JOIN pal_profiles pp ON vr.pal_ID = pp.pal_ID
            LEFT JOIN users pe ON pp.User_ID = pe.User_ID
            LEFT JOIN service_categories sc ON vr.category_ID = sc.category_ID
            WHERE vr.senior_ID IN ($ph)
            ORDER BY vr.scheduled_start DESC
            LIMIT 8
            "
        );
        $stmt->execute($seniorIds);
        $snapshotRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $snapshotRows = [];
    }
}

$nameBySenior = [];
foreach ($linked as $r) {
    $sid = (int) ($r['senior_ID'] ?? 0);
    if ($sid > 0) {
        $nameBySenior[$sid] = trim((string) ($r['Fname'] ?? '') . ' ' . (string) ($r['Lname'] ?? ''));
    }
}

$proxyUser = User::findById(currentUserId()) ?: [];
$primary = $linked[0] ?? null;

$cnContextBar = [
    'role_label' => 'Family proxy',
    'user_name' => trim((string) ($proxyUser['Fname'] ?? '') . ' ' . (string) ($proxyUser['Lname'] ?? '')),
    'senior_line' => '',
    'points' => null,
    'points_label' => 'Household SilverPoints',
];

if ($primary !== null) {
    $cnContextBar['points'] = (int) ($primary['points_balance'] ?? 0);
    if (count($linked) === 1) {
        $cnContextBar['senior_line'] = 'Supporting: ' . trim((string) ($primary['Fname'] ?? '') . ' ' . (string) ($primary['Lname'] ?? ''))
            . (($primary['relationship_type'] ?? '') !== '' ? ' · ' . (string) $primary['relationship_type'] : '');
    } else {
        $labels = array_map(
            static fn (array $x): string => trim((string) ($x['Fname'] ?? '') . ' ' . (string) ($x['Lname'] ?? '')),
            $linked
        );
        $cnContextBar['senior_line'] = 'Supporting ' . count($linked) . ' seniors: ' . implode(', ', $labels);
        $cnContextBar['points_label'] = 'Primary senior · SilverPoints';
    }
}

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_proxy.php'; ?>

<main class="main-content">

    <?php include dirname(__DIR__) . '/layouts/context_bar.php'; ?>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h4 mb-2"><?= e('Care coordination hub') ?></h1>
        <p class="mb-0" style="color:var(--text-secondary);"><?= e('Book visits with your senior\'s SilverPoints and stay aligned with their schedule.') ?></p>
        <?php if ($linked): ?>
            <div class="d-flex flex-wrap gap-2 mt-4">
                <a class="cn-btn cn-btn-primary cn-btn-sm" href="<?= carenest_url('views/proxy/book_visit.php') ?>"><?= e('Book a visit') ?></a>
                <a class="cn-btn cn-btn-outline cn-btn-sm" href="<?= carenest_url('views/shared/buy_points.php') ?>"><?= e('Buy SilverPoints') ?></a>
                <a class="cn-btn cn-btn-outline cn-btn-sm" href="<?= carenest_url('views/proxy/visit_history.php') ?>"><?= e('Visit history') ?></a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$linked): ?>
        <div class="cn-card cn-card-body" style="color:var(--text-secondary);"><?= e('No linked seniors yet. Register again while selecting your senior, or ask support if linking failed.') ?></div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($linked as $seniorRow): ?>
                <?php
                $sid = (int) ($seniorRow['senior_ID'] ?? 0);
                $hr = Senior::healthBySeniorId($sid) ?: [];
                $pts = (int) ($seniorRow['points_balance'] ?? 0);
                $nm = trim((string) ($seniorRow['Fname'] ?? '') . ' ' . (string) ($seniorRow['Lname'] ?? ''));
                ?>
                <div class="col-lg-6">
                    <div class="cn-card cn-card-body h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h3 class="h6 mb-1"><?= e($nm !== '' ? $nm : 'Senior') ?></h3>
                                <div class="small" style="color:var(--text-secondary);">
                                    <?= e(($seniorRow['relationship_type'] ?? '') !== '' ? (string) $seniorRow['relationship_type'] : 'Linked contact') ?>
                                </div>
                            </div>
                            <div class="cn-points-pill cn-points-pill--compact">
                                <div class="cn-points-pill-value"><?= e(number_format($pts)) ?></div>
                                <div class="cn-points-pill-label"><?= e('SilverPoints') ?></div>
                            </div>
                        </div>
                        <div class="small mb-3" style="color:var(--text-secondary);"><?= e('Care notes') ?></div>
                        <p class="mb-4 small"><?= e(trim((string) ($hr['medical_notes'] ?? '')) !== '' ? trim((string) $hr['medical_notes']) : 'No medical notes on file.') ?></p>
                        <a class="cn-btn cn-btn-outline cn-btn-sm" href="<?= carenest_url('views/proxy/book_visit.php?senior_id=' . $sid) ?>"><?= e('Book for this senior') ?></a>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="col-lg-12">
                <div class="cn-card cn-card-body">
                    <h3 class="h6 mb-4"><?= e('Recent visits') ?></h3>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($snapshotRows as $row): ?>
                            <?php $sidRow = (int) ($row['senior_ID'] ?? 0); ?>
                            <li class="mb-3 pb-3 border-bottom" style="border-color:var(--border)!important;">
                                <span class="fw-semibold"><?= e(trim((string) ($nameBySenior[$sidRow] ?? 'Senior'))) ?></span>
                                <span style="color:var(--text-secondary);"> · </span>
                                <?= e((string) ($row['category_name'] ?? 'Visit')) ?>
                                — <?= e((string) ($row['pal_name'] ?? '')) ?>
                                — <?= e((string) ($row['scheduled_start'] ?? '')) ?>
                                — <span class="badge-status badge-pending"><?= e((string) ($row['status'] ?? '')) ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$snapshotRows): ?>
                            <li style="color:var(--text-secondary);"><?= e('No visits yet. Book one when your senior needs help.') ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
