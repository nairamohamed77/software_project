<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Admin');
require_once dirname(__DIR__, 2) . '/models/Notification.php';
require_once dirname(__DIR__, 2) . '/models/BackgroundCheck.php';
require_once dirname(__DIR__, 2) . '/models/User.php';

$db = Database::getInstance()->getConnection();
BackgroundCheck::ensureReady();
User::ensureReady();
[$flashMessage, $flashClass] = ['', 'alert-cn-success'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    $newStatus = (string) ($_POST['account_status'] ?? '');
    $doDelete = (string) ($_POST['delete_user'] ?? '') === '1';

    if ($doDelete && $uid > 0) {
        if ($uid === currentUserId()) {
            $flashMessage = 'You cannot delete your own admin account while signed in.';
            $flashClass = 'alert-cn-danger';
        } else {
            try {
                $db->beginTransaction();

                $stmtU = $db->prepare('SELECT User_ID, role_type FROM users WHERE User_ID = ? LIMIT 1');
                $stmtU->execute([$uid]);
                $urow = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$urow) {
                    throw new RuntimeException('User not found.');
                }

                $role = (string) ($urow['role_type'] ?? '');
                if ($role === 'Admin') {
                    throw new RuntimeException('Admin accounts cannot be deleted.');
                }

                if ($role === 'Pal') {
                    $stmtP = $db->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID = ? LIMIT 1');
                    $stmtP->execute([$uid]);
                    $palId = (int) ($stmtP->fetch()['pal_ID'] ?? 0);
                    if ($palId > 0) {
                        // ratings FK blocks deleting pal_profiles → delete ratings first
                        $db->prepare('DELETE FROM ratings WHERE pal_ID = ?')->execute([$palId]);
                        // background checks + badges
                        $db->prepare('DELETE FROM background_checks WHERE pal_ID = ?')->execute([$palId]);
                        $db->prepare('DELETE FROM skill_badges WHERE pal_ID = ?')->execute([$palId]);
                        // cashout requests/destinations will cascade when pal_profile is deleted, but safe to remove requests first
                        $db->prepare('DELETE FROM cashout_requests WHERE pal_ID = ?')->execute([$palId]);
                        $db->prepare('DELETE FROM cashout_destinations WHERE pal_ID = ?')->execute([$palId]);
                        // visits will set pal_ID NULL on pal_profile delete; no need to delete them
                    }
                } elseif ($role === 'Senior') {
                    $stmtS = $db->prepare('SELECT senior_ID FROM senior_profiles WHERE User_ID = ? LIMIT 1');
                    $stmtS->execute([$uid]);
                    $seniorId = (int) ($stmtS->fetch()['senior_ID'] ?? 0);
                    if ($seniorId > 0) {
                        // ratings FK blocks deleting senior_profiles → delete ratings first
                        $db->prepare('DELETE FROM ratings WHERE senior_ID = ?')->execute([$seniorId]);
                        // emergency threads reference senior_ID (no cascade) → delete threads (messages cascade)
                        $db->prepare('DELETE FROM emergency_threads WHERE senior_ID = ?')->execute([$seniorId]);
                        // visits reference senior_ID (no cascade) → delete visits (escrow/passed_requests/ratings cascade from visit_ID)
                        $db->prepare('DELETE FROM visit_requests WHERE senior_ID = ?')->execute([$seniorId]);
                        // welfare checks + gifts + proxy links cascade from senior_ID in schema, but safe to delete explicitly
                        $db->prepare('DELETE FROM welfare_checks WHERE senior_ID = ?')->execute([$seniorId]);
                        $db->prepare('DELETE FROM gift_transactions WHERE recipient_senior_ID = ?')->execute([$seniorId]);
                        $db->prepare('DELETE FROM proxy_senior_link WHERE senior_ID = ?')->execute([$seniorId]);
                    }
                } elseif ($role === 'FamilyProxy') {
                    // remove links first (should cascade, but explicit is clearer)
                    $db->prepare('DELETE FROM proxy_senior_link WHERE proxy_User_ID = ?')->execute([$uid]);
                } elseif ($role === 'Admin') {
                    // broadcasts reference admin_ID
                    $db->prepare('DELETE FROM admin_broadcasts WHERE admin_ID = ?')->execute([$uid]);
                }

                // Final delete: user row (profiles cascade due to FK from profiles → users with ON DELETE CASCADE)
                $stmtDel = $db->prepare('DELETE FROM users WHERE User_ID = ? LIMIT 1');
                $stmtDel->execute([$uid]);

                $db->commit();

                $flashMessage = 'User deleted successfully (dependent data cleared first).';
                $flashClass = 'alert-cn-success';
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $flashMessage = $e->getMessage();
                $flashClass = 'alert-cn-danger';
            }
        }
    } elseif ($uid && $newStatus !== '') {
        $stmtUp = $db->prepare('UPDATE users SET account_status=?, is_active=? WHERE User_ID=? LIMIT 1');
        $isActiveFlag = strtolower($newStatus) === 'active' ? 1 : 0;
        $stmtUp->execute([$newStatus, $isActiveFlag, $uid]);

        if ($newStatus === 'Active') {
            Notification::enqueue($uid, 'System', 'Registration approved', 'Your CareNest account is now active. You can sign in.');
            $flashMessage = 'User approved successfully.';
        } elseif ($newStatus === 'Deactivated') {
            Notification::enqueue($uid, 'System', 'Registration declined', 'Your registration was declined by an administrator.');
            $flashMessage = 'User registration declined.';
        } else {
            Notification::enqueue($uid, 'System', 'Account updated', 'Your account status was updated to ' . $newStatus . '.');
            $flashMessage = 'User status updated.';
        }
    }
}

