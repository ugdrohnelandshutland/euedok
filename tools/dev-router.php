<?php
/*
 * Nur für die Entwicklung mit dem eingebauten PHP-Server:
 *   php -S localhost:8080 -t public tools/dev-router.php
 * Bildet die .htaccess-Regel /q/<key> nach.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = dirname(__DIR__) . '/public';

if (preg_match('#^/q/([0-9A-Za-z-]{20,64})/?$#', $path, $m)) {
    $_GET['key'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/quick-entry.php';
    require $public . '/quick-entry.php';
    return true;
}
if ($path === '/' ) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require $public . '/index.php';
    return true;
}
if (str_ends_with($path, '.webmanifest') && is_file($public . $path)) {
    header('Content-Type: application/manifest+json');
    readfile($public . $path);
    return true;
}
return false;
