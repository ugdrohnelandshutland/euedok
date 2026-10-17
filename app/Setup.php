<?php
declare(strict_types=1);

namespace Euedok;

/** Einrichtungsassistent: prüft den Server und legt data/ mit den Grundwerten an. */
final class Setup
{
    /** @return array<int,array{label: string, ok: bool, hint: string}> */
    public static function requirements(): array
    {
        $dataOk = is_dir(EUEDOK_DATA) ? is_writable(EUEDOK_DATA) : is_writable(EUEDOK_ROOT);
        return [
            ['label' => 'PHP 8.1 oder neuer', 'ok' => PHP_VERSION_ID >= 80100, 'hint' => 'gefunden: ' . PHP_VERSION],
            ['label' => 'Erweiterung sodium (Verschlüsselung)', 'ok' => extension_loaded('sodium'), 'hint' => 'für SMTP-Passwort und Divera-Schlüssel'],
            ['label' => 'Erweiterung curl (Divera247)', 'ok' => extension_loaded('curl'), 'hint' => 'nur nötig, wenn Divera genutzt wird'],
            ['label' => 'Erweiterung mbstring', 'ok' => extension_loaded('mbstring'), 'hint' => 'Umlaute'],
            ['label' => 'Erweiterung gd (PDF-Grafik)', 'ok' => extension_loaded('gd'), 'hint' => 'für QR-Codes und Logo im PDF'],
            ['label' => 'Erweiterung zip (ZIP-Export)', 'ok' => class_exists(\ZipArchive::class), 'hint' => 'für die Sicherung aller Berichte'],
            ['label' => 'Ordner data/ beschreibbar', 'ok' => $dataOk, 'hint' => EUEDOK_DATA],
            ['label' => 'HTTPS', 'ok' => is_https() || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) || str_starts_with((string) ($_SERVER['HTTP_HOST'] ?? ''), 'localhost:'), 'hint' => 'im Betrieb Pflicht'],
        ];
    }

    public static function requirementsMet(): bool
    {
        foreach (self::requirements() as $r) {
            if (!$r['ok'] && $r['label'] !== 'Erweiterung curl (Divera247)') {
                return false;
            }
        }
        return true;
    }

    /** @return string|null Fehlermeldung */
    public static function install(array $in): ?string
    {
        if (Settings::isInstalled()) {
            return 'EÜDOK ist bereits eingerichtet.';
        }
        $name = trim((string) ($in['unit_name'] ?? ''));
        $username = strtolower(trim((string) ($in['username'] ?? '')));
        $display = trim((string) ($in['display_name'] ?? ''));
        $pw = (string) ($in['password'] ?? '');
        if ($name === '') {
            return 'Bitte den Namen der Einheit eintragen.';
        }
        if ($err = Auth::usernameError($username)) {
            return $err;
        }
        if ($display === '') {
            return 'Bitte einen Anzeigenamen eintragen.';
        }
        if (mb_strlen($pw) < 10) {
            return 'Das Passwort muss mindestens 10 Zeichen haben.';
        }
        if ($pw !== (string) ($in['password2'] ?? '')) {
            return 'Die Passwörter stimmen nicht überein.';
        }

        foreach (['settings', 'keys', 'cache', 'logs', 'branding', 'reports', 'auth', 'sessions', 'locks'] as $d) {
            Storage::ensureDir(Storage::path($d));
        }
        // Rückfallebene, falls data/ doch im Web-Root landet
        Storage::writeRaw('.htaccess', "Require all denied\nDeny from all\n");
        Crypto::ensureKey();

        $unit = Defaults::unit();
        $unit['name'] = $name;
        $unit['subtitle'] = trim((string) ($in['subtitle'] ?? ''));
        $unit['footer'] = ['left' => '', 'center' => '', 'right' => Footer::DEFAULT_RIGHT];

        $preset = ($in['preset'] ?? 'drone') === 'drone' ? Defaults::presetDrone() : [];
        Settings::save('unit', $unit);
        Settings::save('master-data', $preset['master-data'] ?? Defaults::masterData());
        Settings::save('custom-fields', $preset['custom-fields'] ?? Defaults::customFields());
        Settings::save('divera', $preset['divera'] ?? Defaults::divera());
        Settings::save('mail', Defaults::mail());
        Settings::save('qr-access', Defaults::qrAccess());

        // Erste Person des Zugangs: bei Startwerten die erste Dummy-Person nicht – Zugang bleibt ohne Zuordnung
        $user = [
            'id' => bin2hex(random_bytes(6)),
            'username' => $username,
            'display_name' => $display,
            'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'can_manage_settings' => true,
            'person_id' => null,
            'active' => true,
            'created_at' => now_iso(),
        ];
        Settings::save('users', ['users' => [$user]]);
        Settings::clearCache();
        Log::audit('setup', 'Einrichtung abgeschlossen (' . (($in['preset'] ?? 'drone') === 'drone' ? 'Startwerte UG-Drohne' : 'leer') . '), Zugang ' . $username . ' angelegt');
        Auth::login($user, true);
        return null;
    }
}
