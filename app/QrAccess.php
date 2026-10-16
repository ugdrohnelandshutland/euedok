<?php
declare(strict_types=1);

namespace Euedok;

/**
 * QR-Schnellerfassung: kryptischer Link (nur als Hash gespeichert), optionale PIN,
 * technischer Dienst-Zugang je QR-Code mit genau einem Recht: „Bericht erstellen“.
 */
final class QrAccess
{
    private const DEVICE_COOKIE = 'euedok_qr_device';
    public const RATE_LIMIT_PER_HOUR = 5;
    public const PDF_HOURS = 24;

    public static function entries(): array
    {
        return Settings::get('qr-access')['entries'];
    }

    public static function find(string $id): ?array
    {
        foreach (self::entries() as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }
        return null;
    }

    public static function findByKey(string $key): ?array
    {
        if (!preg_match('/^[0-9A-Za-z-]{20,64}$/', $key)) {
            return null;
        }
        $hash = Crypto::hashToken($key);
        foreach (self::entries() as $e) {
            if (hash_equals((string) $e['key_hash'], $hash)) {
                return $e;
            }
        }
        return null;
    }

    private static function saveEntries(array $entries, string $audit): void
    {
        Settings::save('qr-access', ['entries' => array_values($entries)], $audit);
    }

    /** @return array{0: array, 1: string} Eintrag und Klartext-Schlüssel (nur jetzt sichtbar) */
    public static function create(string $label, bool $divera): array
    {
        $entries = self::entries();
        $slug = unique_id(slugify($label), array_column($entries, 'slug'));
        $key = Crypto::readableKey();
        $entry = [
            'id' => bin2hex(random_bytes(6)),
            'slug' => $slug,
            'label' => $label,
            'service_account' => 'qr-' . $slug,
            'key_hash' => Crypto::hashToken($key),
            'key_hint' => substr($key, 0, 4),
            'pin_enabled' => false,
            'pin_hash' => '',
            'pin_version' => 1,
            'pin_remember_days' => 30,
            'divera_enabled' => $divera,
            'active' => true,
            'created_at' => now_iso(),
            'renewed_at' => null,
        ];
        $entries[] = $entry;
        self::saveEntries($entries, 'QR-Zugang angelegt: ' . $label);
        return [$entry, $key];
    }

    public static function renew(string $id): ?string
    {
        $key = Crypto::readableKey();
        $entries = self::entries();
        $found = false;
        foreach ($entries as &$e) {
            if ($e['id'] === $id) {
                $e['key_hash'] = Crypto::hashToken($key);
                $e['key_hint'] = substr($key, 0, 4);
                $e['renewed_at'] = now_iso();
                $found = $e['label'];
            }
        }
        unset($e);
        if ($found === false) {
            return null;
        }
        self::saveEntries($entries, 'QR-Schlüssel erneuert (alter Link ungültig): ' . $found);
        // Angreifer hat den alten Link nicht mehr → Sperre je Zugang aufheben, damit Mitglieder sofort wieder rein können
        Storage::locked('qr-pin', static function () use ($id) {
            $attempts = Storage::read('auth/qr-pin-attempts.json', []);
            unset($attempts['entry|' . $id]);
            Storage::write('auth/qr-pin-attempts.json', $attempts);
        });
        return $key;
    }

    public static function update(string $id, array $changes, string $audit): void
    {
        $entries = self::entries();
        foreach ($entries as &$e) {
            if ($e['id'] === $id) {
                $e = array_merge($e, $changes);
            }
        }
        unset($e);
        self::saveEntries($entries, $audit);
    }

    public static function setPin(string $id, ?string $pin, int $rememberDays): void
    {
        $e = self::find($id);
        if (!$e) {
            return;
        }
        $changes = ['pin_remember_days' => max(0, $rememberDays)];
        if ($pin === null) {
            $changes += ['pin_enabled' => false, 'pin_hash' => ''];
            $text = 'PIN ausgeschaltet';
        } else {
            // neue PIN → gemerkte Geräte werden ungültig
            $changes += ['pin_enabled' => true, 'pin_hash' => password_hash($pin, PASSWORD_DEFAULT), 'pin_version' => (int) $e['pin_version'] + 1];
            $text = 'PIN gesetzt/geändert';
        }
        self::update($id, $changes, $text . ': ' . $e['label']);
    }

    public static function delete(string $id): void
    {
        $e = self::find($id);
        $entries = array_filter(self::entries(), static fn ($x) => $x['id'] !== $id);
        self::saveEntries($entries, 'QR-Zugang gelöscht: ' . ($e['label'] ?? $id));
    }

