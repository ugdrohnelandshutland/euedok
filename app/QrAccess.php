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
    private const PIN_MAX_ATTEMPTS = 5;
    private const PIN_LOCK_SECONDS = 900;
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

    public static function pinLockedUntil(array $entry, string $device): int
    {
        $attempts = Storage::read('auth/qr-pin-attempts.json', []);
        $a = $attempts[$device . '|' . $entry['id']] ?? null;
        return ($a && $a['until'] > time()) ? (int) $a['until'] : 0;
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public static function checkPin(array $entry, string $device, string $pin): ?string
    {
        if (self::pinLockedUntil($entry, $device)) {
            return 'Zu viele Fehlversuche – dieses Gerät ist für 15 Minuten gesperrt.';
        }
        $k = $device . '|' . $entry['id'];
        if (preg_match('/^\d{4,6}$/', $pin) && password_verify($pin, (string) $entry['pin_hash'])) {
            Log::info('qr', 'pin_ok', 'PIN korrekt', ['qr' => $entry['label'], 'device' => substr($device, 0, 12)]);
            Storage::locked('qr-pin', static function () use ($k, $device, $entry) {
                $attempts = Storage::read('auth/qr-pin-attempts.json', []);
                unset($attempts[$k]);
                Storage::write('auth/qr-pin-attempts.json', $attempts);
                $days = (int) $entry['pin_remember_days'];
                if ($days > 0) {
                    $devices = Storage::read('auth/qr-devices.json', []);
                    $devices[$device][$entry['id']] = ['pin_version' => (int) $entry['pin_version'], 'until' => time() + $days * 86400];
                    Storage::write('auth/qr-devices.json', $devices);
                }
            });
            return null;
        }
        Log::warning('qr', 'pin_failed', 'Falsche PIN', ['qr' => $entry['label'], 'device' => substr($device, 0, 12)]);
        $locked = Storage::locked('qr-pin', static function () use ($k) {
            $attempts = Storage::read('auth/qr-pin-attempts.json', []);
            $a = $attempts[$k] ?? ['count' => 0, 'until' => 0];
            $a['count']++;
            $locked = false;
            if ($a['count'] >= self::PIN_MAX_ATTEMPTS) {
                $a = ['count' => 0, 'until' => time() + self::PIN_LOCK_SECONDS];
                $locked = true;
            }
            $attempts[$k] = $a;
            Storage::write('auth/qr-pin-attempts.json', $attempts);
            return $locked;
        });
        if ($locked) {
            self::notifyLockout($entry);
            return 'Zu viele Fehlversuche – dieses Gerät ist für 15 Minuten gesperrt.';
        }
        return 'PIN falsch.';
    }

    private static function notifyLockout(array $entry): void
    {
        Log::warning('qr', 'pin_lockout', 'PIN-Sperre ausgelöst (5 Fehlversuche)', ['qr' => $entry['label']]);
        if (Mailer::enabled()) {
            Mailer::sendText(
                Settings::mail()['recipients'],
                '[EÜDOK] Hinweis: PIN-Sperre beim QR-Zugang „' . $entry['label'] . '“',
                "Am QR-Zugang „{$entry['label']}“ wurde fünfmal eine falsche PIN eingegeben.\n"
                . 'Das betroffene Gerät ist für 15 Minuten gesperrt (' . date('d.m.Y H:i') . ").\n\n"
                . "Falls das nicht von euch war: QR-Zugang in den Einstellungen sperren oder Schlüssel erneuern."
            );
        }
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
