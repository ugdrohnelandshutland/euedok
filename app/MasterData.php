<?php
declare(strict_types=1);

namespace Euedok;

/** Auswahllisten für das Formular: Personal, Geräte, Fahrzeuge, Alarmierung – lokal oder aus Divera. */
final class MasterData
{
    private static ?array $personnelCache = null;
    private static ?array $vehicleCache = null;

    private static function active(array $list): array
    {
        return array_values(array_filter($list, static fn ($x) => !empty($x['active'])));
    }

    /** @return array{items: array, source: string, stale: bool, fetched_at: ?string, error: ?string} */
    public static function personnel(): array
    {
        if (self::$personnelCache !== null) {
            return self::$personnelCache;
        }
        if (Divera::personnelFromDivera()) {
            $r = Divera::personnel();
            $r['source'] = 'divera';
        } else {
            $r = ['items' => self::active(Settings::masterData()['personnel']), 'source' => 'local', 'stale' => false, 'fetched_at' => null, 'error' => null];
        }
        // häufig Eingesetzte zuerst (letzte 12 Monate), sonst alphabetisch
        $freq = Reports::personnelFrequency();
        usort($r['items'], static function ($a, $b) use ($freq) {
            $fa = $freq[$a['id']] ?? 0;
            $fb = $freq[$b['id']] ?? 0;
            return $fb <=> $fa ?: strcasecmp($a['name'], $b['name']);
        });
        foreach ($r['items'] as &$p) {
            $p['frequency'] = $freq[$p['id']] ?? 0;
        }
        unset($p);
        return self::$personnelCache = $r;
    }

    public static function equipment(): array
    {
        return self::active(Settings::masterData()['equipment']);
    }

    public static function alertSources(): array
    {
        return self::active(Settings::masterData()['alert_sources']);
    }

    /** @return array{items: array, source: string, stale: bool, fetched_at: ?string, error: ?string} */
    public static function vehicles(): array
    {
        if (self::$vehicleCache !== null) {
            return self::$vehicleCache;
        }
        // Fahrzeugquelle wie beim Personal: entweder Divera live oder die EÜDOK-Liste
        if (Divera::vehiclesFromDivera()) {
            $r = Divera::vehicles();
            return self::$vehicleCache = ['items' => $r['items'], 'source' => 'divera', 'stale' => $r['stale'], 'fetched_at' => $r['fetched_at'], 'error' => $r['error']];
        }
        $items = [];
        foreach (self::active(Settings::masterData()['vehicles']) as $v) {
            $v['source'] = 'local';
            $items[] = $v;
        }
        return self::$vehicleCache = ['items' => $items, 'source' => 'local', 'stale' => false, 'fetched_at' => null, 'error' => null];
    }

    public static function findPerson(string $id): ?array
    {
        foreach (self::personnel()['items'] as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        // auch inaktive lokale Personen auflösen (alte Entwürfe)
        foreach (Settings::masterData()['personnel'] as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    public static function alertSource(string $id): ?array
    {
        foreach (Settings::masterData()['alert_sources'] as $s) {
            if ($s['id'] === $id) {
                return ['id' => $s['id'], 'label' => $s['label']];
            }
        }
        return null;
    }

    public static function findEquipment(string $id): ?array
    {
        foreach (Settings::masterData()['equipment'] as $q) {
            if ($q['id'] === $id) {
                return $q;
            }
        }
        return null;
    }

    public static function findVehicle(string $id): ?array
    {
        foreach (self::vehicles()['items'] as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        foreach (Settings::masterData()['vehicles'] as $v) {
            if ($v['id'] === $id) {
                return $v + ['source' => 'local'];
            }
        }
        return null;
    }

    /** Divera-Person → Eintrag der aktuellen Personalquelle (lokal: Abgleich über den Namen). */
    public static function matchPerson(array $diveraPerson): array
    {
        if (!Divera::personnelFromDivera()) {
            foreach (self::active(Settings::masterData()['personnel']) as $p) {
                if (mb_strtolower(trim($p['name'])) === mb_strtolower(trim($diveraPerson['name']))) {
                    return ['id' => $p['id'], 'name' => $p['name'], 'divera_id' => $diveraPerson['divera_id']];
                }
            }
        }
        return ['id' => $diveraPerson['id'], 'name' => $diveraPerson['name'], 'divera_id' => $diveraPerson['divera_id']];
    }

    public static function matchVehicle(array $diveraVehicle): array
    {
        if (!Divera::vehiclesFromDivera()) {
            foreach (self::active(Settings::masterData()['vehicles']) as $v) {
                $same = mb_strtolower($v['label']) === mb_strtolower($diveraVehicle['label'])
                    || ($v['radio_call_sign'] !== '' && $v['radio_call_sign'] === $diveraVehicle['radio_call_sign']);
                if ($same) {
                    return ['id' => $v['id'], 'label' => $v['label'], 'source' => 'local'];
                }
            }
        }
        return ['id' => $diveraVehicle['id'], 'label' => $diveraVehicle['label'], 'source' => 'divera'];
    }
}
