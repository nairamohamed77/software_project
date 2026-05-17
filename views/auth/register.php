<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
require_once dirname(__DIR__, 2) . '/models/User.php';
require_once dirname(__DIR__, 2) . '/models/BackgroundCheck.php';
require_once dirname(__DIR__, 2) . '/models/RegistrationDocument.php';

$proxyRelationshipOptions = ['Son', 'Daughter', 'Spouse', 'Sibling', 'Friend', 'Caregiver', 'Other'];
$seniorsForProxy = [];
try {
    $pdoList = Database::getInstance()->getConnection();
    $stmtList = $pdoList->query(
        'SELECT sp.senior_ID, u.Fname, u.Lname, u.email
         FROM senior_profiles sp
         INNER JOIN users u ON u.User_ID = sp.User_ID
         WHERE u.role_type = \'Senior\'
         ORDER BY u.Lname, u.Fname'
    );
    $seniorsForProxy = $stmtList->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $seniorsForProxy = [];
}

if (!empty($_SESSION['user_id'])) {
    $u = User::findById(currentUserId());
    if ($u !== null) {
        header('Location: ' . carenest_url(User::dashboardPathFor($u)));
        exit;
    }
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    User::ensureReady();
    BackgroundCheck::ensureReady();
    RegistrationDocument::ensureTable();

    $fname = trim((string) ($_POST['fname'] ?? ''));
    $lname = trim((string) ($_POST['lname'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $nationalIdNumber = preg_replace('/\D+/', '', (string) ($_POST['national_id_number'] ?? '')) ?? '';
    $pass = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password_confirm'] ?? '');
    $role = (string) ($_POST['role_type'] ?? '');
    $seniorAge = (int) ($_POST['senior_age'] ?? 0);
    $terms = isset($_POST['terms']);

    if ($fname === '' || $lname === '' || $email === '' || $phone === '' || $pass === '') {
        $errors[] = 'All required fields must be completed.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email.';
    }
    if ($nationalIdNumber === '' || strlen($nationalIdNumber) < 10 || strlen($nationalIdNumber) > 20) {
        $errors[] = 'National ID is required (10-20 digits).';
    }
    if (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($pass !== $pass2) {
        $errors[] = 'Passwords do not match.';
    }
    if (!in_array($role, ['Senior', 'Pal', 'FamilyProxy'], true)) {
        $errors[] = 'Please select a valid role.';
    }
    if ($role === 'Senior') {
        if ($seniorAge < 50 || $seniorAge > 120) {
            $errors[] = 'Please enter a valid senior age (50-120).';
        }
    }
    if (!$terms) {
        $errors[] = 'You must accept the Terms & Conditions.';
    }

    $photoMeta = null;
    $profilePhotoExt = '';
    if (!empty($_FILES['profile_photo']['name'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo((string) $_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $profilePhotoExt = $ext;
        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Invalid file type for profile photo.';
        }
        if (($_FILES['profile_photo']['size'] ?? 0) > 5 * 1024 * 1024) {
            $errors[] = 'File too large (max 5MB).';
        }
    }

    $badgeName = trim((string) ($_POST['skill_badge_name'] ?? ''));
    if ($role === 'Pal') {
        if ($badgeName === '') {
            $errors[] = 'Skill badge name is required for Pal registration.';
        }
        if ($nationalIdNumber === '' || strlen($nationalIdNumber) < 10 || strlen($nationalIdNumber) > 20) {
            $errors[] = 'National ID is required for Pal registration (10-20 digits).';
        }
        if (empty($_FILES['profile_photo']['name']) || !is_uploaded_file((string) ($_FILES['profile_photo']['tmp_name'] ?? ''))) {
            $errors[] = 'Personal photo is required for Pal registration.';
        } elseif (!in_array($profilePhotoExt, ['jpg', 'jpeg', 'png'], true)) {
            $errors[] = 'Personal photo must be JPG or PNG for Pal registration.';
        }

        if (empty($_FILES['skill_badge_file']['name']) || !is_uploaded_file((string) ($_FILES['skill_badge_file']['tmp_name'] ?? ''))) {
            $errors[] = 'Skill badge certificate file is required for Pal registration.';
        }
        if (empty($_FILES['background_doc_file']['name']) || !is_uploaded_file((string) ($_FILES['background_doc_file']['tmp_name'] ?? ''))) {
            $errors[] = 'Background check document is required for Pal registration.';
        }

        foreach (['skill_badge_file', 'background_doc_file'] as $requiredFile) {
            if (!empty($_FILES[$requiredFile]['name'])) {
                $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
                $ext = strtolower(pathinfo((string) $_FILES[$requiredFile]['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed, true)) {
                    $errors[] = 'Invalid file type for ' . str_replace('_', ' ', $requiredFile) . '.';
                }
                if (($_FILES[$requiredFile]['size'] ?? 0) > 5 * 1024 * 1024) {
                    $errors[] = 'File too large for ' . str_replace('_', ' ', $requiredFile) . ' (max 5MB).';
                }
            }
        }
    }

    $linkedSeniorId = (int) ($_POST['linked_senior_id'] ?? 0);
    $proxyRelationship = trim((string) ($_POST['proxy_relationship'] ?? ''));
    $validSeniorIds = array_map(static fn ($r) => (int) ($r['senior_ID'] ?? 0), $seniorsForProxy);
    $validSeniorIds = array_values(array_filter($validSeniorIds, static fn ($id) => $id > 0));

    if ($role === 'FamilyProxy') {
        if ($linkedSeniorId <= 0) {
            $errors[] = 'Please select the senior you manage.';
        } elseif (!in_array($linkedSeniorId, $validSeniorIds, true)) {
            $errors[] = 'That senior selection is not valid.';
        }
        if ($proxyRelationship === '' || !in_array($proxyRelationship, $proxyRelationshipOptions, true)) {
            $errors[] = 'Please select your relationship to the senior.';
        }
        if ($validSeniorIds === []) {
            $errors[] = 'No senior accounts are available to link yet. The senior must register first, then you can sign up as their Family Proxy.';
        }
    }

    if (!$errors) {
        if (!empty($_FILES['profile_photo']['name']) && is_uploaded_file((string) ($_FILES['profile_photo']['tmp_name'] ?? ''))) {
            $ext = strtolower(pathinfo((string) $_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $dir = dirname(__DIR__, 2) . '/uploads/profiles/';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $fnameSafe = 'u_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = $dir . $fnameSafe;
            if (move_uploaded_file((string) $_FILES['profile_photo']['tmp_name'], $dest)) {
                $photoMeta = ['fpath' => 'uploads/profiles/' . $fnameSafe, 'type' => $ext];
            }
        }

        $res = User::register($fname, $lname, $email, $phone, $nationalIdNumber, $pass, $role, $photoMeta);
        if (!$res['ok']) {
            $errors[] = (string) ($res['message'] ?? 'Registration failed.');
        } else {
            $newUserId = (int) ($res['user_id'] ?? 0);
            try {
                if ($role === 'Pal') {
                    $db = Database::getInstance()->getConnection();
                    $db->beginTransaction();

                    $palStmt = $db->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID = ? LIMIT 1');
                    $palStmt->execute([$newUserId]);
                    $palId = (int) ($palStmt->fetch()['pal_ID'] ?? 0);
                    if ($palId <= 0) {
                        throw new RuntimeException('Pal profile not found after registration.');
                    }

                    $moveRequiredUpload = static function (string $fieldName, string $targetDir, string $prefix): string {
                        $ext = strtolower(pathinfo((string) $_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
                        $dir = dirname(__DIR__, 2) . '/' . trim($targetDir, '/') . '/';
                        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                            throw new RuntimeException('Unable to prepare upload directory: ' . $targetDir);
                        }
                        $fileName = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $fullPath = $dir . $fileName;
                        if (!move_uploaded_file((string) $_FILES[$fieldName]['tmp_name'], $fullPath)) {
                            throw new RuntimeException('Upload failed for ' . $fieldName . '.');
                        }
                        return trim($targetDir, '/') . '/' . $fileName;
                    };

                    $badgeCertPath = $moveRequiredUpload('skill_badge_file', 'uploads/badges', 'badge');
                    $backgroundDocPath = $moveRequiredUpload('background_doc_file', 'uploads/documents', 'bgcheck');

                    $insBadge = $db->prepare(
                        "INSERT INTO skill_badges (pal_ID, badge_name, description, verification_status, certificate_url)
                         VALUES (?, ?, ?, 'Pending', ?)"
                    );
                    $insBadge->execute([$palId, $badgeName, 'Submitted during registration', $badgeCertPath]);

                    $insCheck = $db->prepare(
                        "INSERT INTO background_checks (pal_ID, status, id_document_url, criminal_record_url, national_id_number, notes)
                         VALUES (?, 'Pending', ?, ?, ?, ?)"
                    );
                    $insCheck->execute([$palId, $backgroundDocPath, $backgroundDocPath, $nationalIdNumber, 'Auto-created from registration']);

                    $db->commit();
                } elseif ($role === 'Senior') {
                    $db = Database::getInstance()->getConnection();
                    try {
                        $db->exec('ALTER TABLE senior_profiles ADD COLUMN IF NOT EXISTS age TINYINT UNSIGNED NULL AFTER User_ID');
                    } catch (Throwable $e) {
                        try {
                            $db->exec('ALTER TABLE senior_profiles ADD COLUMN age TINYINT UNSIGNED NULL');
                        } catch (Throwable $ignored) {
                        }
                    }
                    $updAge = $db->prepare('UPDATE senior_profiles SET age = ? WHERE User_ID = ? LIMIT 1');
                    $updAge->execute([$seniorAge, $newUserId]);
                } elseif ($role === 'FamilyProxy') {
                    $db = Database::getInstance()->getConnection();
                    $insLink = $db->prepare(
                        'INSERT INTO proxy_senior_link (proxy_User_ID, senior_ID, relationship_type, can_schedule, can_view_health, can_manage_points, is_primary)
                         VALUES (?, ?, ?, 1, 1, 0, 1)'
                    );
                    $insLink->execute([$newUserId, $linkedSeniorId, $proxyRelationship]);
                }

                if ($photoMeta !== null && !empty($photoMeta['fpath'])) {
                    RegistrationDocument::add(
                        $newUserId,
                        'Profile_Photo',
                        (string) ($_FILES['profile_photo']['name'] ?? 'profile_photo'),
                        (string) $photoMeta['fpath'],
                        (string) ($_FILES['profile_photo']['type'] ?? '')
                    );
                }

                if ($role === 'Pal') {
                    $dbPal = Database::getInstance()->getConnection();
                    $stmtP = $dbPal->prepare('SELECT pal_ID FROM pal_profiles WHERE User_ID = ? LIMIT 1');
                    $stmtP->execute([$newUserId]);
                    $palId = (int) ($stmtP->fetch()['pal_ID'] ?? 0);
                    if ($palId > 0) {
                        $stmtBadge = $dbPal->prepare('SELECT certificate_url FROM skill_badges WHERE pal_ID = ? ORDER BY badge_ID DESC LIMIT 1');
                        $stmtBadge->execute([$palId]);
                        $badgeFile = (string) ($stmtBadge->fetch()['certificate_url'] ?? '');
                        if ($badgeFile !== '') {
                            RegistrationDocument::add(
                                $newUserId,
                                'Skill_Badge_Certificate',
                                (string) ($_FILES['skill_badge_file']['name'] ?? 'skill_badge_file'),
                                $badgeFile,
                                (string) ($_FILES['skill_badge_file']['type'] ?? '')
                            );
                        }

                        $stmtBg = $dbPal->prepare('SELECT id_document_url FROM background_checks WHERE pal_ID = ? ORDER BY check_ID DESC LIMIT 1');
                        $stmtBg->execute([$palId]);
                        $bgFile = (string) ($stmtBg->fetch()['id_document_url'] ?? '');
                        if ($bgFile !== '') {
                            RegistrationDocument::add(
                                $newUserId,
                                'Background_Document',
                                (string) ($_FILES['background_doc_file']['name'] ?? 'background_doc_file'),
                                $bgFile,
                                (string) ($_FILES['background_doc_file']['type'] ?? '')
                            );
                        }
                    }
                }

                $success = true;
            } catch (Throwable $e) {
                try {
                    $db = Database::getInstance()->getConnection();
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    if ($newUserId > 0) {
                        $cleanup = $db->prepare('DELETE FROM users WHERE User_ID = ?');
                        $cleanup->execute([$newUserId]);
                    }
                } catch (Throwable $ignored) {
                }
                $errors[] = 'Registration could not complete verification setup. Please try again.';
            }
        }
    }
}

$pageTitle = 'Create Account — CareNest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= carenest_url('public/css/style.css') ?>">
</head>
<body class="py-5" style="background: var(--beige);">
<div class="container" style="max-width: 640px;">
    <div class="cn-card">
        <div class="cn-card-body">
            <h1 class="h3 mb-3">Create My Account</h1>
            <?php if ($success): ?>
                <div class="alert-cn alert-cn-success">Account created! Awaiting admin approval.</div>
                <a class="cn-btn cn-btn-primary" href="<?= carenest_url('views/auth/login.php') ?>">Go to Sign In</a>
            <?php else: ?>
                <?php foreach ($errors as $er): ?>
                    <div class="alert-cn alert-cn-danger"><?= e($er) ?></div>
                <?php endforeach; ?>
                <form method="post" enctype="multipart/form-data" class="mt-2">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="cn-label">First Name</label>
                            <input class="cn-input" name="fname" required value="<?= e((string) ($_POST['fname'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="cn-label">Last Name</label>
                            <input class="cn-input" name="lname" required value="<?= e((string) ($_POST['lname'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="cn-label">Email</label>
                        <input type="email" class="cn-input" name="email" required value="<?= e((string) ($_POST['email'] ?? '')) ?>">
                    </div>
                    <div class="mt-3">
                        <label class="cn-label">Phone</label>
                        <input class="cn-input" name="phone" required value="<?= e((string) ($_POST['phone'] ?? '')) ?>">
                    </div>
                    <div class="mt-3">
                        <label class="cn-label">National ID Number</label>
                        <input class="cn-input" name="national_id_number" required value="<?= e((string) ($_POST['national_id_number'] ?? '')) ?>">
                    </div>
                    <div class="mt-3">
                        <label class="cn-label">Password</label>
                        <input type="password" class="cn-input" name="password" required>
                    </div>
                    <div class="mt-3">
                        <label class="cn-label">Confirm Password</label>
                        <input type="password" class="cn-input" name="password_confirm" required>
                    </div>

                    <div class="mt-4">
                        <div class="cn-label">I am joining as</div>
                        <div class="row g-2">
                            <label class="col-md-4">
                                <div class="role-card-select h-100">
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="radio" name="role_type" value="Senior" <?= (($_POST['role_type'] ?? '') === 'Senior') ? 'checked' : '' ?> required>
                                        <span><span class="me-1">&#127968;</span> I need help</span>
                                    </div>
                                    <div class="small text-secondary">Senior</div>
                                </div>
                            </label>
                            <label class="col-md-4">
                                <div class="role-card-select h-100">
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="radio" name="role_type" value="Pal" <?= (($_POST['role_type'] ?? '') === 'Pal') ? 'checked' : '' ?>>
                                        <span><span class="me-1">&#129309;</span> I want to help</span>
                                    </div>
                                    <div class="small text-secondary">Pal</div>
                                </div>
                            </label>
                            <label class="col-md-4">
                                <div class="role-card-select h-100">
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="radio" name="role_type" value="FamilyProxy" <?= (($_POST['role_type'] ?? '') === 'FamilyProxy') ? 'checked' : '' ?>>
                                        <span><span class="me-1">&#128107;</span> I manage a senior</span>
                                    </div>
                                    <div class="small text-secondary">Family Proxy</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="cn-label">Profile Photo (optional)</label>
                        <input type="file" name="profile_photo" class="form-control cn-input border" accept=".jpg,.jpeg,.png,.pdf">
                    </div>

                    <div class="mt-4" id="proxy-senior-fields" style="<?= (($_POST['role_type'] ?? '') === 'FamilyProxy') ? '' : 'display:none;' ?>">
                        <div class="cn-card cn-card-body">
                            <h3 class="h6 mb-3">Senior you manage</h3>
                            <?php if ($seniorsForProxy === []): ?>
                                <div class="alert-cn alert-cn-warning mb-0">No senior accounts exist yet. The person you support should create a Senior account first; then return here to register as their Family Proxy.</div>
                            <?php else: ?>
                                <div class="mb-3">
                                    <label class="cn-label" for="linked_senior_id">Select senior</label>
                                    <select class="cn-input" id="linked_senior_id" name="linked_senior_id">
                                        <option value="">— Choose —</option>
                                        <?php foreach ($seniorsForProxy as $sr): ?>
                                            <?php
                                            $sid = (int) ($sr['senior_ID'] ?? 0);
                                            $sel = (int) ($_POST['linked_senior_id'] ?? 0) === $sid ? ' selected' : '';
                                            $label = trim((string) ($sr['Fname'] ?? '') . ' ' . (string) ($sr['Lname'] ?? '')) . ' (' . (string) ($sr['email'] ?? '') . ')';
                                            ?>
                                            <option value="<?= $sid ?>"<?= $sel ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="cn-label" for="proxy_relationship">Your relationship</label>
                                    <select class="cn-input" id="proxy_relationship" name="proxy_relationship">
                                        <option value="">— Choose —</option>
                                        <?php foreach ($proxyRelationshipOptions as $rel): ?>
                                            <?php $rsel = (string) ($_POST['proxy_relationship'] ?? '') === $rel ? ' selected' : ''; ?>
                                            <option value="<?= e($rel) ?>"<?= $rsel ?>><?= e($rel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-4" id="senior-registration-fields" style="<?= (($_POST['role_type'] ?? '') === 'Senior') ? '' : 'display:none;' ?>">
                        <div class="cn-card cn-card-body">
                            <h3 class="h6 mb-3">Senior Details</h3>
                            <div>
                                <label class="cn-label">Age</label>
                                <input class="cn-input" type="number" min="50" max="120" name="senior_age" value="<?= e((string) ($_POST['senior_age'] ?? '')) ?>" placeholder="e.g. 68">
                            </div>
                        </div>
                    </div>

                    <div class="mt-4" id="pal-verification-fields" style="<?= (($_POST['role_type'] ?? '') === 'Pal') ? '' : 'display:none;' ?>">
                        <div class="cn-card cn-card-body">
                            <h3 class="h6 mb-3">Pal Verification (required for Pal accounts)</h3>
                            <div class="mb-3">
                                <label class="cn-label">Skill Badge Name</label>
                                <input class="cn-input" name="skill_badge_name" value="<?= e((string) ($_POST['skill_badge_name'] ?? '')) ?>" placeholder="e.g. First Aid Certified">
                            </div>
                            <div class="mb-3">
                                <label class="cn-label">Skill Badge Certificate (PDF/JPG/PNG)</label>
                                <input type="file" name="skill_badge_file" class="form-control cn-input border" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                            <div>
                                <label class="cn-label">Background Check Document (PDF/JPG/PNG)</label>
                                <input type="file" name="background_doc_file" class="form-control cn-input border" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                        </div>
                    </div>

                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="terms" value="1" id="terms" <?= isset($_POST['terms']) ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="terms" style="color: var(--text-secondary);">I agree to the Terms &amp; Conditions</label>
                    </div>

                    <button class="cn-btn cn-btn-caramel cn-btn-block mt-4"><?= e('Create My Account') ?></button>
                </form>
            <?php endif; ?>

            <?php if (!$success): ?>
                <div class="text-center mt-3">
                    <a href="<?= carenest_url('views/auth/login.php') ?>">Already have an account? Sign in</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
function cnUpdateRoleSections() {
    var role = document.querySelector('input[name="role_type"]:checked');
    var val = role ? role.value : '';
    var palBox = document.getElementById('pal-verification-fields');
    var proxyBox = document.getElementById('proxy-senior-fields');
    var seniorBox = document.getElementById('senior-registration-fields');
    if (palBox) palBox.style.display = val === 'Pal' ? '' : 'none';
    if (proxyBox) proxyBox.style.display = val === 'FamilyProxy' ? '' : 'none';
    if (seniorBox) seniorBox.style.display = val === 'Senior' ? '' : 'none';
}
document.querySelectorAll('input[name="role_type"]').forEach(function (radio) {
    radio.addEventListener('change', cnUpdateRoleSections);
});
cnUpdateRoleSections();
</script>
</body>
</html>
