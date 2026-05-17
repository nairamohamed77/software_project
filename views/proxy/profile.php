<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
requireRole('FamilyProxy');
require_once dirname(__DIR__, 2) . '/models/User.php';
require_once dirname(__DIR__, 2) . '/models/RegistrationDocument.php';

$db = Database::getInstance()->getConnection();
User::ensureReady();
RegistrationDocument::ensureTable();

$hasNationalIdColumn = false;
try {
    $colStmt = $db->query("SHOW COLUMNS FROM users LIKE 'national_id_number'");
    $hasNationalIdColumn = (bool) ($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasNationalIdColumn = false;
}
$nationalSelect = $hasNationalIdColumn ? 'national_id_number' : "NULL AS national_id_number";

$userStmt = $db->prepare("SELECT User_ID, Fname, Lname, email, phone, profile_photo_url, role_type, account_status, created_at, {$nationalSelect}, password_hash FROM users WHERE User_ID = ? LIMIT 1");
$userStmt->execute([currentUserId()]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$docRows = [];
try {
    $dq = $db->prepare('SELECT document_type, original_name, file_url, uploaded_at FROM registration_documents WHERE User_ID = ? ORDER BY registration_document_ID DESC LIMIT 40');
    $dq->execute([currentUserId()]);
    $docRows = $dq->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $docRows = [];
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $currentPass = (string) ($_POST['current_password'] ?? '');
        $newPass = (string) ($_POST['new_password'] ?? '');
        $confirmPass = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
            throw new RuntimeException('Please complete all password fields.');
        }
        if ($newPass !== $confirmPass) {
            throw new RuntimeException('New password and confirmation do not match.');
        }
        if (strlen($newPass) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPass, $hash)) {
            throw new RuntimeException('Current password is incorrect.');
        }

        $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $upd = $db->prepare('UPDATE users SET password_hash = ? WHERE User_ID = ? LIMIT 1');
        $upd->execute([$newHash, currentUserId()]);
        $msg = 'Password updated successfully.';
        $userStmt->execute([currentUserId()]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
    }
}

$pageTitle = 'Family Proxy Profile — CareNest';
$active = 'profile';
?>

<?php include dirname(__DIR__) . '/layouts/header.php'; ?>
<?php include dirname(__DIR__) . '/layouts/sidebar_proxy.php'; ?>

<main class="main-content">
    <?php if ($msg !== ''): ?>
        <div class="alert-cn <?= strtolower($msg) === 'password updated successfully.' ? 'alert-cn-success' : 'alert-cn-danger' ?> mb-4"><?= e($msg) ?></div>
    <?php endif; ?>

    <div class="cn-card cn-card-body mb-4">
        <h1 class="h4 mb-4"><?= e('Registration Details') ?></h1>
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
                    <div class="col-md-6"><strong><?= e('Role:') ?></strong> <?= e((string) ($user['role_type'] ?? 'FamilyProxy')) ?></div>
                    <div class="col-md-6"><strong><?= e('Account status:') ?></strong> <?= e((string) ($user['account_status'] ?? 'Pending')) ?></div>
                    <div class="col-md-6"><strong><?= e('Registered at:') ?></strong> <?= e((string) ($user['created_at'] ?? '—')) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="cn-card cn-card-body mb-4">
        <h2 class="h6 mb-4"><?= e('Edit Password') ?></h2>
        <form method="post" class="row g-3">
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

    <div class="cn-card cn-card-body">
        <h2 class="h6 mb-3"><?= e('Registration Documents') ?></h2>
        <?php if ($docRows): ?>
            <div class="table-responsive">
                <table class="cn-table mb-0">
                    <thead><tr><th><?= e('Type') ?></th><th><?= e('File') ?></th><th><?= e('Uploaded at') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($docRows as $d): ?>
                        <tr>
                            <td><?= e((string) ($d['document_type'] ?? 'Document')) ?></td>
                            <td>
                                <?php $fileUrl = (string) ($d['file_url'] ?? ''); ?>
                                <?php if ($fileUrl !== ''): ?>
                                    <a href="<?= e(carenest_url($fileUrl)) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) ($d['original_name'] ?? basename($fileUrl))) ?></a>
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
</main>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
