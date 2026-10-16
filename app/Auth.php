<?php
declare(strict_types=1);

namespace Euedok;

/** Anmeldung, „angemeldet bleiben“, Sperre nach Fehlversuchen, Rechte. */
final class Auth
{
    private const REMEMBER_COOKIE = 'euedok_remember';
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;
    private const MAX_ATTEMPTS_PER_IP = 20;
    /** Gültige Remember-Tokens bleiben nach dem Austausch kurz gültig (parallele Anfragen) */
    private const REMEMBER_GRACE = 60;
    /** Platzhalter-Hash: gleiche Rechenzeit bei unbekanntem Benutzernamen */
    private const DUMMY_HASH = '$2y$10$8i/OWJASIysO7ccVxpvDFeG8Ny7MUQDzJcOJRCplnyZJ10h5aJFDa';

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
            // Abgelaufene Sitzungsdateien selbst aufräumen (PHP-GC läuft bei vielen Hostern nicht)
            if (random_int(1, 50) === 1) {
                foreach (glob($dir . '/sess_*') ?: [] as $f) {
                    if (filemtime($f) < time() - 43200) {
                        @unlink($f);
                    }
                }
            }
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
            // ältere Sitzungen (vor diesem Update) ohne Zeitstempel einmalig übernehmen
            $_SESSION['login_at'] ??= time();
            $changed = !empty($u['password_changed_at']) ? (int) strtotime((string) $u['password_changed_at']) : 0;
            if ($u && !empty($u['active']) && $_SESSION['login_at'] >= $changed) {
                self::$user = $u;
            } else {
                if ($u && $_SESSION['login_at'] < $changed) {
                    Log::info('auth', 'session_expired_password', 'Sitzung nach Passwortänderung beendet', ['username' => $u['username']]);
                }
                unset($_SESSION['uid'], $_SESSION['login_at']);
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
        // zusätzlich je IP-Adresse über alle Benutzernamen (gegen Durchprobieren vieler Namen)
        $ipKey = 'ip|' . hash('sha256', client_ip());
        $attempts = Storage::read('auth/login-attempts.json', []);
        $entry = $attempts[$key] ?? ['count' => 0, 'until' => 0];
        $ipEntry = $attempts[$ipKey] ?? ['fails' => [], 'until' => 0];
        $until = max((int) $entry['until'], (int) ($ipEntry['until'] ?? 0));
        if ($until > time()) {
            Log::info('auth', 'login_blocked', 'Anmeldeversuch während Sperre', ['username' => $username]);
            $min = (int) ceil(($until - time()) / 60);
            return "Zu viele Fehlversuche. Bitte in {$min} Minuten erneut versuchen.";
        }

        $u = self::findUser(['username' => strtolower($username)]);
        $valid = $u && !empty($u['active']) && !empty($u['password_hash']);
        // immer eine Passwortprüfung rechnen, damit die Antwortzeit keine Benutzernamen verrät
        $verified = password_verify($password, $valid ? $u['password_hash'] : self::DUMMY_HASH);
        $ok = $valid && $verified;
        if (!$ok) {
            $entry['count']++;
            Log::warning('auth', 'login_failed', 'Anmeldung fehlgeschlagen', ['username' => $username, 'known_user' => (bool) $u, 'attempt' => $entry['count']]);
            if ($entry['count'] >= self::MAX_ATTEMPTS) {
                $entry = ['count' => 0, 'until' => time() + self::LOCK_SECONDS];
                Log::warning('auth', 'login_locked', 'Anmeldung 15 Minuten gesperrt', ['username' => $username]);
            }
            $fails = array_values(array_filter((array) ($ipEntry['fails'] ?? []), static fn ($t) => $t > time() - self::LOCK_SECONDS));
            $fails[] = time();
            $ipEntry = ['fails' => $fails, 'until' => (int) ($ipEntry['until'] ?? 0)];
            if (count($fails) >= self::MAX_ATTEMPTS_PER_IP) {
                $ipEntry = ['fails' => [], 'until' => time() + self::LOCK_SECONDS];
                Log::warning('auth', 'login_ip_locked', 'Anmeldung für diese IP-Adresse 15 Minuten gesperrt', ['attempts' => self::MAX_ATTEMPTS_PER_IP]);
            }
            $attempts[$key] = $entry;
            $attempts[$ipKey] = $ipEntry;
            Storage::write('auth/login-attempts.json', self::pruneAttempts($attempts));
            return 'Benutzername oder Passwort falsch.';
        }
        unset($attempts[$key]);
        Storage::write('auth/login-attempts.json', self::pruneAttempts($attempts));

        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            self::updateUser($u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        self::login($u, $remember);
        Log::info('auth', 'login', 'Angemeldet: ' . $u['username'], ['remember' => $remember]);
        return null;
    }

    public static function login(array $u, bool $remember): void
    {
        session_regenerate_id(true);
        unset($_SESSION['qr']);
        $_SESSION['uid'] = $u['id'];
        $_SESSION['login_at'] = time();
        self::$user = $u;
        self::$loaded = true;
        if ($remember) {
            self::issueRememberToken($u['id']);
        }
    }

    public static function logout(): void
    {
        Log::info('auth', 'logout', 'Abgemeldet: ' . (self::user()['username'] ?? '?'));
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
            Log::info('auth', 'remember_rejected', $t ? 'Gerät-Anmeldung abgelaufen oder ungültig' : 'Unbekannte Gerät-Anmeldung');
            self::setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
            return null;
        }
        Log::debug('auth', 'remember_restored', 'Anmeldung über gemerktes Gerät', ['user_id' => $t['user_id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = $t['user_id'];
        $_SESSION['login_at'] = time();
        // Token bei jeder Nutzung austauschen: ein kopiertes Cookie wird so schnell wertlos.
        // Das alte bleibt kurz gültig, damit parallel laufende Anfragen nicht abgemeldet werden.
        if (empty($t['rotated'])) {
            Storage::locked('remember', static function () use ($selector) {
                $tokens = Storage::read('auth/remember.json', []);
                if (isset($tokens[$selector])) {
                    $tokens[$selector]['rotated'] = true;
                    $tokens[$selector]['expires'] = min((int) $tokens[$selector]['expires'], time() + self::REMEMBER_GRACE);
                    Storage::write('auth/remember.json', $tokens);
                }
            });
            self::issueRememberToken($t['user_id']);
        }
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
        return array_filter($attempts, static fn ($a) => ($a['until'] ?? 0) > time() || ($a['count'] ?? 0) > 0 || !empty($a['fails']));
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
