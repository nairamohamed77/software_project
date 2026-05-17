<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/constants.php';

$codeRaw = preg_replace('/\D/', '', (string) ($_GET['code'] ?? '403'));
http_response_code(strlen($codeRaw) === 3 ? (int)$codeRaw : 403);
$pageTitle = 'CareNest — Notice';
$messages = [
    '403' => 'You do not have permission to drift here.',
    '404' => 'This path is lovingly missing.',
    '500' => 'Our servers cuddled themselves too tightly — pause and retry.',
];

$explain = $messages[$codeRaw] ?? $messages['403'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= carenest_url('public/css/style.css') ?>">
</head>
<body class="py-5" style="background:var(--beige);">
<div class="container" style="max-width:760px;">
    <div class="cn-card cn-card-body text-center">
        <div class="display-3 fw-semibold"><?= e($codeRaw ?: '403') ?></div>
        <p><?= e($explain) ?></p>
        <a class="cn-btn cn-btn-primary" href="<?= carenest_url('views/auth/login.php') ?>"><?= e('Return toward login') ?></a>
    </div>
</div>
</body>
</html>
