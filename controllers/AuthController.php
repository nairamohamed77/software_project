<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], !empty($p['secure']), (bool) $p['httponly']);
    }
    session_destroy();
    header('Location: ' . carenest_url('views/auth/login.php'));
    exit;
}

http_response_code(404);
exit('Not found');
