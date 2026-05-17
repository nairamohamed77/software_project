<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');

$db = Database::getInstance()->getConnection();
$query = <<<SQL
SELECT vr.visit_ID,
       vr.status,
       vr.scheduled_start,
       vr.points_reserved,
       CONCAT(IFNULL(su.Fname,''), ' ', IFNULL(su.Lname,'')) senior_name,
       CONCAT(IFNULL(pu.Fname,''), ' ', IFNULL(pu.Lname,'')) pal_name,
       sc.category_name
FROM visit_requests vr
LEFT JOIN senior_profiles sp ON vr.senior_ID = sp.senior_ID
LEFT JOIN users su ON sp.User_ID = su.User_ID
LEFT JOIN pal_profiles pp ON vr.pal_ID = pp.pal_ID
LEFT JOIN users pu ON pp.User_ID = pu.User_ID
LEFT JOIN service_categories sc ON vr.category_ID = sc.category_ID
ORDER BY vr.visit_ID DESC
LIMIT 500
SQL;
$stmt = $db->query($query);
$list = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$pageTitle = 'Visits Oversight — CareNest Admin';
$active = 'visits';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">

    <div class="cn-card cn-card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
            <h1 class="h4"><?= e('Operational visit ribbon') ?></h1>
            <div class="small" style="color:var(--text-secondary);"><?= e('Most recent bookings first.') ?></div>
        </div>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                    <tr>
                        <th><?= e('#') ?></th>
                        <th><?= e('Senior') ?></th>
                        <th><?= e('Pal') ?></th>
                        <th><?= e('Category') ?></th>
                        <th><?= e('When') ?></th>
                        <th><?= e('Points escrow') ?></th>
                        <th><?= e('Status') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><?= (int) ($row['visit_ID'] ?? 0) ?></td>
                            <td><?= e(trim((string) ($row['senior_name'] ?? ''))) ?></td>
                            <td><?= e(trim((string) ($row['pal_name'] ?? ''))) ?></td>
                            <td><?= e((string) ($row['category_name'] ?? '')) ?></td>
                            <td><?= e((string) ($row['scheduled_start'] ?? '')) ?></td>
                            <td><?= (int) ($row['points_reserved'] ?? 0) ?></td>
                            <td><?= e((string) ($row['status'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$list): ?>
                        <tr><td colspan="7" style="color:var(--text-secondary);"><?= e('No visits scheduled yet.') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
