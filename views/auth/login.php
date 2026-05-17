<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
require_once dirname(__DIR__, 2) . '/models/User.php';

if (!empty($_SESSION['user_id'])) {
    $u = User::findById(currentUserId());
    if ($u !== null) {
        header('Location: ' . carenest_url(User::dashboardPathFor($u)));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        echo json_encode(['ok' => false, 'message' => 'Please fill in all fields.']);
        exit;
    }
    $user = User::tryLogin($email, $password);
    if ($user === null) {
        echo json_encode(['ok' => false, 'message' => 'Invalid credentials or account inactive.']);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) ($user['User_ID'] ?? $user['user_ID'] ?? $user['USER_ID'] ?? 0);
    $_SESSION['role'] = (string) ($user['role_type'] ?? '');
    $_SESSION['fname'] = (string) ($user['Fname'] ?? '');
    $_SESSION['lname'] = (string) ($user['Lname'] ?? '');

    echo json_encode(['ok' => true, 'redirect' => carenest_url(User::dashboardPathFor($user))]);
    exit;
}

$pageTitle = 'Sign In — CareNest';
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
<body>
<div class="container-fluid g-0">
    <div class="row g-0 min-vh-100">
        <div class="col-lg-6 auth-split-left d-flex flex-column justify-content-center p-5">
            <div class="auth-split-brand position-relative">
                <div class="display-5 fw-bold mb-3">CareNest</div>
                <p class="lead opacity-90">Neighborly care, SilverPoints trust, and dignity at every visit.</p>
                <p class="cn-lang-tagline mt-4 opacity-90">Safe support for seniors, trusted helpers, and families in one calm place.</p>
                <div class="mt-5 d-none d-md-block">
                    <i class="fas fa-hand-holding-heart fa-4x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-6 d-flex align-items-center justify-content-center p-4" style="background: var(--cream);">
            <div class="cn-card login-card-elevated w-100 position-relative" style="max-width:440px;z-index:1;">
                <div class="cn-card-body">
                    <h2 class="h3 mb-4" style="color: var(--text-primary);">Welcome back</h2>
                    <form id="cn-login-form" novalidate>
                        <input type="hidden" name="ajax" value="1">
                        <div class="mb-3">
                            <label class="cn-label">Email</label>
                            <input type="email" name="email" class="cn-input" placeholder="your@email.com" required autocomplete="username">
                        </div>
                        <div class="mb-3">
                            <label class="cn-label">Password</label>
                            <input type="password" name="password" class="cn-input" required autocomplete="current-password">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
                            <label class="form-check-label" for="remember" style="color: var(--text-secondary);">Remember Me</label>
                        </div>
                        <button type="submit" class="cn-btn cn-btn-caramel cn-btn-block"><?= e('Sign In to CareNest') ?></button>
                    </form>
                    <div id="login-error-box" class="login-error-box alert-cn alert-cn-danger d-none" role="alert"></div>
                    <div class="text-center mt-4">
                        <a href="<?= carenest_url('views/auth/register.php') ?>" style="color: var(--sage-dark);"><?= e("Don't have an account? Register") ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('cn-login-form').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var box = document.getElementById('login-error-box');
    box.classList.add('d-none');
    var fd = new FormData(this);
    fetch('<?= carenest_url('views/auth/login.php') ?>', {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(function (r){ return r.json(); })
        .then(function (j){
            if (j.ok) window.location.href = j.redirect;
            else { box.textContent = j.message || 'Login failed.'; box.classList.remove('d-none'); }
        })
        .catch(function (){
            box.textContent = 'Network error. Please try again.';
            box.classList.remove('d-none');
        });
});
</script>
</body>
</html>
