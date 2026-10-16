<?php
declare(strict_types=1);

namespace Euedok;

/**
 * Divera247-API v2 – nur lesend, nur serverseitig. Der API-Schlüssel verlässt den Server nie.
 * Alle Feldzugriffe sind defensiv, weil Divera Strukturen je nach Einheit leicht abweichen.
 */
final class Divera
{
    private const PULL_CACHE = 'cache/divera-pull.json';
    private const PULL_TTL = 60;

    public static function configured(): bool
    {
        $d = Settings::divera();
        return !empty($d['enabled']) && !empty($d['api_key_enc']);
    }

    public static function apiKey(): ?string
    {
        return Crypto::decrypt(Settings::divera()['api_key_enc'] ?? '');
    }

    public static function personnelFromDivera(): bool
    {
        return self::configured() && (Settings::divera()['personnel_source'] ?? 'local') === 'divera';
    }

    public static function vehiclesFromDivera(): bool
    {
        return self::configured() && self::vehicleSource() === 'divera';
    }

    /** Fahrzeugquelle wie beim Personal: 'local' (EÜDOK-Liste) oder 'divera' (live). Übernimmt den alten Schalter. */
    public static function vehicleSource(): string
    {
        $d = Settings::divera();
        if (isset($d['vehicle_source'])) {
            return $d['vehicle_source'] === 'divera' ? 'divera' : 'local';
        }
        return !empty($d['vehicles_from_divera']) ? 'divera' : 'local';
    }

    /** Darf für diese Berichtsart (und ggf. QR) in Divera nachgesehen werden? */
    public static function lookupAllowed(string $typeId, bool $quickEntry): ?string
    {
        if (!self::configured()) {
            return null;
        }
        $d = Settings::divera();
        if ($quickEntry && empty($d['quick_entry_enabled'])) {
            return null;
        }
        $source = Settings::reportType($typeId)['divera_source'] ?? null;
        if ($source === 'alarm' && !empty($d['incident_enabled'])) {
            return 'alarm';
        }
        if ($source === 'event' && !empty($d['exercise_enabled'])) {
            return 'event';
        }
        return null;
    }

    /* ---------- HTTP ---------- */

    /** @throws \RuntimeException wenn Divera nicht erreichbar ist oder ablehnt */
    private static function request(string $path, ?string $key = null): array
    {
        $key ??= self::apiKey();
        if (!$key) {
            throw new \RuntimeException('Kein Divera-API-Schlüssel hinterlegt.');
        }
        // Nach einem Ausfall 60 s nicht erneut warten lassen – Seiten öffnen sofort mit Puffer
        $down = Storage::read('cache/divera-down.json');
        if ($key === self::apiKey() && is_array($down) && ($down['until'] ?? 0) > time()) {
            Log::debug('divera', 'skipped', 'Divera kürzlich nicht erreichbar – Abruf übersprungen', ['path' => $path, 'until' => date('H:i:s', $down['until'])]);
            throw new \RuntimeException((string) ($down['error'] ?? 'Divera nicht erreichbar.'));
        }

        $d = Settings::divera();
        $url = rtrim((string) $d['base_url'], '/') . $path . (str_contains($path, '?') ? '&' : '?') . 'accesskey=' . rawurlencode($key);
        $ch = curl_init($url);
        $timeout = max(1, (int) $d['timeout_seconds']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout + 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'EUEDOK/' . EUEDOK_VERSION,
        ]);
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $ctx = ['path' => $path, 'http' => $status, 'ms' => $ms, 'bytes' => is_string($body) ? strlen($body) : 0];
        $fail = static function (string $msg, bool $markDown) use ($ctx, $body, $path, $key): never {
            $raw = is_string($body) && $body !== '' ? Log::raw('error' . str_replace('/', '-', $path), $body) : null;
            Log::error('divera', 'request_failed', $msg, $ctx + ($raw ? ['raw_file' => $raw] : []));
            if ($markDown && $key === self::apiKey()) {
                Storage::write('cache/divera-down.json', ['until' => time() + 60, 'error' => $msg]);
            }
            throw new \RuntimeException($msg);
        };

