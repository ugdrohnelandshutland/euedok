<?php
/*
 * Nur für die Entwicklung mit dem eingebauten PHP-Server:
 *   php -S localhost:8080 -t public tools/dev-router.php
 * Bildet die Umschreib-Regeln aus public/.htaccess nach (sprechende Pfade, /q/<key>).
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = dirname(__DIR__) . '/public';

$routes = [
    '#^/$#' => ['index.php', []],
    '#^/manifest\.webmanifest$#' => ['manifest.php', []],
    '#^/q/([0-9A-Za-z-]{20,64})/?$#' => ['quick-entry.php', ['key' => 1]],
    '#^/q/?$#' => ['quick-entry.php', []],
    '#^/login/?$#' => ['index.php', ['login' => '=1']],
    '#^/actions/(login|logout|new|discard|resend)$#' => ['index.php', ['action' => 1]],
    '#^/setup/?$#' => ['setup.php', []],
    '#^/reports/?$#' => ['index.php', ['view' => '=reports']],
    '#^/reports/(\d{4}|all)\.zip$#' => ['download.php', ['zip' => 1]],
    '#^/reports/([A-Za-z0-9._-]+)/(pdf|json)$#' => ['download.php', ['n' => 1, 'f' => 2]],
    '#^/reports/([A-Za-z0-9._-]+)/edit$#' => ['report.php', ['n' => 1, 'edit' => '=1']],
    '#^/reports/([A-Za-z0-9._-]+)/?$#' => ['report.php', ['n' => 1]],
    '#^/settings/?$#' => ['settings.php', []],
    '#^/settings/([a-z]+)/?$#' => ['settings.php', ['s' => 1]],
];
foreach ($routes as $re => [$script, $params]) {
    if (preg_match($re, $path, $m)) {
        // wie [QSA]: Werte aus dem Pfad zuerst, die Anfrage-Parameter bleiben erhalten
        foreach ($params as $k => $v) {
            $_GET[$k] = is_string($v) ? substr($v, 1) : $m[$v];
        }
        $_SERVER['SCRIPT_NAME'] = '/' . $script;
        chdir($public);
        require $public . '/' . $script;
        return true;
    }
}
// vorhandene Dateien (Assets, *.php) normal ausliefern; sonst 404 wie Apache
// (der eingebaute Server würde sonst stillschweigend index.php zeigen)
if (is_file($public . $path)) {
    return false;
}
http_response_code(404);
echo 'Nicht gefunden';
return true;
