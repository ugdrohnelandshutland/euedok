<?php
declare(strict_types=1);

namespace Euedok;

/*
 * Update über die öffentlichen GitHub-Releases – nur auf Klick in den Einstellungen, nie automatisch.
 *
 * Ablauf: Release abfragen → ZIP + Signatur laden → Signatur prüfen (Ed25519) → nach data/updates/staging entpacken
 * → Inhalt prüfen → Wartungsmodus → app/, public/, vendor/ per Umbenennen tauschen (alter Stand nach
 * data/updates/previous) → Selbsttest → bei Fehler automatisch zurück. data/ wird nie angefasst.
 *
 * Nur Pakete, die mit dem privaten Release-Schlüssel signiert sind, werden installiert – auch ein Angreifer mit
 * Admin-Zugang oder Zugriff auf das GitHub-Konto kann so keinen fremden Code einspielen.
 */
final class Updater
{
    public const REPO = 'ugdrohnelandshutland/euedok';
    /** Öffentlicher Ed25519-Schlüssel; der private liegt nur beim Release-Ersteller (tools/release-tool.php) */
    private const PUBLIC_KEY = 'CT8m2p5hC3fTZukM3rWMtinXlYdP6eUJETk9kn/W2oo=';
    private const DIRS = ['app', 'public', 'vendor'];
    private const MAX_BYTES = 64 * 1048576;
    private const MAINTENANCE_SECONDS = 600;

    /* ---------- Release abfragen (nur auf Klick) ---------- */