    /* ---------- Gerät & PIN ---------- */

    public static function deviceId(): string
    {
        $c = $_COOKIE[self::DEVICE_COOKIE] ?? '';
        if (!is_string($c) || !preg_match('/^[a-f0-9]{48}$/', $c)) {
            $c = bin2hex(random_bytes(24));
            Auth::setCookie(self::DEVICE_COOKIE, $c, time() + 400 * 86400);
            $_COOKIE[self::DEVICE_COOKIE] = $c;
        }
        return hash('sha256', $c);
    }

    public static function pinRemembered(array $entry, string $device): bool
    {
        $devices = Storage::read('auth/qr-devices.json', []);
        $d = $devices[$device][$entry['id']] ?? null;
        return $d && (int) $d['pin_version'] === (int) $entry['pin_version'] && $d['until'] > time();
    }

    /*
     * Schutz gegen Durchprobieren der PIN – ein Zähler je QR-Zugang, unabhängig von Gerät und IP-Adresse:
     * 10 Fehlversuche innerhalb einer Stunde → dieser QR-Zugang ist 1 Stunde komplett gesperrt
     * (auch für gemerkte Geräte; bereits laufende Erfassungen laufen weiter). Die Leitung bekommt eine Mail.
     * Schlüssel erneuern hebt die Sperre sofort auf.
     */
    private const PIN_MAX_FAILS = 10;
    private const PIN_WINDOW = 3600;
    private const PIN_LOCK = 3600;

    private static function pinKey(array $entry): string
    {
        return 'entry|' . $entry['id'];
    }

    /** Zeitpunkt, bis zu dem der QR-Zugang wegen falscher PINs gesperrt ist (0 = frei) */
    public static function lockedUntil(array $entry): int
    {
        $until = (int) (Storage::read('auth/qr-pin-attempts.json', [])[self::pinKey($entry)]['until'] ?? 0);
        return $until > time() ? $until : 0;
    }

