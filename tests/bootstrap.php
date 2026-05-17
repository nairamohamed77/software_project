<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(
        STDERR,
        "Composer autoload not found. Run: composer install\n(from the senior_care directory)\n"
    );
    exit(1);
}

require_once $autoload;

require_once dirname(__DIR__) . '/config/constants.php';