        if ($body === false) {
            $ctx['curl_errno'] = $errno;
            $fail('Divera nicht erreichbar (' . $err . ').', true);
        }
        $json = json_decode((string) $body, true);
        if ($status === 403 || $status === 401) {
            $fail('Divera lehnt den API-Schlüssel ab (HTTP ' . $status . ').', false);
        }
        if ($status >= 400 || !is_array($json)) {
            $fail('Divera antwortet mit Fehler (HTTP ' . $status . ($json === null ? ', keine gültige JSON-Antwort' : '') . ').', $status >= 500);
        }
        if (isset($json['success']) && $json['success'] === false) {
            $fail('Divera meldet einen Fehler: ' . (is_string($json['message'] ?? null) ? $json['message'] : json_encode($json['errors'] ?? 'unbekannt', JSON_UNESCAPED_UNICODE)), false);
        }

        $data = $json['data'] ?? null;
        $ctx['items'] = is_array($data) ? (isset($data['items']) && is_array($data['items']) ? count($data['items']) : count($data)) : null;
        if (is_array($data) && !isset($data['items']) && str_contains($path, 'pull/all')) {
            $ctx['top_level_keys'] = array_keys($data);
        }
        Log::info('divera', 'request', 'Abruf ' . $path . ' ok', $ctx);
        if ($ms > $timeout * 1000 * 0.8) {
            Log::warning('divera', 'slow', 'Divera antwortet langsam (' . $ms . ' ms)', ['path' => $path]);
        }
        @unlink(Storage::path('cache/divera-down.json'));
        return $json;
    }

    /** Gesamtabruf (Einheit, Personal, Status, Fahrzeuge, Alarme, Termine), 60 s zwischengespeichert. */
    public static function pull(bool $fresh = false, ?string $key = null): array
    {
        if (!$fresh && $key === null) {
            $cached = Storage::read(self::PULL_CACHE);
            if (is_array($cached) && ($cached['fetched'] ?? 0) > time() - self::PULL_TTL) {
                return $cached['data'];
            }
        }
        $json = self::request('/api/v2/pull/all', $key);
        $data = $json['data'] ?? [];
        if ($key === null) {
            Storage::write(self::PULL_CACHE, ['fetched' => time(), 'data' => $data]);
            self::refreshBuffers($data);
        }
        return $data;
    }

    /** Reiner Puffer für Ausfälle: zuletzt erfolgreich abgerufene Listen. */
    private static function refreshBuffers(array $data): void
    {
        $stamp = now_iso();
        Storage::write('cache/divera-personnel.json', ['fetched_at' => $stamp, 'items' => self::parsePersonnel($data)]);
        Storage::write('cache/divera-vehicles.json', ['fetched_at' => $stamp, 'items' => self::parseVehicles($data)]);
        Storage::write('cache/divera-statuses.json', ['fetched_at' => $stamp, 'items' => self::parseStatuses($data)]);
    }

    /* ---------- Listen ---------- */

    /** @return array{items: array, stale: bool, fetched_at: ?string, error: ?string} */
    public static function personnel(): array
    {
        return self::listWithBuffer('cache/divera-personnel.json', [self::class, 'parsePersonnel']);
    }

    public static function vehicles(): array
    {
        return self::listWithBuffer('cache/divera-vehicles.json', [self::class, 'parseVehicles']);
    }

    public static function statuses(): array
    {
        return self::listWithBuffer('cache/divera-statuses.json', [self::class, 'parseStatuses']);
    }

    private static function listWithBuffer(string $bufferFile, callable $parser): array
    {
        try {
            $data = self::pull();
            return ['items' => $parser($data), 'stale' => false, 'fetched_at' => now_iso(), 'error' => null];
        } catch (\Throwable $e) {
            $buf = Storage::read($bufferFile, []);
            Log::warning('divera', 'buffer_used', 'Puffer verwendet: ' . basename($bufferFile), [
                'error' => $e->getMessage(),
                'buffer_from' => $buf['fetched_at'] ?? null,
                'items' => count($buf['items'] ?? []),
            ]);
            return [
                'items' => $buf['items'] ?? [],
                'stale' => true,
                'fetched_at' => $buf['fetched_at'] ?? null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private static function items(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        if (isset($node['items']) && is_array($node['items'])) {
            $node = $node['items'];
        }
        $out = [];
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $v['id'] ??= $k;
                $out[] = $v;
            }
        }
        return $out;
    }

    public static function parsePersonnel(array $data): array
    {
        $out = [];
        foreach (self::items($data['cluster']['consumer'] ?? []) as $c) {
            $last = trim((string) ($c['lastname'] ?? ''));
            $first = trim((string) ($c['firstname'] ?? ''));
            $name = $last !== '' ? trim($last . ($first !== '' ? ', ' . $first : '')) : trim((string) ($c['stdformat_name'] ?? $c['name'] ?? $first));
            if ($name === '') {
                continue;
            }
            $out[] = ['id' => 'div-' . $c['id'], 'name' => $name, 'divera_id' => (int) $c['id'], 'active' => true];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    public static function parseVehicles(array $data): array
    {
        $out = [];
        foreach (self::items($data['cluster']['vehicle'] ?? []) as $v) {
            $label = trim((string) ($v['fullname'] ?? '')) ?: trim((string) ($v['name'] ?? '')) ?: trim((string) ($v['shortname'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'id' => 'div-veh-' . $v['id'],
                'divera_id' => (int) $v['id'],
                'label' => $label,
                'radio_call_sign' => trim((string) ($v['shortname'] ?? $v['name'] ?? '')),
                'license_plate' => trim((string) ($v['number'] ?? $v['license_plate'] ?? '')),
                'source' => 'divera',
                'active' => true,
            ];
        }
        return $out;
    }

    public static function parseStatuses(array $data): array
    {
        $out = [];
        foreach (self::items($data['cluster']['status'] ?? []) as $s) {
            $out[] = ['id' => (string) $s['id'], 'label' => trim((string) ($s['name'] ?? ('Status ' . $s['id'])))];
        }
        return $out;
    }

    /* ---------- Alarme & Termine ---------- */

    private static function alarms(array $data): array
    {
        // pull/all enthält je nach Schlüssel-Art keine Alarme → eigener Endpunkt
        return self::items($data['alarm'] ?? self::endpoint('/api/v2/alarms', 'cache/divera-alarms.json'));
    }

    private static function events(array $data): array
    {
        return self::items($data['events'] ?? $data['event'] ?? self::endpoint('/api/v2/events', 'cache/divera-events.json'));
    }

    /** Abruf eines Listen-Endpunkts, 60 s zwischengespeichert. */
    private static function endpoint(string $path, string $cacheFile): array
    {
        $cached = Storage::read($cacheFile);
        if (is_array($cached) && ($cached['fetched'] ?? 0) > time() - self::PULL_TTL) {
            return $cached['data'];
        }
        $data = self::request($path)['data'] ?? [];
        $data = is_array($data) ? $data : [];
        Storage::write($cacheFile, ['fetched' => time(), 'data' => $data]);
        return $data;
    }

    private static function ts(mixed $v): ?int
    {
        if (is_numeric($v) && (int) $v > 0) {
            return (int) $v;
        }
        if (is_string($v) && ($t = strtotime($v))) {
            return $t;
        }
        return null;
    }

    /** ucr_answered: {antwortId: {ucrId: {...}}} oder {antwortId: [ucrId, …]} → [antwortId => [ucrIds]] */
    private static function answers(array $item): array
    {
        $out = [];
        foreach ((array) ($item['ucr_answered'] ?? []) as $answerId => $people) {
            if (!is_array($people)) {
                continue;
            }
            $ids = array_is_list($people) ? array_map(static fn ($p) => is_array($p) ? ($p['id'] ?? null) : $p, $people) : array_keys($people);
            $out[(string) $answerId] = array_values(array_filter(array_map('strval', array_filter($ids, static fn ($x) => $x !== null))));
        }
        return $out;
    }

    private static function attendingIds(array $item, string $source): array
    {
        $d = Settings::divera();
        if ($source === 'event') {
            if (empty($d['event_accept_counts'])) {
                return [];
            }
            $accept = array_map('strval', (array) $d['event_accept_ids']);
        } else {
            $accept = array_map(static fn ($s) => (string) ($s['id'] ?? $s), (array) $d['attendance_statuses']);
        }
        $ids = [];
        foreach (self::answers($item) as $answerId => $ucrIds) {
            if (in_array($answerId, $accept, true)) {
                array_push($ids, ...$ucrIds);
            }
        }
        return array_values(array_unique($ids));
    }

    /** Kandidaten im Zeitfenster, neuester zuerst. */
    public static function candidates(string $source): array
    {
        $data = self::pull();
        $window = max(1, (int) Settings::divera()['window_hours']) * 3600;
        $now = time();
        $imported = Reports::diveraImports();
        $list = [];
        $items = $source === 'alarm' ? self::alarms($data) : self::events($data);
        foreach ($items as $it) {
            if ($source === 'alarm') {
                $start = self::ts($it['date'] ?? $it['ts_create'] ?? null);
                $end = !empty($it['closed']) ? self::ts($it['ts_close'] ?? $it['ts_update'] ?? null) : null;
                if (!$start || $start < $now - $window || $start > $now + 3600) {
                    continue;
                }
            } else {
                $start = self::ts($it['start'] ?? null);
                $end = self::ts($it['end'] ?? null);
                $relevantEnd = $end ?? $start;
                // Termine, die im Zeitfenster begonnen haben oder gerade laufen
                if (!$start || $start > $now + 3600 || $relevantEnd < $now - $window) {
                    continue;
                }
            }
            $key = $source . ':' . $it['id'];
            $list[] = [
                'source' => $source,
                'id' => (string) $it['id'],
                'title' => trim((string) ($it['title'] ?? '')) ?: '(ohne Stichwort)',
                'address' => trim((string) ($it['address'] ?? '')),
                'start' => date('Y-m-d\TH:i', $start),
                'end' => $end ? date('Y-m-d\TH:i', $end) : null,
                'attending' => count(self::attendingIds($it, $source)),
                'imported_in' => $imported[$key] ?? null,
            ];
        }
        usort($list, static fn ($a, $b) => strcmp($b['start'], $a['start']));
        Log::info('divera', 'candidates', count($list) . ' ' . ($source === 'alarm' ? 'Alarme' : 'Termine') . ' im Zeitfenster', [
            'source' => $source, 'total' => count($items), 'in_window' => count($list), 'window_h' => $window / 3600,
        ]);
        return $list;
    }

    /** Daten zur Übernahme in einen Bericht. */
    public static function importData(string $source, string $id): ?array
    {
        $data = self::pull();
        $items = $source === 'alarm' ? self::alarms($data) : self::events($data);
        $item = null;
        foreach ($items as $it) {
            if ((string) $it['id'] === $id) {
                $item = $it;
                break;
            }
        }
        if ($item === null) {
            Log::warning('divera', 'import_not_found', 'Eintrag nicht mehr in Divera gefunden', ['source' => $source, 'id' => $id, 'available' => count($items)]);
            return null;
        }
        $consumers = [];
        foreach (self::parsePersonnel($data) as $p) {
            $consumers[(string) $p['divera_id']] = $p;
        }
        $personnel = [];
        $unknown = [];
        $attending = self::attendingIds($item, $source);
        foreach ($attending as $ucr) {
            if (isset($consumers[$ucr])) {
                $personnel[] = MasterData::matchPerson($consumers[$ucr]);
            } else {
                $unknown[] = $ucr;
            }
        }
        // Für die Analyse: welche Antworten gab es, was wurde als „eingesetzt“ gewertet?
        $answerSummary = array_map('count', self::answers($item));
        $rawFile = Log::raw('import-' . $source . '-' . $id, (string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        Log::info('divera', 'import', 'Übernahme ' . ($source === 'alarm' ? 'Alarm' : 'Termin') . ' ' . $id, [
            'answers_by_id' => $answerSummary,
            'counted_ids' => $source === 'event' ? Settings::divera()['event_accept_ids'] : array_column(Settings::divera()['attendance_statuses'], 'id'),
            'attending' => count($attending),
            'matched_persons' => count($personnel),
            'unknown_ucr_ids' => $unknown,
            'vehicles_in_alarm' => count((array) ($item['vehicle'] ?? [])),
            'raw_file' => $rawFile,
        ]);
        if ($answerSummary && !$attending) {
            Log::warning('divera', 'no_attendance_match', 'Es gibt Rückmeldungen, aber keine passt zu den als „eingesetzt“ markierten IDs', ['answers_by_id' => $answerSummary]);
        }

        $vehicles = [];
        if ($source === 'alarm' && Settings::moduleOn('vehicles')) {
            $byId = [];
            foreach (self::parseVehicles($data) as $v) {
                $byId[(string) $v['divera_id']] = $v;
            }
            foreach ((array) ($item['vehicle'] ?? []) as $vid) {
                $vid = (string) (is_array($vid) ? ($vid['id'] ?? '') : $vid);
                if (isset($byId[$vid])) {
                    $vehicles[] = MasterData::matchVehicle($byId[$vid]);
                }
            }
        }

        if ($source === 'alarm') {
            $start = self::ts($item['date'] ?? $item['ts_create'] ?? null);
            $end = !empty($item['closed']) ? self::ts($item['ts_close'] ?? $item['ts_update'] ?? null) : null;
        } else {
            $start = self::ts($item['start'] ?? null);
            $end = self::ts($item['end'] ?? null);
        }
        $typeKey = $source === 'alarm' ? 'incident' : 'exercise';
        $alertId = (string) (Settings::divera()['default_alert_source'][$typeKey] ?? '');

        return [
            'start' => $start ? date('Y-m-d\TH:i', $start) : null,
            'end' => $end ? date('Y-m-d\TH:i', $end) : null,
            'subject' => trim((string) ($item['title'] ?? '')),
            'location' => trim((string) ($item['address'] ?? '')),
            'alert_source' => $alertId !== '' ? MasterData::alertSource($alertId) : null,
            'personnel' => array_values(array_filter($personnel)),
            'vehicles' => array_values(array_filter($vehicles)),
            'divera' => [
                'type' => $source,
                'id' => is_numeric($item['id']) ? (int) $item['id'] : (string) $item['id'],
                'title' => trim((string) ($item['title'] ?? '')),
                'text' => trim((string) ($item['text'] ?? '')),
                'imported_at' => now_iso(),
            ],
        ];
    }

    /** Testabruf für die Einstellungsseite – zeigt nur Zählwerte und Strukturen, keine Namen. */
    public static function test(?string $key = null): array
    {
        $data = self::pull(true, $key);
        if ($key === null) {
            @unlink(Storage::path('cache/divera-alarms.json'));
            @unlink(Storage::path('cache/divera-events.json'));
        }
        $answerKeys = ['alarm' => [], 'event' => []];
        foreach (['alarm' => self::alarms($data), 'event' => self::events($data)] as $src => $items) {
            foreach ($items as $it) {
                foreach (self::answers($it) as $aid => $ids) {
                    $answerKeys[$src][$aid] = ($answerKeys[$src][$aid] ?? 0) + count($ids);
                }
            }
        }
        if ($key === null) {
            self::refreshBuffers($data);
        }
        return [
            'unit' => trim((string) ($data['cluster']['name'] ?? '')),
            'personnel' => count(self::parsePersonnel($data)),
            'vehicles' => count(self::parseVehicles($data)),
            'statuses' => self::parseStatuses($data),
            'alarms' => count(self::alarms($data)),
            'events' => count(self::events($data)),
            'answer_keys' => $answerKeys,
            'top_level_keys' => array_keys($data),
        ];
    }
}
