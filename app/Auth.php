<?php
declare(strict_types=1);

namespace Euedok;

/** Anmeldung, „angemeldet bleiben“, Sperre nach Fehlversuchen, Rechte. */
final class Auth
{
    private const REMEMBER_COOKIE = 'euedok_remember';
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Immer eigener Sitzungsordner (auch schon im Einrichtungsassistenten), sonst geht die
        // Sitzung verloren, sobald data/ angelegt wird.
        $dir = Storage::path('sessions');
        try {
            Storage::ensureDir($dir);
            session_save_path($dir);
        } catch (\RuntimeException) {
            // data/ nicht beschreibbar – der Assistent zeigt das an
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '43200');
        session_name('euedok');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_url(),
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        if (!Settings::isInstalled()) {
            return null;
        }
        $uid = $_SESSION['uid'] ?? null;
        if ($uid === null) {
            $uid = self::restoreFromCookie();
        }
        if ($uid !== null) {
            $u = self::findUser(['id' => $uid]);
            if ($u && !empty($u['active'])) {
                self::$user = $u;
            } else {
                unset($_SESSION['uid']);
            }
        }
        return self::$user;
    }

    public static function canManageSettings(): bool
    {
        return !empty(self::user()['can_manage_settings']);
    }

    public static function requireLogin(): array
    {
        if (!Settings::isInstalled()) {
            redirect('setup.php');
        }
        $u = self::user();
        if ($u === null) {
            if (is_api_request()) {
                json_out(['ok' => false, 'error' => 'Nicht angemeldet.'], 401);
            }
            $target = $_SERVER['REQUEST_URI'] ?? '';
            $_SESSION['after_login'] = is_string($target) ? $target : '';
            redirect('index.php?login=1');
        }
        return $u;
    }

    public static function requireSettingsRight(): array
    {
        $u = self::requireLogin();
        if (empty($u['can_manage_settings'])) {
            http_response_code(403);
            exit('Keine Berechtigung für die Einstellungen.');
        }
        return $u;
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public static function attempt(string $username, string $password, bool $remember): ?string
    {
        $key = hash('sha256', strtolower($username) . '|' . client_ip());
        $attempts = Storage::read('auth/login-attempts.json', []);
        $entry = $attempts[$key] ?? ['count' => 0, 'until' => 0];
        if ($entry['until'] > time()) {
            $min = (int) ceil(($entry['until'] - time()) / 60);
            return "Zu viele Fehlversuche. Bitte in {$min} Minuten erneut versuchen.";
        }

        $u = self::findUser(['username' => strtolower($username)]);
        $ok = $u && !empty($u['active']) && !empty($u['password_hash']) && password_verify($password, $u['password_hash']);
        if (!$ok) {
            $entry['count']++;
            if ($entry['count'] >= self::MAX_ATTEMPTS) {
                $entry = ['count' => 0, 'until' => time() + self::LOCK_SECONDS];
            }
            $attempts[$key] = $entry;
            Storage::write('auth/login-attempts.json', self::pruneAttempts($attempts));
            return 'Benutzername oder Passwort falsch.';
        }
        unset($attempts[$key]);
        Storage::write('auth/login-attempts.json', self::pruneAttempts($attempts));

        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            self::updateUser($u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        self::login($u, $remember);
        return null;
    }

    public static function login(array $u, bool $remember): void
    {
        session_regenerate_id(true);
        unset($_SESSION['qr']);
        $_SESSION['uid'] = $u['id'];
        self::$user = $u;
        self::$loaded = true;
        if ($remember) {
            self::issueRememberToken($u['id']);
        }
    }

    public static function logout(): void
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (is_string($cookie) && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            Storage::locked('remember', static function () use ($selector) {
                $tokens = Storage::read('auth/remember.json', []);
                unset($tokens[$selector]);
                Storage::write('auth/remember.json', $tokens);
            });
        }
        self::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    private static function issueRememberToken(string $uid): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $days = max(1, (int) (Settings::unit()['remember_days'] ?? 30));
        $expires = time() + $days * 86400;
        Storage::locked('remember', static function () use ($selector, $validator, $uid, $expires) {
            $tokens = Storage::read('auth/remember.json', []);
            $tokens = array_filter($tokens, static fn ($t) => ($t['expires'] ?? 0) > time());
            $tokens[$selector] = ['user_id' => $uid, 'hash' => hash('sha256', $validator), 'expires' => $expires];
            Storage::write('auth/remember.json', $tokens);
        });
        self::setCookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, $expires);
    }

    private static function restoreFromCookie(): ?string
    {
        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if (!is_string($cookie) || !str_contains($cookie, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $cookie, 2);
        $tokens = Storage::read('auth/remember.json', []);
        $t = $tokens[$selector] ?? null;
        if (!$t || $t['expires'] < time() || !hash_equals($t['hash'], hash('sha256', $validator))) {
            self::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $t['user_id'];
        return $t['user_id'];
    }

    public static function setCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => base_url(),
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Alle Anmeldungen eines Zugangs beenden (z. B. nach Passwortänderung). */
    public static function revokeRememberTokens(string $uid): void
    {
        Storage::locked('remember', static function () use ($uid) {
            $tokens = Storage::read('auth/remember.json', []);
            $tokens = array_filter($tokens, static fn ($t) => ($t['user_id'] ?? '') !== $uid);
            Storage::write('auth/remember.json', $tokens);
        });
    }

    private static function pruneAttempts(array $attempts): array
    {
        return array_filter($attempts, static fn ($a) => ($a['until'] ?? 0) > time() || ($a['count'] ?? 0) > 0);
    }

    public static function users(): array
    {
        return Settings::get('users')['users'];
    }

    public static function findUser(array $criteria): ?array
    {
        foreach (self::users() as $u) {
            $match = true;
            foreach ($criteria as $k => $v) {
                if (($u[$k] ?? null) !== $v) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $u;
            }
        }
        return null;
    }

    public static function updateUser(string $id, array $changes): void
    {
        $all = Settings::get('users');
        foreach ($all['users'] as &$u) {
            if ($u['id'] === $id) {
                $u = array_merge($u, $changes);
            }
        }
        unset($u);
        Settings::save('users', $all);
    }

    /* ---------- QR-Schnellerfassung ---------- */

    public static function qrContext(): ?array
    {
        return $_SESSION['qr'] ?? null;
    }
}
