<?php
declare(strict_types=1);

namespace Euedok;

/**
 * Zugriff auf data/settings/*.json. Diese Dateien schreibt nur EÜDOK selbst –
 * bearbeitet wird ausschließlich über die Einstellungsseite.
 */
final class Settings
{
    private static array $cache = [];

    private const FILES = [
        'unit' => 'unit.json',
        'master-data' => 'master-data.json',
        'custom-fields' => 'custom-fields.json',
        'users' => 'users.json',
        'qr-access' => 'qr-access.json',
        'mail' => 'mail.json',
        'divera' => 'divera.json',
    ];

    public static function isInstalled(): bool
    {
        return is_file(Storage::path('settings/unit.json')) && is_file(Storage::path('settings/users.json'));
    }

    public static function get(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            $defaults = self::defaults($name);
            $stored = Storage::read('settings/' . self::FILES[$name], []);
            self::$cache[$name] = self::merge($defaults, is_array($stored) ? $stored : []);
        }
        return self::$cache[$name];
    }

    public static function save(string $name, array $data, ?string $auditText = null): void
    {
        Storage::write('settings/' . self::FILES[$name], $data);
        self::$cache[$name] = self::merge(self::defaults($name), $data);
        if ($auditText !== null) {
            Log::audit($name, $auditText);
        }
    }

    public static function unit(): array
    {
        return self::get('unit');
    }

    public static function masterData(): array
    {
        return self::get('master-data');
    }

    public static function customFields(): array
    {
        return self::get('custom-fields');
    }

    public static function mail(): array
    {
        return self::get('mail');
    }

    public static function divera(): array
    {
        return self::get('divera');
    }

    public static function reportType(string $id): ?array
    {
        foreach (self::unit()['report_types'] as $t) {
            if ($t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    public static function activeReportTypes(): array
    {
        return array_values(array_filter(self::unit()['report_types'], static fn ($t) => !empty($t['active'])));
    }

    public static function moduleOn(string $module): bool
    {
        return !empty(self::unit()['modules'][$module]);
    }

    public static function isRequired(string $field): bool
    {
        return in_array($field, self::unit()['required_fields'], true);
    }

    /** @return array<int,array> aktive Felder einer Art (report|person|vehicle) */
    public static function fields(string $kind): array
    {
        return array_values(array_filter(self::customFields()[$kind] ?? [], static fn ($f) => !empty($f['active'])));
    }

    private static function defaults(string $name): array
    {
        return match ($name) {
            'unit' => Defaults::unit(),
            'master-data' => Defaults::masterData(),
            'custom-fields' => Defaults::customFields(),
            'users' => Defaults::users(),
            'qr-access' => Defaults::qrAccess(),
            'mail' => Defaults::mail(),
            'divera' => Defaults::divera(),
        };
    }

    /** Ergänzt fehlende Schlüssel aus den Grundwerten (nur assoziative Ebenen). */
    private static function merge(array $defaults, array $stored): array
    {
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $stored)) {
                $stored[$k] = $v;
            } elseif (is_array($v) && is_array($stored[$k]) && $v !== [] && !array_is_list($v)) {
                $stored[$k] = self::merge($v, $stored[$k]);
            }
        }
        return $stored;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
