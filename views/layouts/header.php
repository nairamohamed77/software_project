<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string|null $extraBodyClass */
$pageTitle ??= 'CareNest';

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
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
<body class="<?= isset($extraBodyClass) ? e($extraBodyClass) : '' ?>">
