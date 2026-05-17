<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
require_once dirname(__DIR__, 2) . '/models/SystemAudit.php';

SystemAudit::ensureTable();

$limit = (int) ($_GET['limit'] ?? 150);
$actionF = trim((string) ($_GET['action'] ?? ''));
$entityF = trim((string) ($_GET['entity'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$rows = SystemAudit::listRecent(
    $limit,
    $actionF !== '' ? $actionF : null,
    $entityF !== '' ? $entityF : null,
    $q !== '' ? $q : null
);

$pageTitle = 'Audit logs (UC-34) — CareNest';
$active = 'audit_logs';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">
    <h1 class="h5 mb-3"><?= e('System audit trail') ?></h1>
    <p class="small text-secondary mb-4"><?= e('Append-only log of sensitive actions. Use filters to narrow results.') ?></p>

    <form method="get" class="cn-card cn-card-body mb-4 row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small"><?= e('Action contains') ?></label>
            <input type="text" name="action" class="cn-input" value="<?= e($actionF) ?>" placeholder="<?= e('e.g. DISPUTE') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small"><?= e('Entity type') ?></label>
            <input type="text" name="entity" class="cn-input" value="<?= e($entityF) ?>" placeholder="<?= e('dispute') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small"><?= e('Search') ?></label>
            <input type="text" name="q" class="cn-input" value="<?= e($q) ?>" placeholder="<?= e('details / id') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small"><?= e('Limit') ?></label>
            <input type="number" name="limit" class="cn-input" min="20" max="500" value="<?= (int) max(20, min(500, $limit)) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm w-100"><?= e('Apply') ?></button>
        </div>
    </form>

    <div class="cn-table-wrap">
        <table class="cn-table mb-0 small">
            <thead>
            <tr>
                <th><?= e('When') ?></th>
                <th><?= e('Action') ?></th>
                <th><?= e('Entity') ?></th>
                <th><?= e('Actor') ?></th>
                <th><?= e('Details') ?></th>
                <th><?= e('IP') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e((string) ($r['created_at'] ?? '')) ?></td>
                    <td><code><?= e((string) ($r['action_type'] ?? '')) ?></code></td>
                    <td><?php
                        $et = trim((string) ($r['entity_type'] ?? ''));
                        $eid = $r['entity_ID'] ?? null;
                        echo e($et . ($eid !== null && (string) $eid !== '' ? ' #' . $eid : ''));
                        ?></td>
                    <td><?= e(trim((string) ($r['actor_name'] ?? ''))) ?>
                        <?php if (!empty($r['actor_email'])): ?><div class="text-secondary"><?= e((string) $r['actor_email']) ?></div><?php endif; ?>
                    </td>
                    <td style="max-width:360px;"><?= nl2br(e((string) ($r['details'] ?? ''))) ?></td>
                    <td><?= e((string) ($r['ip_address'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-secondary"><?= e('No rows match your filters.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
