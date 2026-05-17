<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('Pal');
require_once dirname(__DIR__, 2) . '/models/User.php';

$db = Database::getInstance()->getConnection();
User::ensureReady();
$stmt = $db->prepare('SELECT * FROM pal_profiles WHERE User_ID=? LIMIT 1');
$stmt->execute([currentUserId()]);
$p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$hasNationalIdColumn = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM users LIKE 'national_id_number'");
    $hasNationalIdColumn = (bool) ($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
} catch (\Throwable $e) {
    $hasNationalIdColumn = false;
}
$nationalSelect = $hasNationalIdColumn ? 'national_id_number' : "NULL AS national_id_number";
$uStmt = $db->prepare("SELECT User_ID, Fname, Lname, email, phone, profile_photo_url, role_type, account_status, created_at, {$nationalSelect} FROM users WHERE User_ID = ? LIMIT 1");
$uStmt->execute([currentUserId()]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$pageTitle = 'Pal Profile — CareNest';
$active = 'profile';
$badgeRows = [];
$docRows = [];

try {
    $bq = $db->prepare('SELECT badge_ID, badge_name, verification_status FROM skill_badges WHERE pal_ID = ? LIMIT 25');
    $bq->execute([(int) ($p['pal_ID'] ?? 0)]);
    $badgeRows = $bq->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
}

try {
    $dq = $db->prepare('SELECT document_type, original_name, file_url, uploaded_at FROM registration_documents WHERE User_ID = ? ORDER BY registration_document_ID DESC LIMIT 40');
    $dq->execute([currentUserId()]);
    $docRows = $dq->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    $docRows = [];
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? 'availability');
    if ($formType === 'password') {
        $currentPass = (string) ($_POST['current_password'] ?? '');
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirmPass = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
            $msg = 'Please complete all password fields.';
        } elseif ($newPass !== $confirmPass) {
            $msg = 'New password and confirmation do not match.';
        } elseif (strlen($newPass) < 8) {
            $msg = 'New password must be at least 8 characters.';
        } else {
            try {
                $authStmt = $db->prepare('SELECT password_hash FROM users WHERE User_ID = ? LIMIT 1');
                $authStmt->execute([currentUserId()]);
                $hash = (string) ($authStmt->fetch()['password_hash'] ?? '');
                if ($hash === '' || !password_verify($currentPass, $hash)) {
                    $msg = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $updPass = $db->prepare('UPDATE users SET password_hash = ? WHERE User_ID = ? LIMIT 1');
                    $updPass->execute([$newHash, currentUserId()]);
                    $msg = 'Password updated successfully.';
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
            }
        }
    } else {
        $avail = isset($_POST['available']) ? 1 : 0;
        $travel = max(5, (int) ($_POST['travel_radius_km'] ?? ($p['travel_radius_km'] ?? 25)));
        $palId = (int) ($p['pal_ID'] ?? 0);
        if ($palId > 0) {
            try {
                $uu = $db->prepare('UPDATE pal_profiles SET is_available=?, travel_radius_km=? WHERE pal_ID=?');
                $uu->execute([$avail, $travel, $palId]);
                $msg = 'Profile refreshed.';
                $stmt->execute([currentUserId()]);
                $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
            }
        }
    }
}

?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_pal.php'; ?>

