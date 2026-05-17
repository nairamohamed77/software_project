<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
requirePermission('manage_service_categories');
require_once dirname(__DIR__, 2) . '/models/ServiceCategory.php';
require_once dirname(__DIR__, 2) . '/models/Notification.php';

$msg = '';
$msgOk = true;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = $editId > 0 ? ServiceCategory::findAdminById($editId) : null;
if ($editId > 0 && !$editing) {
    $msg = 'Category not found.';
    $msgOk = false;
    $editId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['_action'] ?? '');
        if ($action === 'delete') {
            $id = (int) ($_POST['category_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid category.');
            }
            ServiceCategory::deleteRow($id);
            $msg = 'Delete completed (category removed).';
            $msgOk = true;
            $editId = 0;
            $editing = null;
        } elseif ($action === 'create') {
            $isActive = isset($_POST['is_active']);
            $newName = (string) ($_POST['category_name'] ?? '');
            ServiceCategory::createRow(
                $newName,
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['icon'] ?? ''),
                (int) ($_POST['base_points_cost'] ?? 10),
                (int) ($_POST['cost_per_extra_hour'] ?? 5),
                trim((string) ($_POST['requires_badge'] ?? '')) !== '' ? (string) $_POST['requires_badge'] : null,
                $isActive
            );
            try {
                $db = Database::getInstance()->getConnection();
                $q = $db->query("SELECT User_ID FROM users WHERE role_type IN ('Senior','Pal','FamilyProxy') AND COALESCE(is_active,0)=1");
                foreach (($q ? $q->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $uid = (int) ($r['User_ID'] ?? 0);
                    if ($uid > 0) {
                        Notification::enqueue($uid, 'System', 'Service catalog updated', 'New service category added: ' . trim($newName));
                    }
                }
            } catch (Throwable $ignored) {
            }
            $msg = 'Create completed (new category saved).';
            $msgOk = true;
        } elseif ($action === 'update') {
            $id = (int) ($_POST['category_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid category.');
            }
            $isActive = isset($_POST['is_active']);
            $newName = (string) ($_POST['category_name'] ?? '');
            ServiceCategory::updateRow(
                $id,
                $newName,
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['icon'] ?? ''),
                (int) ($_POST['base_points_cost'] ?? 10),
                (int) ($_POST['cost_per_extra_hour'] ?? 5),
                trim((string) ($_POST['requires_badge'] ?? '')) !== '' ? (string) $_POST['requires_badge'] : null,
                $isActive
            );
            try {
                $db = Database::getInstance()->getConnection();
                $q = $db->query("SELECT User_ID FROM users WHERE role_type IN ('Senior','Pal','FamilyProxy') AND COALESCE(is_active,0)=1");
                foreach (($q ? $q->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                    $uid = (int) ($r['User_ID'] ?? 0);
                    if ($uid > 0) {
                        Notification::enqueue($uid, 'System', 'Service catalog updated', 'Service category updated: ' . trim($newName));
                    }
                }
            } catch (Throwable $ignored) {
            }
            $msg = 'Update completed (changes saved).';
            $msgOk = true;
            $editing = ServiceCategory::findAdminById($id);
            $editId = $id;
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $msgOk = false;
    }
}

$list = ServiceCategory::allAdmin();

$pageTitle = 'Service categories (CRUD) — CareNest';
$active = 'categories';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">

    <?php if ($msg !== ''): ?>
        <div class="alert-cn <?= $msgOk ? 'alert-cn-success' : 'alert-cn-danger' ?>"><?= e($msg) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h4 mb-2"><?= e('Service categories') ?></h1>

        <h2 class="h6 mb-3"><?= $editing ? e('Update (U)') : e('Create (C)') ?></h2>
        <form method="post" class="row g-3">
            <input type="hidden" name="_action" value="<?= $editing ? 'update' : 'create' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="category_id" value="<?= (int) ($editing['category_ID'] ?? 0) ?>">
            <?php endif; ?>

            <div class="col-md-6">
                <label class="form-label small"><?= e('Name (unique)') ?></label>
                <input class="cn-input" name="category_name" required maxlength="100"
                       value="<?= e((string) ($editing['category_name'] ?? '')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small"><?= e('Icon (Font Awesome class)') ?></label>
                <input class="cn-input" name="icon" maxlength="100" placeholder="fa-shopping-cart"
                       value="<?= e((string) ($editing['icon'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small"><?= e('Base points') ?></label>
                <input class="cn-input" type="number" name="base_points_cost" min="0" required
                       value="<?= (int) ($editing['base_points_cost'] ?? 10) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small"><?= e('Extra hour cost') ?></label>
                <input class="cn-input" type="number" name="cost_per_extra_hour" min="0" required
                       value="<?= (int) ($editing['cost_per_extra_hour'] ?? 5) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small"><?= e('Requires badge (optional)') ?></label>
                <input class="cn-input" name="requires_badge" maxlength="100"
                       value="<?= e((string) ($editing['requires_badge'] ?? '')) ?>"
                       placeholder="<?= e('e.g. First Aid Certified') ?>">
            </div>
            <div class="col-12">
                <label class="form-label small"><?= e('Description') ?></label>
                <textarea class="cn-input" name="description" rows="3"><?= e((string) ($editing['description'] ?? '')) ?></textarea>
            </div>
            <div class="col-12">
                <label class="d-flex align-items-center gap-2 mb-0">
                    <input type="checkbox" name="is_active" value="1" <?= (($editing['is_active'] ?? 1) == 1) ? 'checked' : '' ?>>
                    <span class="small"><?= e('Active (shown in booking)') ?></span>
                </label>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="cn-btn cn-btn-outline"><?= $editing ? e('Save (U)') : e('Save (C)') ?></button>
                <?php if ($editing): ?>
                    <a href="<?= e(carenest_url('views/admin/service_categories.php')) ?>" class="cn-btn cn-btn-outline"><?= e('Cancel edit') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="cn-card cn-card-body">
        <h2 class="h5 mb-3"><?= e('Read (R) — all rows') ?></h2>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                <tr>
                    <th><?= e('ID') ?></th>
                    <th><?= e('Name') ?></th>
                    <th><?= e('Points / extra hr') ?></th>
                    <th><?= e('Badge req.') ?></th>
                    <th><?= e('Active') ?></th>
                    <th><?= e('Update / Delete') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <?php $cid = (int) ($row['category_ID'] ?? 0); ?>
                    <tr>
                        <td><?= $cid ?></td>
                        <td>
                            <div class="fw-semibold"><?= e((string) ($row['category_name'] ?? '')) ?></div>
                            <div class="small" style="color:var(--text-secondary);"><code><?= e((string) ($row['icon'] ?? '')) ?></code></div>
                        </td>
                        <td class="small"><?= (int) ($row['base_points_cost'] ?? 0) ?> / <?= (int) ($row['cost_per_extra_hour'] ?? 0) ?></td>
                        <td class="small"><?= e((string) ($row['requires_badge'] ?? '—')) ?></td>
                        <td><?= !empty($row['is_active']) ? e('Yes') : e('No') ?></td>
                        <td>
                            <a class="cn-btn cn-btn-outline cn-btn-sm me-1" href="<?= e(carenest_url('views/admin/service_categories.php?edit=' . $cid)) ?>"><?= e('Edit (U)') ?></a>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete? Only allowed if no visits use this category.');">
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="category_id" value="<?= $cid ?>">
                                <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm" style="border-color:var(--danger);"><?= e('Delete (D)') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$list): ?>
                    <tr><td colspan="6" style="color:var(--text-secondary);"><?= e('No categories.') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
