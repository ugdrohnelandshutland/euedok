<?php
declare(strict_types=1);

/* Kleine Hilfsfunktionen, die überall gebraucht werden. */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Pfad relativ zum Web-Root dieser Installation (funktioniert auch in Unterordnern).
 * Seiten werden über sprechende Pfade aufgerufen (z. B. „reports/E-2026-001“, siehe public/.htaccess) –
 * hier immer den Pfad angeben, nie die .php-Datei.
 */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        // Basis aus dem ausgeführten Skript; zeigt SCRIPT_NAME (je nach Server) auf den sprechenden Pfad, gilt das Web-Root
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $dir = str_ends_with($script, '.php') ? str_replace('\\', '/', dirname($script)) : '';
        $base = rtrim($dir, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/** Vollständige URL (für QR-Codes und Mails). */
function absolute_url(string $path = ''): string
{
    $configured = trim((string) (\Euedok\Settings::unit()['public_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/' . ltrim($path, '/');
    }
    $https = is_https();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . base_url($path);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function is_api_request(): bool
{
    return str_ends_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 'api.php')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : base_url($path)));
    exit;
}

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'Sitzung abgelaufen. Bitte Seite neu laden.'], 419);
        }
        http_response_code(419);
        exit('Sitzung abgelaufen. Bitte zurück und Seite neu laden.');
    }
}

function flash(?string $message = null, string $kind = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'][] = ['text' => $message, 'kind' => $kind];
        return null;
    }
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

function now_iso(): string
{
    return date('Y-m-d\TH:i:s');
}

function fmt_date(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $t = strtotime($iso);
    return $t ? date('d.m.Y', $t) : '';
}

function fmt_datetime(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $t = strtotime($iso);
    return $t ? date('d.m.Y H:i', $t) : '';
}

function fmt_duration(?int $minutes): string
{
    if ($minutes === null) {
        return '';
    }
    return sprintf('%d:%02d h', intdiv($minutes, 60), $minutes % 60);
}

/** Technische ID aus einer Bezeichnung (Business-Englisch-Regeln: klein, Bindestrich). */
function slugify(string $text): string
{
    $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss'];
    $text = strtr($text, $map);
    $text = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text));
    $text = trim($text, '-');
    return $text !== '' ? substr($text, 0, 40) : 'item';
}

/** Dateinamen-taugliche Schreibweise ohne Umlaute (für die deutschen Berichtsdateien). */
function ascii_name(string $text): string
{
    $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'];
    $text = strtr($text, $map);
    return (string) preg_replace('/[^A-Za-z0-9_-]+/', '', $text);
}

function unique_id(string $base, array $existing): string
{
    $id = $base;
    $n = 2;
    while (in_array($id, $existing, true)) {
        $id = $base . '-' . $n++;
    }
    return $id;
}

function initials(string $name): string
{
    $parts = preg_split('/[\s,]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (str_contains($name, ',') && count($parts) >= 2) {
        // "Nachname, Vorname" → VN
        return mb_strtoupper(mb_substr($parts[1], 0, 1) . mb_substr($parts[0], 0, 1));
    }
    $first = $parts[0] ?? '?';
    $last = $parts[count($parts) - 1] ?? '';
    return mb_strtoupper(mb_substr($first, 0, 1) . ($last !== $first ? mb_substr($last, 0, 1) : ''));
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="i ' . e($class) . '" aria-hidden="true"><use href="' . e(base_url('assets/icons/sprite.svg')) . '#i-' . e($name) . '"/></svg>';
}

function asset(string $path): string
{
    $file = EUEDOK_ROOT . '/public/assets/' . $path;
    $v = is_file($file) ? (string) filemtime($file) : EUEDOK_VERSION;
    return base_url('assets/' . $path) . '?v=' . $v;
}

/** Bindet eine Vorlage aus app/Views ein. */
function render(string $view, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require EUEDOK_ROOT . '/app/Views/' . $view . '.php';
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function post_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post_bool(string $key): bool
{
    return isset($_POST[$key]) && $_POST[$key] !== '0' && $_POST[$key] !== '';
}
