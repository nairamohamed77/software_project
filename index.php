<?php
declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';

header('Location: ' . carenest_url('views/auth/login.php'));
exit;