$hasNationalIdColumn = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM users LIKE 'national_id_number'");
    $hasNationalIdColumn = (bool) ($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasNationalIdColumn = false;
}

$nationalIdSelect = $hasNationalIdColumn ? 'national_id_number' : "NULL AS national_id_number";
$stmt = $db->query("SELECT User_ID, Fname, Lname, email, phone, {$nationalIdSelect}, profile_photo_url, role_type, COALESCE(is_active,0) AS is_active, IFNULL(account_status,'') AS account_status, created_at FROM users ORDER BY CASE WHEN account_status = 'Pending' THEN 0 ELSE 1 END, User_ID DESC LIMIT 500");
$list = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$palDetailsByUser = [];
$seniorDetailsByUser = [];
foreach ($list as $uRow) {
    $uid = (int) ($uRow['User_ID'] ?? 0);
    $role = (string) ($uRow['role_type'] ?? '');
    if ($uid <= 0) {
        continue;
    }
    if ($role === 'Pal') {
        $q = $db->prepare(
            "SELECT pp.pal_ID, pp.verification_status, bc.national_id_number, bc.id_document_url, bc.criminal_record_url, sb.badge_name, sb.certificate_url
             FROM pal_profiles pp
             LEFT JOIN background_checks bc ON bc.check_ID = (
                 SELECT check_ID FROM background_checks WHERE pal_ID = pp.pal_ID ORDER BY check_ID DESC LIMIT 1
             )
             LEFT JOIN skill_badges sb ON sb.badge_ID = (
                 SELECT badge_ID FROM skill_badges WHERE pal_ID = pp.pal_ID ORDER BY badge_ID DESC LIMIT 1
             )
             WHERE pp.User_ID = ?
             LIMIT 1"
        );
        $q->execute([$uid]);
        $palDetailsByUser[$uid] = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif ($role === 'Senior') {
        $q = $db->prepare(
            "SELECT age
             FROM senior_profiles
             WHERE User_ID = ?
             LIMIT 1"
        );
        $q->execute([$uid]);
        $seniorDetailsByUser[$uid] = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

$pageTitle = 'Users — Admin';
$active = 'users';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_admin.php'; ?>

<main class="main-content">
    <?php if ($flashMessage !== ''): ?>
        <div class="alert-cn <?= e($flashClass) ?> mb-4"><?= e($flashMessage) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
            <div>
                <h1 class="h4 mb-1"><?= e('User approvals and access') ?></h1>
                <div class="small" style="color:var(--text-secondary);"><?= e('Approve or reject new registrations, then manage active users.') ?></div>
            </div>
            <div class="badge-status badge-pending-v"><?= e('Pending signups appear first') ?></div>
        </div>
        <div class="cn-table-wrap">
            <table class="cn-table mb-0">
                <thead>
                <tr>
                    <th><?= e('Name') ?></th>
                    <th><?= e('Email') ?></th>
                    <th><?= e('Role') ?></th>
                    <th><?= e('Status') ?></th>
                    <th><?= e('Access') ?></th>
                    <th><?= e('Actions') ?></th>
                </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $u): ?>
                        <?php
                        $status = (string) ($u['account_status'] ?? '');
                        $isActive = (int) ($u['is_active'] ?? 0) === 1;
                        $badgeClass = match ($status) {
                            'Active' => 'badge-approved',
                            'Pending' => 'badge-pending-v',
                            'Suspended' => 'badge-cancelled',
                            'Deactivated' => 'badge-cancelled',
                            default => 'badge-pending',
                        };
                        $roleType = (string) ($u['role_type'] ?? '');
                        ?>
                        <tr>
                            <td><?= e(trim(($u['Fname'] ?? '') . ' ' . ($u['Lname'] ?? ''))) ?></td>
                            <td><?= e((string) ($u['email'] ?? '')) ?></td>
                            <td><?= e((string) ($u['role_type'] ?? '')) ?></td>
                            <td><span class="badge-status <?= e($badgeClass) ?>"><?= e($status) ?></span></td>
                            <td><?= e($isActive ? 'Enabled' : 'Blocked') ?></td>
                            <td>
                                <?php $detailModalId = 'userDetails' . (int) ($u['User_ID'] ?? 0); ?>
                                <button type="button" class="cn-btn cn-btn-outline cn-btn-sm me-2 mb-2"
                                        data-bs-toggle="modal" data-bs-target="#<?= e($detailModalId) ?>">
                                    <?= e('Show Details') ?>
                                </button>

                                <?php if ($status === 'Pending'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= (int) ($u['User_ID'] ?? 0) ?>">
                                        <input type="hidden" name="account_status" value="Active">
                                        <button type="submit" class="cn-btn cn-btn-primary cn-btn-sm me-2 mb-2"><?= e('Approve') ?></button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="user_id" value="<?= (int) ($u['User_ID'] ?? 0) ?>">
                                        <input type="hidden" name="account_status" value="Deactivated">
                                        <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm me-2 mb-2" style="border-color:var(--danger);color:var(--danger);"><?= e('Reject') ?></button>
                                    </form>
                                <?php else: ?>
                                    <?php foreach (['Active', 'Suspended', 'Deactivated'] as $st): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="user_id" value="<?= (int) ($u['User_ID'] ?? 0) ?>">
                                            <input type="hidden" name="account_status" value="<?= e($st) ?>">
                                            <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm me-2 mb-2"><?= e($st) ?></button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if ($roleType !== 'Admin'): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user and ALL their related data? This cannot be undone.');">
                                        <input type="hidden" name="user_id" value="<?= (int) ($u['User_ID'] ?? 0) ?>">
                                        <input type="hidden" name="delete_user" value="1">
                                        <button type="submit" class="cn-btn cn-btn-outline cn-btn-sm me-2 mb-2" style="border-color:var(--danger);color:var(--danger);">
                                            <?= e('Delete') ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge-status badge-approved"><?= e('Protected Admin') ?></span>
                                <?php endif; ?>

                                <div class="modal fade" id="<?= e($detailModalId) ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered">
                                        <div class="modal-content" style="background:var(--cream);border-radius:var(--radius-md);">
                                            <div class="modal-header">
                                                <h5 class="modal-title"><?= e('User Details') ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e('Close') ?>"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-3" style="color:var(--text-secondary);">
                                                    <div class="col-md-6"><strong>Name:</strong> <?= e(trim(($u['Fname'] ?? '') . ' ' . ($u['Lname'] ?? ''))) ?></div>
                                                    <div class="col-md-6"><strong>Role:</strong> <?= e((string) ($u['role_type'] ?? '')) ?></div>
                                                    <div class="col-md-6"><strong>Email:</strong> <?= e((string) ($u['email'] ?? '')) ?></div>
                                                    <div class="col-md-6"><strong>Phone:</strong> <?= e((string) ($u['phone'] ?? '—')) ?></div>
                                                    <div class="col-md-6"><strong>National ID:</strong> <?= e((string) ($u['national_id_number'] ?? '—')) ?></div>
                                                    <div class="col-md-6"><strong>Status:</strong> <?= e((string) ($u['account_status'] ?? '')) ?></div>
                                                    <div class="col-md-6"><strong>Access:</strong> <?= e(((int) ($u['is_active'] ?? 0) === 1) ? 'Enabled' : 'Blocked') ?></div>
                                                    <div class="col-md-6">
                                                        <strong>Profile Photo:</strong>
                                                        <?php if (!empty($u['profile_photo_url'])): ?>
                                                            <a href="<?= e(carenest_url((string) $u['profile_photo_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e('Open file') ?></a>
                                                        <?php else: ?>
                                                            <?= e('—') ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ((string) ($u['role_type'] ?? '') === 'Senior'): ?>
                                                        <?php $sdet = $seniorDetailsByUser[(int) ($u['User_ID'] ?? 0)] ?? []; ?>
                                                        <div class="col-md-6"><strong>Age:</strong> <?= e((string) ($sdet['age'] ?? '—')) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ((string) ($u['role_type'] ?? '') === 'Pal'): ?>
                                                        <?php $pdet = $palDetailsByUser[(int) ($u['User_ID'] ?? 0)] ?? []; ?>
                                                        <div class="col-md-6"><strong>Pal verification:</strong> <?= e((string) ($pdet['verification_status'] ?? 'Pending')) ?></div>
                                                        <div class="col-md-6"><strong>National ID:</strong> <?= e((string) ($pdet['national_id_number'] ?? '—')) ?></div>
                                                        <div class="col-md-6"><strong>Skill badge:</strong> <?= e((string) ($pdet['badge_name'] ?? '—')) ?></div>
                                                        <div class="col-md-6">
                                                            <strong>Badge certificate:</strong>
                                                            <?php if (!empty($pdet['certificate_url'])): ?>
                                                                <a href="<?= e(carenest_url((string) $pdet['certificate_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e('Open file') ?></a>
                                                            <?php else: ?>
                                                                <?= e('—') ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="col-12">
                                                            <strong>Background document:</strong>
                                                            <?php if (!empty($pdet['id_document_url'])): ?>
                                                                <a href="<?= e(carenest_url((string) $pdet['id_document_url'])) ?>" target="_blank" rel="noopener noreferrer"><?= e('Open file') ?></a>
                                                            <?php else: ?>
                                                                <?= e('—') ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$list): ?>
                        <tr><td colspan="6"><?= e('No users found.') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
