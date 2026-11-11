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
    /** Vergleichs-Hash für unbekannte Namen – mit denselben Kosten wie echte Hashes, sonst verrät die Antwortzeit, ob ein Name existiert */
    private static function dummyHash(): string
    {
        static $h;
        return $h ??= password_hash(random_bytes(12), PASSWORD_DEFAULT);
    }

    /** Benutzername: Kleinbuchstaben, Ziffern, . _ - @ (also auch E-Mail-Adressen), 3–100 Zeichen */
    public const USERNAME_MAX = 100;

    /** @return string|null Fehlermeldung oder null, wenn gültig (erwartet bereits kleingeschrieben) */
    public static function usernameError(string $username): ?string
    {
        if (mb_strlen($username) > self::USERNAME_MAX) {
            return 'Benutzername „' . mb_substr($username, 0, 40) . '…“ ist zu lang (höchstens ' . self::USERNAME_MAX . ' Zeichen).';
        }
        if (!preg_match('/^[a-z0-9._@-]{3,' . self::USERNAME_MAX . '}$/', $username)) {
            return 'Benutzername „' . $username . '“ ungültig (3–' . self::USERNAME_MAX . ' Zeichen: a–z, 0–9, . _ - @ – z. B. auch eine E-Mail-Adresse).';
        }
        return null;
    }

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
            // Grenze für ältere Sitzungen: Unix-Zeit (Passwort, 2FA); ältere Bestände nur mit password_changed_at
            $changed = isset($u['sessions_invalid_before']) ? (int) $u['sessions_invalid_before']
                : (!empty($u['password_changed_at']) ? (int) strtotime((string) $u['password_changed_at']) : 0);
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

    /** @param bool $passwordPage true nur für „Mein Zugang“ – dort darf ein Pflichtwechsel erledigt werden */
    public static function requireLogin(bool $passwordPage = false): array
    {
        if (!Settings::isInstalled()) {
            redirect('setup');
        }
        $u = self::user();
        if ($u === null) {
            if (is_api_request()) {
                json_out(['ok' => false, 'error' => 'Nicht angemeldet.'], 401);
            }
            $target = $_SERVER['REQUEST_URI'] ?? '';
            $_SESSION['after_login'] = is_string($target) ? $target : '';
            redirect('login');
        }
        if (!$passwordPage && ($msg = self::pendingAccountAction()) !== null) {
            if (is_api_request()) {
                json_out(['ok' => false, 'error' => $msg], 403);
            }
            redirect('account');
        }
        return $u;
    }

    public const MUST_CHANGE_MSG = 'Bitte zuerst ein eigenes Passwort festlegen (Mein Zugang).';

    /** Admin hat „Passwort beim nächsten Login ändern“ gesetzt */
    public static function mustChangePassword(): bool
    {
        return !empty(self::user()['must_change_password']);
    }

    /** Eigenes Passwort ändern. Gibt eine Fehlermeldung zurück oder null bei Erfolg. */
    public static function changeOwnPassword(string $current, string $new, string $repeat): ?string
    {
        $u = self::user();
        if ($u === null) {
            return 'Nicht angemeldet.';
        }
        // Raten des aktuellen Passworts in einer fremden, offenen Sitzung begrenzen
        self::guardAccountFails();
        if (!self::confirmPassword($current)) {
            $_SESSION['account_fails'] = (int) ($_SESSION['account_fails'] ?? 0) + 1;
            return 'Aktuelles Passwort ist falsch.';
        }
        if (mb_strlen($new) < 10) {
            return 'Das neue Passwort ist zu kurz (mind. 10 Zeichen).';
        }
        if ($new !== $repeat) {
            return 'Die beiden neuen Passwörter stimmen nicht überein.';
        }
        if ($new === $current) {
            return 'Das neue Passwort muss sich vom bisherigen unterscheiden.';
        }
        unset($_SESSION['account_fails']);
        self::updateUser($u['id'], [
            'password_hash' => password_hash($new, PASSWORD_DEFAULT),
            'password_changed_at' => now_iso(),
            'sessions_invalid_before' => time(),
            'must_change_password' => false,
        ]);
        // gemerkte Geräte abmelden; andere Sitzungen enden über password_changed_at, die eigene bleibt
        self::revokeRememberTokens($u['id']);
        $_SESSION['login_at'] = time();
        session_regenerate_id(true); // Sitzungsdaten (CSRF, login_at) bleiben erhalten
        self::$user = array_merge($u, ['must_change_password' => false]);
        Log::audit('auth', 'Eigenes Passwort geändert: ' . $u['username']);
        return null;
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
        if ($msg = self::countAttempt($username)) {
            return $msg;
        }

        $u = self::findUser(['username' => strtolower($username)]);
        $valid = $u && !empty($u['active']) && !empty($u['password_hash']);
        // immer eine Passwortprüfung rechnen, damit die Antwortzeit keine Benutzernamen verrät
        $verified = password_verify($password, $valid ? $u['password_hash'] : self::dummyHash());
        if (!($valid && $verified)) {
            Log::warning('auth', 'login_failed', 'Anmeldung fehlgeschlagen', ['username' => $username, 'known_user' => (bool) $u]);
            return 'Benutzername oder Passwort falsch.';
        }
        if (!self::twoFactorActive($u)) {
            self::clearAttempt($username); // bei 2FA erst nach gültigem Code, sonst setzt jedes richtige Passwort die Sperre zurück
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            self::updateUser($u['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        if (self::twoFactorActive($u)) {
            // erst nach dem Code voll angemeldet; „angemeldet bleiben“ wird bis dahin nur vorgemerkt
            session_regenerate_id(true);
            unset($_SESSION['uid'], $_SESSION['login_at'], $_SESSION['qr']);
            $_SESSION['tfa_pending'] = ['uid' => $u['id'], 'remember' => $remember, 'at' => time()];
            Log::info('auth', 'login_password_ok', 'Passwort korrekt, Code ausstehend: ' . $u['username']);
            return null;
        }
        self::login($u, $remember);
        Log::info('auth', 'login', 'Angemeldet: ' . $u['username'], ['remember' => $remember]);
        return null;
    }

    /* ---------- Zwei-Faktor (TOTP) ---------- */

    private const TFA_PENDING_SECONDS = 300;

    public static function twoFactorActive(array $u): bool
    {
        return !empty($u['tfa_secret']);
    }

    /** Pflicht je Zugang oder für alle Admins (Einstellungen → Zugänge) */
    public static function twoFactorRequired(array $u): bool
    {
        return !empty($u['tfa_required'])
            || (!empty($u['can_manage_settings']) && !empty(Settings::get('users')['admins_require_tfa']));
    }

    /** Was der angemeldete Benutzer zuerst unter „Mein Zugang“ erledigen muss – oder null */
    public static function pendingAccountAction(): ?string
    {
        $u = self::user();
        if ($u === null) {
            return null;
        }
        if (!empty($u['must_change_password'])) {
            return self::MUST_CHANGE_MSG;
        }
        if (self::twoFactorRequired($u) && !self::twoFactorActive($u)) {
            return 'Bitte zuerst die Zwei-Faktor-Anmeldung einrichten (Mein Zugang).';
        }
        return null;
    }

    /** Benutzer nach korrektem Passwort, der noch den Code eingeben muss */
    public static function twoFactorPending(): ?array
    {
        $p = $_SESSION['tfa_pending'] ?? null;
        if (!is_array($p)) {
            return null;
        }
        $u = ($p['at'] ?? 0) > time() - self::TFA_PENDING_SECONDS ? self::findUser(['id' => (string) $p['uid']]) : null;
        if (!$u || empty($u['active']) || !self::twoFactorActive($u)) {
            unset($_SESSION['tfa_pending']);
            return null;
        }
        return $u;
    }

    /** Zweiter Anmeldeschritt. Fehlermeldung oder null bei Erfolg. */
    public static function completeTwoFactor(string $code): ?string
    {
        $u = self::twoFactorPending();
        if ($u === null) {
            return 'Anmeldung abgelaufen. Bitte erneut anmelden.';
        }
        if ($msg = self::countAttempt($u['username'])) {
            unset($_SESSION['tfa_pending']);
            return $msg;
        }
        $secret = Crypto::decrypt($u['tfa_secret']);
        if ($secret === null) {
            Log::error('auth', 'tfa_secret_unreadable', 'Zwei-Faktor-Schlüssel nicht lesbar', ['username' => $u['username']]);
            return 'Zwei-Faktor-Schlüssel nicht lesbar. Bitte einen Admin, die Zwei-Faktor-Anmeldung zurückzusetzen.';
        }
        // Prüfen und Merken des Zeitschritts in einer Sperre – sonst gehen zwei parallele Anfragen mit demselben Code durch
        $step = Storage::locked('users', static function () use ($u, $secret, $code): ?int {
            $fresh = self::freshUser($u['id']);
            $step = self::matchStep($secret, $code, (int) ($fresh['tfa_last_step'] ?? 0));
            if ($step !== null) {
                self::writeUser($u['id'], ['tfa_last_step' => $step]);
            }
            return $step;
        });
        if ($step === null) {
            Log::warning('auth', 'tfa_failed', 'Falscher Zwei-Faktor-Code', ['username' => $u['username']]);
            return 'Code falsch oder bereits verwendet.';
        }
        self::clearAttempt($u['username'], 2); // Passwort- und Code-Schritt
        $remember = !empty($_SESSION['tfa_pending']['remember']);
        unset($_SESSION['tfa_pending']);
        self::login($u, $remember);
        Log::info('auth', 'login', 'Angemeldet mit Zwei-Faktor: ' . $u['username'], ['remember' => $remember]);
        return null;
    }

    /** Geheimnis für die laufende Einrichtung (bleibt bis zum Aktivieren in der Sitzung) */
    public static function twoFactorSetupSecret(): string
    {
        return $_SESSION['tfa_setup'] ??= self::totp(null)->getSecret();
    }

    /** otpauth://-Adresse für den QR-Code der Authenticator-App */
    public static function twoFactorUri(array $u, string $secret): string
    {
        $unit = Settings::unit();
        $issuer = trim((string) ($unit['app_name'] ?? '')) ?: 'EÜDOK' . (($unit['name'] ?? '') !== '' ? ' ' . $unit['name'] : '');
        $t = self::totp($secret);
        $t->setIssuer(str_replace(':', '', $issuer));
        $t->setLabel(str_replace(':', '', $u['username']));
        return $t->getProvisioningUri();
    }

    /** Einrichtung abschließen: erster gültiger Code schaltet ein. Fehlermeldung oder null. */
    public static function enableTwoFactor(string $code, string $password): ?string
    {
        $u = self::user();
        if ($u === null || !empty($u['must_change_password'])) {
            return 'Bitte zuerst ein eigenes Passwort festlegen.';
        }
        // ohne Passwort könnte eine übernommene Sitzung eine fremde App eintragen und den Inhaber aussperren
        if (!self::confirmPasswordLimited($password)) {
            return 'Aktuelles Passwort ist falsch.';
        }
        $secret = self::twoFactorSetupSecret();
        $step = self::matchStep($secret, $code, 0);
        if ($step === null) {
            $_SESSION['account_fails'] = (int) ($_SESSION['account_fails'] ?? 0) + 1;
            return 'Code falsch. Bitte den aktuellen Code aus der App eingeben (Uhrzeit am Handy prüfen).';
        }
        unset($_SESSION['account_fails'], $_SESSION['tfa_setup']);
        $changes = ['tfa_secret' => Crypto::encrypt($secret), 'tfa_enabled_at' => now_iso(), 'tfa_last_step' => $step, 'sessions_invalid_before' => time()];
        self::updateUser($u['id'], $changes);
        // gemerkte Geräte und andere laufende Sitzungen ohne zweiten Faktor abmelden, die eigene bleibt
        self::revokeRememberTokens($u['id']);
        $_SESSION['login_at'] = time();
        self::$user = array_merge($u, $changes);
        Log::audit('auth', 'Zwei-Faktor eingerichtet: ' . $u['username']);
        return null;
    }

    /** Eigene 2FA ausschalten (nur ohne Pflicht, mit aktuellem Code). Fehlermeldung oder null. */
    public static function disableTwoFactor(string $code): ?string
    {
        $u = self::user();
        if ($u === null || !self::twoFactorActive($u)) {
            return 'Zwei-Faktor ist nicht eingerichtet.';
        }
        if (self::twoFactorRequired($u)) {
            return 'Die Zwei-Faktor-Anmeldung ist für deinen Zugang vorgeschrieben.';
        }
        self::guardAccountFails();
        $secret = Crypto::decrypt($u['tfa_secret']);
        if ($secret === null || self::matchStep($secret, $code, (int) ($u['tfa_last_step'] ?? 0)) === null) {
            $_SESSION['account_fails'] = (int) ($_SESSION['account_fails'] ?? 0) + 1;
            return 'Code falsch oder bereits verwendet.';
        }
        unset($_SESSION['account_fails']);
        self::updateUser($u['id'], self::TFA_OFF);
        self::$user = array_merge($u, self::TFA_OFF);
        Log::audit('auth', 'Zwei-Faktor ausgeschaltet: ' . $u['username']);
        return null;
    }

    /** Admin: 2FA eines Zugangs zurücksetzen (z. B. Handy verloren) */
    public static function resetTwoFactor(string $id): ?array
    {
        $u = self::findUser(['id' => $id]);
        if ($u === null) {
            return null;
        }
        // z. B. Handy verloren: auch alle laufenden Sitzungen dieses Zugangs beenden
        self::updateUser($id, self::TFA_OFF + ['sessions_invalid_before' => time()]);
        self::revokeRememberTokens($id);
        if ($id === (self::user()['id'] ?? null)) {
            $_SESSION['login_at'] = time();
        }
        Log::audit('users', 'Zwei-Faktor zurückgesetzt: ' . $u['username']);
        return $u;
    }

    private const TFA_OFF = ['tfa_secret' => null, 'tfa_enabled_at' => null, 'tfa_last_step' => 0];

    private static function totp(?string $secret): \OTPHP\TOTP
    {
        $clock = new class implements \Psr\Clock\ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
        return $secret === null ? \OTPHP\TOTP::generate($clock, 20) : \OTPHP\TOTP::createFromSecret($secret, $clock);
    }

    /** Zeitschritt des passenden Codes (±1 Schritt = ±30 s) oder null; benutzte Schritte gelten nicht erneut */
    private static function matchStep(string $secret, string $code, int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', (string) $code)) {
            return null;
        }
        $totp = self::totp($secret);
        $now = intdiv(time(), $totp->getPeriod());
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if ($step > $lastStep && hash_equals($totp->at($step * $totp->getPeriod()), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** Raten in einer fremden, offenen Sitzung begrenzen (Passwort/Code unter „Mein Zugang“) */
    private static function guardAccountFails(): void
    {
        if ((int) ($_SESSION['account_fails'] ?? 0) >= 5) {
            Log::warning('auth', 'account_locked', 'Zu viele Fehlversuche unter „Mein Zugang“ – abgemeldet', ['username' => self::user()['username'] ?? null]);
            self::logout();
            redirect('login');
        }
    }

    /** Fehlversuch VOR der Prüfung zählen. Gibt die Sperrmeldung zurück oder null. */
    private static function countAttempt(string $username): ?string
    {
        $key = hash('sha256', strtolower($username) . '|' . client_ip());
        // zusätzlich je IP-Adresse über alle Benutzernamen (gegen Durchprobieren vieler Namen)
        $ipKey = 'ip|' . hash('sha256', client_ip());

        // Versuch VOR der Passwortprüfung unter Sperre zählen – parallele Anfragen können den Zähler
        // so nicht überholen. Bei Erfolg wird der Versuch wieder abgezogen.
        $blocked = Storage::locked('login-attempts', static function () use ($key, $ipKey, $username): int {
            $attempts = Storage::read('auth/login-attempts.json', []);
            $entry = ($attempts[$key] ?? []) + ['count' => 0, 'until' => 0, 'at' => 0];
            if ((int) $entry['at'] < time() - self::LOCK_SECONDS) {
                $entry['count'] = 0; // Fehlversuche älter als 15 Minuten zählen nicht (alte Einträge ohne „at“ ebenso)
            }
            $ipEntry = $attempts[$ipKey] ?? ['fails' => [], 'until' => 0];
            $until = max((int) $entry['until'], (int) ($ipEntry['until'] ?? 0));
            if ($until > time()) {
                return $until;
            }
            $entry['count']++;
            $entry['at'] = time();
            if ($entry['count'] >= self::MAX_ATTEMPTS + 1) {
                $entry = ['count' => 0, 'until' => time() + self::LOCK_SECONDS, 'at' => time()];
                Log::warning('auth', 'login_locked', 'Anmeldung 15 Minuten gesperrt', ['username' => $username]);
            }
            $fails = array_values(array_filter((array) ($ipEntry['fails'] ?? []), static fn ($t) => $t > time() - self::LOCK_SECONDS));
            $fails[] = time();
            $ipEntry = ['fails' => $fails, 'until' => (int) ($ipEntry['until'] ?? 0)];
            if (count($fails) >= self::MAX_ATTEMPTS_PER_IP + 1) {
                $ipEntry = ['fails' => [], 'until' => time() + self::LOCK_SECONDS];
                Log::warning('auth', 'login_ip_locked', 'Anmeldung für diese IP-Adresse 15 Minuten gesperrt', ['attempts' => self::MAX_ATTEMPTS_PER_IP]);
            }
            $attempts[$key] = $entry;
            $attempts[$ipKey] = $ipEntry;
            Storage::write('auth/login-attempts.json', self::pruneAttempts($attempts));
            // ist mit diesem Versuch die Grenze überschritten, wird er bereits abgewiesen
            return max((int) $entry['until'], (int) $ipEntry['until']);
        });
        if ($blocked > time()) {
            Log::info('auth', 'login_blocked', 'Anmeldeversuch während Sperre', ['username' => $username]);
            $min = (int) ceil(($blocked - time()) / 60);
            return "Zu viele Fehlversuche. Bitte in {$min} Minuten erneut versuchen.";
        }
        return null;
    }

    /** Erfolg: gezählte Versuche zurücknehmen ($count: wie viele Schritte dieser Anmeldung gezählt wurden) */
    private static function clearAttempt(string $username, int $count = 1): void
    {
        $key = hash('sha256', strtolower($username) . '|' . client_ip());
        $ipKey = 'ip|' . hash('sha256', client_ip());
        Storage::locked('login-attempts', static function () use ($key, $ipKey, $count) {
            $attempts = Storage::read('auth/login-attempts.json', []);
            unset($attempts[$key]);
            for ($i = 0; $i < $count && !empty($attempts[$ipKey]['fails']); $i++) {
                array_pop($attempts[$ipKey]['fails']);
            }
            Storage::write('auth/login-attempts.json', self::pruneAttempts($attempts));
        });
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
        return array_filter($attempts, static fn ($a) => ($a['until'] ?? 0) > time()
            || (($a['count'] ?? 0) > 0 && ($a['at'] ?? 0) > time() - self::LOCK_SECONDS)
            || array_filter((array) ($a['fails'] ?? []), static fn ($t) => $t > time() - self::LOCK_SECONDS));
    }

    public static function users(): array
    {
        return Settings::get('users')['users'];
    }

    /** Passwort des angemeldeten Benutzers erneut bestätigen (vor folgenreichen Aktionen wie Updates) */
    /** Wie confirmPassword, aber mit Fehlversuchszähler der Sitzung (nach 5 Fehlern Abmeldung) */
    public static function confirmPasswordLimited(string $password): bool
    {
        self::guardAccountFails();
        if (!self::confirmPassword($password)) {
            $_SESSION['account_fails'] = (int) ($_SESSION['account_fails'] ?? 0) + 1;
            return false;
        }
        return true;
    }

    public static function confirmPassword(string $password): bool
    {
        $u = self::user();
        $rec = $u ? self::findUser(['id' => $u['id']]) : null;
        $ok = $rec && $password !== '' && password_verify($password, (string) $rec['password_hash']);
        if (!$ok) {
            Log::warning('auth', 'confirm_failed', 'Passwortbestätigung fehlgeschlagen', ['user' => $u['username'] ?? null]);
        }
        return $ok;
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

    /** Zugang ändern – unter Sperre mit frisch gelesener users.json, damit parallele Änderungen nicht verloren gehen */
    public static function updateUser(string $id, array $changes): void
    {
        Storage::locked('users', static fn () => self::writeUser($id, $changes));
    }

    /** Nur innerhalb von Storage::locked('users', …) aufrufen */
    private static function freshUser(string $id): ?array
    {
        Settings::clearCache();
        return self::findUser(['id' => $id]);
    }

    /** Nur innerhalb von Storage::locked('users', …) aufrufen */
    private static function writeUser(string $id, array $changes): void
    {
        Settings::clearCache();
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