    public static function lockText(int $until): string
    {
        return 'Zu viele falsche PIN-Eingaben – dieser QR-Code ist bis ' . date('H:i', $until) . ' Uhr gesperrt.';
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public static function checkPin(array $entry, string $device, string $pin): ?string
    {
        if ($until = self::lockedUntil($entry)) {
            return self::lockText($until);
        }
        if (preg_match('/^\d{4,6}$/', $pin) && password_verify($pin, (string) $entry['pin_hash'])) {
            // Zähler bleibt bewusst stehen – sonst könnte jede richtige Eingabe einem Angreifer neue Versuche freigeben
            Log::info('qr', 'pin_ok', 'PIN korrekt', ['qr' => $entry['label'], 'device' => substr($device, 0, 12)]);
            $days = (int) $entry['pin_remember_days'];
            if ($days > 0) {
                Storage::locked('qr-pin', static function () use ($device, $entry, $days) {
                    $devices = Storage::read('auth/qr-devices.json', []);
                    $devices[$device][$entry['id']] = ['pin_version' => (int) $entry['pin_version'], 'until' => time() + $days * 86400];
                    Storage::write('auth/qr-devices.json', $devices);
                });
            }
            return null;
        }
        Log::warning('qr', 'pin_failed', 'Falsche PIN', ['qr' => $entry['label'], 'device' => substr($device, 0, 12)]);
        $key = self::pinKey($entry);
        $until = Storage::locked('qr-pin', static function () use ($key) {
            $attempts = Storage::read('auth/qr-pin-attempts.json', []);
            $now = time();
            $fails = array_values(array_filter((array) ($attempts[$key]['fails'] ?? []), static fn ($t) => $t > $now - self::PIN_WINDOW));
            $fails[] = $now;
            $until = 0;
            $attempts[$key] = ['fails' => $fails, 'until' => 0];
            if (count($fails) >= self::PIN_MAX_FAILS) {
                $until = $now + self::PIN_LOCK;
                $attempts[$key] = ['fails' => [], 'until' => $until];
            }
            // abgelaufene Einträge (auch aus älteren Versionen mit Geräte-/IP-Zählern) aufräumen
            $attempts = array_filter($attempts, static fn ($a) => ($a['until'] ?? 0) > $now || !empty($a['fails']));
            Storage::write('auth/qr-pin-attempts.json', $attempts);
            return $until;
        });
        if ($until) {
            self::notifyLockout($entry, $until);
            return self::lockText($until);
        }
        return 'PIN falsch.';
    }

    private static function notifyLockout(array $entry, int $until): void
    {
        Log::warning('qr', 'pin_lockout', 'QR-Zugang wegen falscher PINs gesperrt', ['qr' => $entry['label'], 'until' => date('c', $until)]);
        if (Mailer::enabled()) {
            Mailer::sendText(
                Settings::mail()['recipients'],
                '[EÜDOK] QR-Zugang „' . $entry['label'] . '“ für 1 Stunde gesperrt',
                "Am QR-Zugang „{$entry['label']}“ wurde innerhalb einer Stunde " . self::PIN_MAX_FAILS . "-mal eine falsche PIN eingegeben (" . date('d.m.Y H:i') . ").\n"
                . "Der QR-Code ist deshalb bis " . date('H:i', $until) . " Uhr für alle gesperrt.\n\n"
                . "Falls das nicht von euch war: In den Einstellungen den Schlüssel erneuern (hebt die Sperre sofort auf, neuer Aufkleber nötig)."
            );
        }
    }
    /* ---------- Neuer Schlüssel für Link/Druckvorlage (nur kurz in der Admin-Sitzung) ---------- */

    private const NEW_KEY_SECONDS = 1800;

    public static function holdNewKey(string $id, string $key): void
    {
        $_SESSION['qr_keys'][$id] = ['key' => $key, 'until' => time() + self::NEW_KEY_SECONDS];
    }

    /** Klartext-Schlüssel nur innerhalb von 30 Minuten nach Anlegen/Erneuern; danach aus der Sitzung entfernt. */
    public static function heldKey(string $id): ?string
    {
        foreach ($_SESSION['qr_keys'] ?? [] as $k => $v) {
            if (!is_array($v) || ($v['until'] ?? 0) < time()) {
                unset($_SESSION['qr_keys'][$k]);
            }
        }
        return $_SESSION['qr_keys'][$id]['key'] ?? null;
    }

    /* ---------- Sitzung ---------- */

    public static function startSession(array $entry, string $device): void
    {
        session_regenerate_id(true);
        unset($_SESSION['uid']);
        $_SESSION['qr'] = [
            'access_id' => $entry['id'],
            'device' => $device,
            'since' => time(),
            'pdf' => $_SESSION['qr']['pdf'] ?? [],
        ];
    }

    /** Aktuelle QR-Sitzung inkl. Prüfung, ob der Zugang noch aktiv ist. */
    public static function current(): ?array
    {
        $ctx = $_SESSION['qr'] ?? null;
        if (!$ctx) {
            return null;
        }
        $entry = self::find($ctx['access_id']);
        if (!$entry || empty($entry['active'])) {
            unset($_SESSION['qr']);
            return null;
        }
        return ['entry' => $entry, 'device' => $ctx['device']];
    }

    public static function actor(array $entry, string $device, ?array $person = null): array
    {
        return [
            'by' => $entry['service_account'],
            'by_name' => $person['name'] ?? $entry['label'],
            'source' => 'qr:' . $entry['slug'],
            'qr_label' => $entry['label'],
            'qr_access_id' => $entry['id'],
            'qr_device' => $device,
            'created_by' => $person['id'] ?? null,
        ];
    }

    public static function rateLimitReached(array $entry): bool
    {
        $since = date('Y-m-d\TH:i:s', time() - 3600);
        $n = 0;
        foreach (Reports::index() as $r) {
            if (($r['qr_access_id'] ?? null) === $entry['id'] && ($r['closed_at'] ?? '') >= $since && ($r['correction'] ?? 0) === 0) {
                $n++;
            }
        }
        return $n >= self::RATE_LIMIT_PER_HOUR;
    }

    /** Offener Entwurf dieses Geräts für diesen QR-Zugang. */
    public static function openDraft(array $entry, string $device): ?string
    {
        foreach (Reports::list(['status' => 'draft']) as $r) {
            if (($r['qr_access_id'] ?? null) === $entry['id'] && ($r['qr_device'] ?? null) === $device) {
                return $r['number'];
            }
        }
        return null;
    }

    public static function ownsReport(string $number): bool
    {
        $ctx = self::current();
        $meta = Reports::meta($number);
        return $ctx && $meta && ($meta['qr_access_id'] ?? null) === $ctx['entry']['id'] && ($meta['qr_device'] ?? null) === $ctx['device'];
    }

    public static function grantPdf(string $number): void
    {
        $_SESSION['qr']['pdf'][$number] = time() + self::PDF_HOURS * 3600;
    }

    public static function pdfAllowed(string $number): bool
    {
        $until = $_SESSION['qr']['pdf'][$number] ?? 0;
        return $until > time() && self::ownsReport($number);
    }
}