<main class="main-content">

    <?php if ($msg !== ''): ?>
        <?php $okMessages = ['profile refreshed.', 'password updated successfully.']; ?>
        <?php $isErr = !in_array(strtolower($msg), $okMessages, true); ?>
        <div class="alert-cn <?= $isErr ? 'alert-cn-danger' : 'alert-cn-success' ?> mb-4"><?= e($msg) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h5 mb-4"><?= e('Registration Details') ?></h1>
        <div class="row g-4">
            <div class="col-md-3">
                <?php $photoUrl = (string) ($user['profile_photo_url'] ?? ''); ?>
                <?php if ($photoUrl !== '' && strtolower($photoUrl) !== 'default.png'): ?>
                    <img src="<?= e(carenest_url($photoUrl)) ?>" alt="<?= e('Profile photo') ?>" style="width:100%;max-width:180px;height:auto;border-radius:12px;object-fit:cover;">
                <?php else: ?>
                    <div class="cn-card cn-card-body text-center" style="color:var(--text-secondary);max-width:180px;"><?= e('No photo uploaded') ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-9">
                <div class="row g-3">
                    <div class="col-md-6"><strong><?= e('Full name:') ?></strong> <?= e(trim((string) ($user['Fname'] ?? '') . ' ' . (string) ($user['Lname'] ?? ''))) ?></div>
                    <div class="col-md-6"><strong><?= e('Email:') ?></strong> <?= e((string) ($user['email'] ?? '—')) ?></div>
                    <div class="col-md-6"><strong><?= e('Phone:') ?></strong> <?= e((string) ($user['phone'] ?? '—')) ?></div>
                    <div class="col-md-6"><strong><?= e('National ID:') ?></strong> <?= e((string) ($user['national_id_number'] ?? '—')) ?></div>
                    <div class="col-md-6"><strong><?= e('Role:') ?></strong> <?= e((string) ($user['role_type'] ?? 'Pal')) ?></div>
                    <div class="col-md-6"><strong><?= e('Account status:') ?></strong> <?= e((string) ($user['account_status'] ?? 'Pending')) ?></div>
                    <div class="col-md-6"><strong><?= e('Registered at:') ?></strong> <?= e((string) ($user['created_at'] ?? '—')) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h5 mb-4"><?= e('Profile & availability compass') ?></h1>
        <form method="post" class="row g-4">
            <input type="hidden" name="form_type" value="availability">
            <div class="col-md-4">
                <label class="cn-label"><?= e('Availability') ?></label>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="available" <?= !empty($p['is_available']) ? 'checked' : '' ?> id="avail">
                    <label class="form-check-label" style="color:var(--text-secondary);" for="avail"><?= e('Announce me as reachable') ?></label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="cn-label"><?= e('Comfort travel radius') ?> (km)</label>
                <input class="cn-input" type="number" name="travel_radius_km" min="5" step="5" value="<?= (int) ($p['travel_radius_km'] ?? 25) ?>">
            </div>
            <div class="col-md-12">
                <button class="cn-btn cn-btn-primary"><?= e('Save') ?></button>
            </div>
        </form>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h2 class="h6 mb-4"><?= e('Edit Password') ?></h2>
        <form method="post" class="row g-3">
            <input type="hidden" name="form_type" value="password">
            <div class="col-md-4">
                <label class="cn-label"><?= e('Current password') ?></label>
                <input type="password" class="cn-input" name="current_password" required>
            </div>
            <div class="col-md-4">
                <label class="cn-label"><?= e('New password') ?></label>
                <input type="password" class="cn-input" name="new_password" required>
            </div>
            <div class="col-md-4">
                <label class="cn-label"><?= e('Confirm new password') ?></label>
                <input type="password" class="cn-input" name="confirm_password" required>
            </div>
            <div class="col-12">
                <button class="cn-btn cn-btn-primary"><?= e('Update Password') ?></button>
            </div>
        </form>
    </div>

    <section id="badges">
        <h2 class="h6 mb-4"><?= e('Skill badges tracked by CareNest admins') ?></h2>
        <div class="row g-4">
            <?php foreach ($badgeRows as $b): ?>
                <div class="col-md-4">
                    <div class="cn-card cn-card-body">
                        <?= e((string) ($b['badge_name'] ?? 'Badge #' . ($b['badge_ID'] ?? ''))) ?>
                        <div class="small mt-2" style="color:var(--text-secondary);">
                            <?= e('Status: ' . (string) ($b['verification_status'] ?? 'Pending')) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$badgeRows): ?>
                <div class="small" style="color:var(--text-secondary);"><?= e('No badges yet — finish modules to sparkle here.') ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section id="registration-documents" class="mt-5">
        <h2 class="h6 mb-3"><?= e('Registration Documents') ?></h2>
        <div class="cn-card cn-card-body">
            <?php if ($docRows): ?>
                <div class="table-responsive">
                    <table class="cn-table mb-0">
                        <thead>
                        <tr>
                            <th><?= e('Type') ?></th>
                            <th><?= e('File') ?></th>
                            <th><?= e('Uploaded at') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($docRows as $d): ?>
                            <tr>
                                <td><?= e((string) ($d['document_type'] ?? 'Document')) ?></td>
                                <td>
                                    <?php $fileUrl = (string) ($d['file_url'] ?? ''); ?>
                                    <?php if ($fileUrl !== ''): ?>
                                        <a href="<?= e(carenest_url($fileUrl)) ?>" target="_blank" rel="noopener noreferrer">
                                            <?= e((string) ($d['original_name'] ?? basename($fileUrl))) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= e('—') ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($d['uploaded_at'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="small" style="color:var(--text-secondary);"><?= e('No registration documents found.') ?></div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
