<?php
declare(strict_types=1);

/**
 * CLI / scheduled task: inactive senior welfare scan.
 * Usage: php welfare_inactivity_cron.php [days]
 * Example: php welfare_inactivity_cron.php 7
 */

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/models/WelfareInactivity.php';

$days = isset($argv[1]) ? (int) $argv[1] : WelfareInactivity::DEFAULT_INACTIVITY_DAYS;
$result = WelfareInactivity::runScan($days);
echo json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
