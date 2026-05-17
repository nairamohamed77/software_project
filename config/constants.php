<?php
declare(strict_types=1);

/** Web path to app root (no trailing slash). Example: `/carenest/senior_care` when project is under htdocs/carenest/senior_care */
if (!defined('BASE_URL')) {
    define('BASE_URL', '/carenest/senior_care');
}

function carenest_url(string $path): string {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** Full site origin for absolute URLs, e.g. https://yourdomain.com (override with CARENST_PUBLIC_ORIGIN). */
function carenest_public_origin(): string {
    $fromEnv = trim((string) (getenv('CARENST_PUBLIC_ORIGIN') ?: ''));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host;
}

function carenest_abs_url(string $path): string {
    return carenest_public_origin() . carenest_url($path);
}

/** @param mixed $value */
function e(?string $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