    /** @return array{version:string,tag:string,name:string,notes:string,published_at:string,html_url:string,zip_url:string,sig_url:string,newer:bool,checked_at:string} */
    public static function check(): array
    {
        [$status, $body] = self::http('https://api.github.com/repos/' . self::REPO . '/releases/latest', ['Accept: application/vnd.github+json']);
        if ($status === 404) {
            throw new \RuntimeException('Auf GitHub ist noch kein Release veröffentlicht.');
        }
        $rel = json_decode($body, true);
        if ($status !== 200 || !is_array($rel)) {
            throw new \RuntimeException('GitHub antwortet mit Fehler (HTTP ' . $status . ').');
        }
        $tag = (string) ($rel['tag_name'] ?? '');
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m)) {
            throw new \RuntimeException('Release hat keine gültige Versionsnummer („' . $tag . '“).');
        }
        $version = $m[1];
        $zip = $sig = '';
        foreach ((array) ($rel['assets'] ?? []) as $a) {
            $url = (string) ($a['browser_download_url'] ?? '');
            if (($a['name'] ?? '') === 'euedok-' . $version . '.zip') {
                $zip = $url;
            } elseif (($a['name'] ?? '') === 'euedok-' . $version . '.zip.sig') {
                $sig = $url;
            }
        }
        $result = [
            'version' => $version,
            'tag' => $tag,
            'name' => (string) ($rel['name'] ?? $tag),
            'notes' => mb_substr((string) ($rel['body'] ?? ''), 0, 8000),
            'published_at' => (string) ($rel['published_at'] ?? ''),
            'html_url' => (string) ($rel['html_url'] ?? ''),
            'zip_url' => self::validAssetUrl($zip) ? $zip : '',
            'sig_url' => self::validAssetUrl($sig) ? $sig : '',
            'newer' => version_compare($version, EUEDOK_VERSION, '>'),
            'checked_at' => now_iso(),
        ];
        Log::info('update', 'checked', 'Nach Updates gesucht', ['installed' => EUEDOK_VERSION, 'latest' => $version, 'assets' => $zip !== '' && $sig !== '']);
        return $result;
    }

    private static function validAssetUrl(string $url): bool
    {
        return str_starts_with($url, 'https://github.com/' . self::REPO . '/releases/download/');
    }

    /* ---------- Installieren ---------- */

    /** Probleme, die ein Update verhindern würden (leer = alles in Ordnung) */
    public static function preflight(): array
    {
        $p = [];
        foreach (['curl' => 'curl', 'zip' => 'ZipArchive', 'sodium' => 'sodium_crypto_sign_verify_detached'] as $ext => $probe) {
            if (!function_exists($probe) && !class_exists($probe) && !extension_loaded($ext)) {
                $p[] = 'PHP-Erweiterung „' . $ext . '“ fehlt.';
            }
        }
        if (!is_writable(EUEDOK_ROOT)) {
            $p[] = 'Der Programmordner ist für PHP nicht beschreibbar (' . EUEDOK_ROOT . ').';
        }
        foreach (self::DIRS as $d) {
            if (!is_dir(EUEDOK_ROOT . '/' . $d) || !is_writable(EUEDOK_ROOT . '/' . $d)) {
                $p[] = 'Ordner ' . $d . '/ fehlt oder ist nicht beschreibbar.';
            }
        }
        return $p;
    }

    /** Installiert das geprüfte Release (aus check()). @return string Erfolgsmeldung */
    public static function install(array $release, array $user): string
    {
        if (!self::validAssetUrl((string) ($release['zip_url'] ?? '')) || !self::validAssetUrl((string) ($release['sig_url'] ?? ''))) {
            throw new \RuntimeException('Am Release fehlen die Dateien euedok-' . ($release['version'] ?? '?') . '.zip und .zip.sig.');
        }
        $dir = self::workDir();
        Storage::ensureDir($dir);
        $zip = $dir . '/download.zip';
        [$status] = self::http($release['zip_url'], [], $zip);
        if ($status !== 200) {
            throw new \RuntimeException('Download des Updates fehlgeschlagen (HTTP ' . $status . ').');
        }
        [$status, $sig] = self::http($release['sig_url'], []);
        if ($status !== 200) {
            throw new \RuntimeException('Download der Signatur fehlgeschlagen (HTTP ' . $status . ').');
        }
        try {
            return self::installFromFile($zip, $sig, (string) $release['version'], $user);
        } finally {
            @unlink($zip);
        }
    }

    /** Kern der Installation – auch für Tests mit einer lokalen Datei nutzbar. */
    public static function installFromFile(string $zipFile, string $signature, string $version, array $user): string
    {
        @set_time_limit(600);
        ignore_user_abort(true);
        return Storage::locked('update', static function () use ($zipFile, $signature, $version, $user): string {
            if ($p = self::preflight()) {
                throw new \RuntimeException(implode(' ', $p));
            }
            $from = EUEDOK_VERSION;
            Log::info('update', 'start', 'Update gestartet', ['from' => $from, 'to' => $version]);

            // 1) Signatur
            $sig = base64_decode(trim($signature), true);
            $data = (string) file_get_contents($zipFile);
            if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
                || !sodium_crypto_sign_verify_detached($sig, $data, (string) base64_decode(self::PUBLIC_KEY))) {
                Log::error('update', 'bad_signature', 'Update-Paket mit ungültiger Signatur abgelehnt', ['to' => $version, 'bytes' => strlen($data)]);
                throw new \RuntimeException('Die Signatur des Update-Pakets ist ungültig – Update abgelehnt.');
            }
            unset($data);

            // 2) Entpacken und prüfen
            $staging = self::workDir() . '/staging';
            self::removeTree($staging);
            self::extract($zipFile, $staging);
            self::fixPermissions($staging);
            self::validate($staging, $version);

            // 3) Tauschen (Wartungsmodus nur für die Sekunden des Tauschs)
            self::preloadClasses();
            Storage::write('maintenance.json', ['until' => time() + self::MAINTENANCE_SECONDS, 'version' => $version]);
            try {
                self::swapIn($staging, $from);
            } finally {
                Storage::write('maintenance.json', []);
                self::resetOpcache();
            }
            self::removeTree($staging);

            // 4) Selbsttest – bei Fehler automatisch zurück
            $test = self::selfTest();
            if ($test === false) {
                self::swapPrevious($version);
                self::removeTree(self::workDir() . '/previous'); // fehlerhafte Version nicht als „vorherige“ anbieten
                Log::error('update', 'selftest_failed', 'Selbsttest nach Update fehlgeschlagen – alte Version wiederhergestellt', ['from' => $from, 'to' => $version]);
                throw new \RuntimeException('Nach dem Update startete EÜDOK nicht fehlerfrei. Version ' . $from . ' wurde automatisch wiederhergestellt.');
            }

            self::record('update', $from, $version, $user);
            return 'Update auf ' . $version . ' installiert (vorher ' . $from . ').' . ($test === null ? ' Hinweis: Der Selbsttest konnte die Seite nicht aufrufen – bitte kurz prüfen.' : '');
        });
    }

    /** Vorherige Version wiederherstellen (tauscht aktuellen und vorherigen Stand). */
    public static function rollback(array $user): string
    {
        return Storage::locked('update', static function () use ($user): string {
            $prev = self::previous();
            if (!$prev) {
                throw new \RuntimeException('Es gibt keine vorherige Version.');
            }
            self::preloadClasses();
            Storage::write('maintenance.json', ['until' => time() + self::MAINTENANCE_SECONDS, 'version' => $prev['version']]);
            try {
                self::swapPrevious(EUEDOK_VERSION);
            } finally {
                Storage::write('maintenance.json', []);
                self::resetOpcache();
            }
            self::record('rollback', EUEDOK_VERSION, $prev['version'], $user);
            return 'Version ' . $prev['version'] . ' wiederhergestellt (vorher ' . EUEDOK_VERSION . ').';
        });
    }

    /** @return array{version:string,replaced_at:string}|null */
    public static function previous(): ?array
    {
        $info = self::workDir() . '/previous/version.json';
        if (!is_file($info)) {
            return null;
        }
        foreach (self::DIRS as $d) {
            if (!is_dir(self::workDir() . '/previous/' . $d)) {
                return null;
            }
        }
        $v = json_decode((string) file_get_contents($info), true);
        return is_array($v) && isset($v['version']) ? $v : null;
    }

    /** @return list<array> neueste zuerst */
    public static function history(): array
    {
        return array_reverse((array) Storage::read('updates/history.json', []));
    }

    /** Wartungsmodus aktiv? (in bootstrap.php vor allem anderen geprüft) */
    public static function maintenanceActive(): bool
    {
        $m = Storage::read('maintenance.json', []);
        return is_array($m) && (int) ($m['until'] ?? 0) > time();
    }

    /* ---------- intern ---------- */

    private static function workDir(): string
    {
        return Storage::path('updates');
    }

    /** @return array{0:int,1:string} HTTP-Status und Inhalt (leer, wenn in Datei gespeichert) */
    private static function http(string $url, array $headers, ?string $saveTo = null): array
    {
        $ch = curl_init($url);
        $fp = null;
        $opts = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $saveTo ? 300 : 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'EUEDOK/' . EUEDOK_VERSION,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($c, $dlTotal, $dlNow) => $dlNow > self::MAX_BYTES ? 1 : 0,
        ];
        if ($saveTo) {
            $fp = fopen($saveTo, 'wb');
            if ($fp === false) {
                throw new \RuntimeException('Download-Datei nicht anlegbar.');
            }
            $opts[CURLOPT_FILE] = $fp;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fp) {
            fclose($fp);
        }
        if ($body === false) {
            Log::warning('update', 'http_failed', 'GitHub nicht erreichbar', ['url' => $url, 'error' => $err]);
            throw new \RuntimeException('GitHub nicht erreichbar (' . $err . ').');
        }
        return [$status, is_string($body) ? $body : ''];
    }

    /** Entpackt nur app/, public/, vendor/ – mit eigener Pfadprüfung (kein „..“, keine absoluten Pfade). */
    private static function extract(string $zipFile, string $target): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Update-Paket ist kein gültiges ZIP.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                $parts = explode('/', $name);
                if ($name === '' || $name[0] === '/' || str_contains($name, ':') || in_array('..', $parts, true) || !in_array($parts[0], self::DIRS, true)) {
                    continue;
                }
                $dest = $target . '/' . $name;
                if (str_ends_with($name, '/')) {
                    Storage::ensureDir($dest);
                    continue;
                }
                Storage::ensureDir(dirname($dest));
                $in = $zip->getStream($zip->getNameIndex($i));
                $out = fopen($dest, 'wb');
                if ($in === false || $out === false || stream_copy_to_stream($in, $out) === false) {
                    throw new \RuntimeException('Entpacken fehlgeschlagen: ' . $name);
                }
                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Rechte wie beim FTP-Upload: Ordner 0755, Dateien 0644. Ohne das hätten die Ordner die 0750 aus
     * Storage::ensureDir (gedacht für data/) – der Webserver läuft auf Plesk als anderer Benutzer → HTTP 403.
     */
    private static function fixPermissions(string $staging): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            @chmod($f->getPathname(), $f->isDir() ? 0755 : 0644);
        }
    }

    private static function validate(string $staging, string $version): void
    {
        foreach (self::DIRS as $d) {
            if (!is_dir($staging . '/' . $d)) {
                throw new \RuntimeException('Update-Paket unvollständig: ' . $d . '/ fehlt.');
            }
        }
        $boot = (string) @file_get_contents($staging . '/app/bootstrap.php');
        if (!preg_match("/EUEDOK_VERSION = '([^']+)'/", $boot, $m) || $m[1] !== $version) {
            throw new \RuntimeException('Update-Paket enthält nicht Version ' . $version . '.');
        }
        foreach (['public/index.php', 'vendor/autoload.php'] as $f) {
            if (!is_file($staging . '/' . $f)) {
                throw new \RuntimeException('Update-Paket unvollständig: ' . $f . ' fehlt.');
            }
        }
        // Mindest-PHP-Version aus Composer übernehmen
        $platform = (string) @file_get_contents($staging . '/vendor/composer/platform_check.php');
        if (preg_match('/PHP_VERSION_ID >= (\d+)/', $platform, $pm) && PHP_VERSION_ID < (int) $pm[1]) {
            throw new \RuntimeException('Das Update braucht eine neuere PHP-Version (installiert: ' . PHP_VERSION . ').');
        }
    }

    /** Neuen Stand einsetzen, alten nach previous/ – bei Fehler alles zurück. */
    private static function swapIn(string $staging, string $from): void
    {
        $prev = self::workDir() . '/previous';
        self::removeTree($prev);
        Storage::ensureDir($prev);
        $done = [];
        try {
            foreach (self::DIRS as $d) {
                // oberster Ordner bekommt exakt die Rechte des bisherigen (z. B. public/ als Dokumentstamm)
                @chmod($staging . '/' . $d, fileperms(EUEDOK_ROOT . '/' . $d) & 0777);
                self::move(EUEDOK_ROOT . '/' . $d, $prev . '/' . $d);
                $done[] = [$d, 'out'];
                self::move($staging . '/' . $d, EUEDOK_ROOT . '/' . $d);
                $done[] = [$d, 'in'];
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($done) as [$d, $step]) {
                $step === 'in' ? @rename(EUEDOK_ROOT . '/' . $d, $staging . '/' . $d) : @rename($prev . '/' . $d, EUEDOK_ROOT . '/' . $d);
            }
            Log::error('update', 'swap_failed', $e->getMessage());
            throw new \RuntimeException('Austausch der Programmdateien fehlgeschlagen – alter Stand bleibt aktiv. ' . $e->getMessage());
        }
        file_put_contents($prev . '/version.json', json_encode(['version' => $from, 'replaced_at' => now_iso()]));
    }

    /** Aktuellen und vorherigen Stand tauschen (für Zurück und für den Selbsttest-Fehlerfall). */
    /** @param string $activeVersion Version des Stands, der gerade aktiv ist (wird zur „vorherigen“) */
    private static function swapPrevious(string $activeVersion): void
    {
        $prevDir = self::workDir() . '/previous';
        $info = self::previous();
        if (!$info) {
            throw new \RuntimeException('Es gibt keine vorherige Version.');
        }
        $tmp = self::workDir() . '/swap';
        self::removeTree($tmp);
        Storage::ensureDir($tmp);
        $done = [];
        try {
            foreach (self::DIRS as $d) {
                self::move(EUEDOK_ROOT . '/' . $d, $tmp . '/' . $d);
                $done[] = [$d, 'out'];
                self::move($prevDir . '/' . $d, EUEDOK_ROOT . '/' . $d);
                $done[] = [$d, 'in'];
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($done) as [$d, $step]) {
                $step === 'in' ? @rename(EUEDOK_ROOT . '/' . $d, $prevDir . '/' . $d) : @rename($tmp . '/' . $d, EUEDOK_ROOT . '/' . $d);
            }
            throw new \RuntimeException('Wiederherstellen fehlgeschlagen – aktueller Stand bleibt aktiv. ' . $e->getMessage());
        }
        // Was eben aktiv war, wird zur neuen „vorherigen“ Version
        foreach (self::DIRS as $d) {
            self::move($tmp . '/' . $d, $prevDir . '/' . $d);
        }
        file_put_contents($prevDir . '/version.json', json_encode(['version' => $activeVersion, 'replaced_at' => now_iso()]));
        self::removeTree($tmp);
    }

    private static function move(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new \RuntimeException('Umbenennen nicht möglich: ' . basename($from));
        }
    }

    /** Klassen laden, die nach dem Tausch in dieser Anfrage noch gebraucht werden (sonst kämen sie aus dem neuen Stand). */
    private static function preloadClasses(): void
    {
        foreach ([Log::class, Mailer::class, Settings::class, Storage::class, Auth::class, \PHPMailer\PHPMailer\PHPMailer::class, \PHPMailer\PHPMailer\SMTP::class, \PHPMailer\PHPMailer\Exception::class] as $c) {
            class_exists($c);
        }
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /** @return bool|null true = ok, false = Fehler (zurückrollen), null = nicht prüfbar (Server erreicht sich selbst nicht) */
    private static function selfTest(): ?bool
    {
        $url = absolute_url('index.php?login=1');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'EUEDOK-Selbsttest']);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status === 0) {
            Log::warning('update', 'selftest_unreachable', 'Selbsttest konnte die Seite nicht abrufen', ['url' => $url, 'error' => $err]);
            return null;
        }
        $ok = $status === 200 && str_contains((string) $body, '<title>');
        Log::info('update', 'selftest', $ok ? 'Selbsttest erfolgreich' : 'Selbsttest fehlgeschlagen', ['http' => $status, 'url' => $url]
            + ($ok ? [] : ['body' => mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $body))), 0, 300)]));
        return $ok;
    }

    private static function record(string $kind, string $from, string $to, array $user): void
    {
        Storage::locked('update-history', static function () use ($kind, $from, $to, $user): void {
            $h = (array) Storage::read('updates/history.json', []);
            $h[] = ['at' => now_iso(), 'kind' => $kind, 'from' => $from, 'to' => $to, 'by' => $user['username'] ?? ''];
            Storage::write('updates/history.json', array_slice($h, -50));
        });
        $text = $kind === 'update' ? 'Update installiert: ' . $from . ' → ' . $to : 'Vorherige Version wiederhergestellt: ' . $from . ' → ' . $to;
        Log::audit('update', $text);
        Log::info('update', $kind, $text, ['from' => $from, 'to' => $to]);
        if (Mailer::enabled()) {
            Mailer::sendText(
                Settings::mail()['recipients'],
                '[EÜDOK] ' . $text,
                $text . "\nDurch: " . ($user['display_name'] ?? $user['username'] ?? '?') . ' am ' . date('d.m.Y H:i') . ".\n\n"
                . 'Falls etwas nicht stimmt: Einstellungen → Update → „Vorherige Version wiederherstellen“.'
            );
        }
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
