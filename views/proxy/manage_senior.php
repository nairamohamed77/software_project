<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('FamilyProxy');
require_once dirname(__DIR__, 2) . '/models/FamilyProxy.php';
require_once dirname(__DIR__, 2) . '/models/Senior.php';
require_once dirname(__DIR__, 2) . '/models/User.php';

$linked = FamilyProxy::linkedSeniorsWithProfiles(currentUserId());

$pageTitle = 'Manage Seniors — Proxy';
$active = 'manage';

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
    $cnContextBar['senior_line'] = count($linked) === 1
        ? ('Supporting: ' . trim((string) ($primary['Fname'] ?? '') . ' ' . (string) ($primary['Lname'] ?? '')))
        : ('Supporting ' . count($linked) . ' seniors');
    $cnContextBar['points'] = (int) ($primary['points_balance'] ?? 0);
}
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_proxy.php'; ?>

<main class="main-content">

    <?php include dirname(__DIR__) . '/layouts/context_bar.php'; ?>

    <div class="cn-card cn-card-body">
        <h1 class="h4"><?= e('Linked seniors') ?></h1>
        <p style="color:var(--text-secondary);" class="mb-4"><?= e('Health summaries are read-only here. Book visits from Book a visit — charges apply to each senior\'s SilverPoints.') ?></p>
        <?php foreach ($linked as $seniorRow): ?>
            <?php
            $sid = (int) ($seniorRow['senior_ID'] ?? 0);
            $hr = Senior::healthBySeniorId($sid) ?: [];
            $nm = trim((string) ($seniorRow['Fname'] ?? '') . ' ' . (string) ($seniorRow['Lname'] ?? ''));
            ?>
            <section class="mb-5">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h2 class="h6 mb-0"><?= e($nm !== '' ? $nm : ('Senior #' . $sid)) ?></h2>
                    <span class="small" style="color:var(--text-secondary);"><?= (int) ($seniorRow['points_balance'] ?? 0) ?> <?= e('SilverPoints') ?></span>
                </div>
                <textarea class="cn-input mb-4" disabled style="opacity:.72;"><?= e((string) ($hr['medical_notes'] ?? 'No notes on file.')) ?></textarea>
            </section>
        <?php endforeach; ?>
        <?php if (!$linked): ?>
            <div style="color:var(--text-secondary);"><?= e('No linkage yet.') ?></div>
        <?php endif; ?>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
