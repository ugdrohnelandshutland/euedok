<?php
declare(strict_types=1);

namespace Euedok;

/**
 * Berichte: Nummernvergabe, Entwurf speichern, Abschließen (JSON + PDF + Mail), Übersicht.
 * Ablage: data/reports/<Jahr>/<Berichtsart>bericht_<Nummer>.json|.pdf
 * Interner Index (data/reports/index.json) – lässt sich jederzeit aus den Dateien neu aufbauen.
 */
final class Reports
{
    public const MAX_ATTACHMENTS = 10;
    public const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;
    public const ATTACHMENT_CAPTION_MAX = 120;
    public const SCHEMA_VERSION = 1;
    private const INDEX = 'reports/index.json';

    /* ---------- Index ---------- */

    public static function index(): array
    {
        $idx = Storage::read(self::INDEX, []);
        return is_array($idx) ? $idx : [];
    }

    private static function updateIndex(string $number, ?array $entry): void
    {
        Storage::locked('index', static function () use ($number, $entry) {
            $idx = self::index();
            if ($entry === null) {
                unset($idx[$number]);
            } else {
                $idx[$number] = array_merge($idx[$number] ?? [], $entry);
            }
            Storage::write(self::INDEX, $idx);
        });
    }

    public static function meta(string $number): ?array
    {
        return self::index()[$number] ?? null;
    }

    /* ---------- Dateinamen ---------- */

    /** Deutscher Dateiname ohne Endung, z. B. „Einsatzbericht_E-2026-004“. */
    public static function fileBase(array $type, string $number): string
    {
        return ascii_name(self::documentTitle($type)) . '_' . ascii_name($number);
    }

    /** „Neuer Einsatz“, „Neue Übung“ (weibliche Endungen → „Neue“) */
    public static function newLabel(array $type): string
    {
        $word = trim((string) $type['label']);
        return (preg_match('/(ung|heit|keit|schaft|ion|tät|e)$/u', $word) ? 'Neue ' : 'Neuer ') . $word;
    }

    /** „Einsatzbericht“, „Übungsbericht“, „Ausbildungsbericht“ … */
    public static function documentTitle(array $type): string
    {
        $word = trim((string) $type['label']);
        $fugenS = preg_match('/(ung|heit|keit|schaft|ion|tät)$/u', $word) ? 's' : '';
        return $word . $fugenS . 'bericht';
    }

    private static function jsonPath(array $meta): string
    {
        return 'reports/' . $meta['file'] . '.json';
    }

    public static function pdfPath(array $meta): string
    {
        return Storage::path('reports/' . $meta['file'] . '.pdf');
    }

    /** Bericht schreiben; leere Objekte bleiben im JSON Objekte ({} statt []) */
    private static function persist(string $relative, array $report): void
    {
        foreach (['custom_fields'] as $k) {
            if (isset($report[$k]) && $report[$k] === []) {
                $report[$k] = new \stdClass();
            }
        }
        foreach (['personnel', 'vehicles'] as $list) {
            foreach ($report[$list] ?? [] as $i => $x) {
                if (($x['fields'] ?? null) === []) {
                    $report[$list][$i]['fields'] = new \stdClass();
                }
            }
        }
        Storage::write($relative, $report);
    }

    public static function load(string $number): ?array
    {
        $meta = self::meta($number);
        if (!$meta) {
            return null;
        }
        $r = Storage::read(self::jsonPath($meta));
        return is_array($r) ? $r : null;
    }

    /* ---------- Nummernvergabe ---------- */

    private static function numberFormat(array $type): string
    {
        return (string) ($type['number_format'] ?: strtoupper(substr($type['id'], 0, 1)) . '-{YYYY}-{NNN}');
    }

    /** @return array{0:string,1:int} Berichtsnummer und laufende Nummer */
    private static function nextNumber(array $type, int $year): array
    {
        return Storage::locked('counters', static function () use ($type, $year) {
            $counters = Storage::read('reports/counters.json', []);
            $key = $type['id'] . '-' . $year;
            $n = ($counters[$key] ?? 0) + 1;
            $counters[$key] = $n;
            Storage::write('reports/counters.json', $counters);
            $out = str_replace(['{YYYY}', '{YY}'], [(string) $year, substr((string) $year, -2)], self::numberFormat($type));
            return [(string) preg_replace_callback('/\{(N+)\}/', static fn ($m) => str_pad((string) $n, strlen($m[1]), '0', STR_PAD_LEFT), $out), $n];
        });
    }

    /** Laufende Nummer eines Index-Eintrags: gespeichert (seit 1.4.2) oder aus dem Nummernformat gelesen; null = unklar */
    private static function seqOf(array $meta): ?int
    {
        if (isset($meta['seq'])) {
            return (int) $meta['seq'];
        }
        $type = Settings::reportType((string) ($meta['type'] ?? ''));
        if (!$type) {
            return null;
        }
        $year = (string) ($meta['year'] ?? '');
        $re = str_replace(['\{YYYY\}', '\{YY\}'], [$year, substr($year, -2)], preg_quote(self::numberFormat($type), '/'));
        $re = (string) preg_replace('/\\\\\{N+\\\\\}/', '(\d+)', $re);
        return preg_match('/^' . $re . '$/', (string) ($meta['number'] ?? ''), $m) ? (int) $m[1] : null;
    }

    /**
     * Nach dem Löschen eines Entwurfs: War er die zuletzt vergebene Nummer, zählt der Zähler auf die höchste
     * verbliebene Nummer zurück – der nächste Bericht bekommt die Nummer wieder. Lücken in der Mitte bleiben.
     * Ist eine verbliebene Nummer nicht lesbar (z. B. Format geändert), bleibt der Zähler sicherheitshalber stehen.
     */
    private static function releaseNumber(array $meta): void
    {
        $seq = self::seqOf($meta);
        if ($seq === null) {
            return;
        }
        Storage::locked('counters', static function () use ($meta, $seq) {
            $counters = Storage::read('reports/counters.json', []);
            $key = $meta['type'] . '-' . $meta['year'];
            if ((int) ($counters[$key] ?? 0) !== $seq) {
                return;
            }
            $max = 0;
            foreach (self::index() as $m) {
                if (($m['type'] ?? null) === $meta['type'] && (int) ($m['year'] ?? 0) === (int) $meta['year']) {
                    $s = self::seqOf($m);
                    if ($s === null) {
                        return;
                    }
                    $max = max($max, $s);
                }
            }
            $counters[$key] = $max;
            Storage::write('reports/counters.json', $counters);
            Log::info('report', 'number_released', 'Zähler ' . $key . ' zurück auf ' . $max, ['discarded' => $meta['number']]);
        });
    }

    /* ---------- Anlegen ---------- */

    /**
     * @param array $actor ['by' => Zugang, 'by_name' => Anzeigename, 'source' => null|'qr:slug', 'qr_access_id' => ?, 'qr_device' => ?, 'person' => ?array]
     */
    public static function create(string $typeId, array $actor): array
    {
        $type = Settings::reportType($typeId);
        if (!$type || empty($type['active'])) {
            throw new \InvalidArgumentException('Unbekannte Berichtsart.');
        }
        $year = (int) date('Y');
        [$number, $seq] = self::nextNumber($type, $year);
        $now = time();
        $rounded = $now - ($now % 300);

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'report_number' => $number,
            'type' => ['id' => $type['id'], 'label' => $type['label']],
            'start' => date('Y-m-d\TH:i', $rounded),
            'end' => null,
            'duration_min' => null,
            'location' => '',
            'subject' => '',
            'alert_source' => null,
            'narrative' => '',
            'defects' => '',
            'equipment' => [],
            'personnel' => [],
            'lead' => null,
            'custom_fields' => new \stdClass(),
            'status' => 'draft',
            'history' => [],
        ];
        if (Settings::moduleOn('vehicles')) {
            $report['vehicles'] = [];
        }
        if (!empty($actor['source'])) {
            // Schnellerfassung: niemand angemeldet → Pflichtfeld „Erstellt durch“
            $report['created_by'] = null;
        }
        if (!empty($actor['person'])) {
            $report['personnel'][] = ['id' => $actor['person']['id'], 'name' => $actor['person']['name']]
                + (isset($actor['person']['divera_id']) ? ['divera_id' => $actor['person']['divera_id']] : [])
                + ['fields' => self::emptyFields('person')];
        }
        $report['history'][] = self::historyEntry('created', $actor);
        $report['field_definitions'] = self::fieldDefinitions();

        $file = $year . '/' . self::fileBase($type, $number);
        self::persist('reports/' . $file . '.json', $report);
        self::updateIndex($number, [
            'number' => $number,
            'seq' => $seq,
            'year' => $year,
            'type' => $type['id'],
            'file' => $file,
            'status' => 'draft',
            'rev' => 1,
            'created_at' => now_iso(),
            'created_by_user' => $actor['by'],
            'qr_access_id' => $actor['qr_access_id'] ?? null,
            'qr_label' => $actor['qr_label'] ?? null,
            'qr_device' => $actor['qr_device'] ?? null,
        ] + self::summary($report));
        Log::info('report', 'created', $number . ' angelegt', ['type' => $type['id'], 'by' => $actor['by'], 'source' => $actor['source'] ?? null]);
        return $report;
    }

    private static function summary(array $r): array
    {
        return [
            'type_label' => $r['type']['label'],
            'subject' => $r['subject'],
            'location' => $r['location'],
            'start' => $r['start'],
            'end' => $r['end'],
            'status' => $r['status'],
            'personnel_ids' => array_column($r['personnel'], 'id'),
            'divera_key' => isset($r['divera']) ? $r['divera']['type'] . ':' . $r['divera']['id'] : null,
            'updated_at' => now_iso(),
        ];
    }

    public static function historyEntry(string $action, array $actor, array $extra = []): array
    {
        $e = ['action' => $action, 'by' => $actor['by'], 'at' => now_iso()];
        if (!empty($actor['by_name'])) {
            $e['by_name'] = $actor['by_name'];
        }
        if (!empty($actor['source'])) {
            $e['source'] = $actor['source'];
            if (!empty($actor['qr_label'])) {
                $e['qr_label'] = $actor['qr_label'];
            }
        }
        if (!empty($actor['created_by'])) {
            $e['created_by'] = $actor['created_by'];
        }
        return $e + $extra;
    }

    private static function emptyFields(string $kind): array
    {
        $out = [];
        foreach (Settings::fields($kind) as $f) {
            $out[$f['id']] = null;
        }
        return $out;
    }

    private static function fieldDefinitions(): array
    {
        $defs = [];
        foreach (['report', 'person', 'vehicle'] as $kind) {
            foreach (Settings::fields($kind) as $f) {
                $defs[$f['id']] = ['label' => $f['label'], 'unit' => $f['unit'] ?? '', 'type' => $f['type'], 'scope' => $kind];
            }
        }
        return $defs;
    }

    /* ---------- Speichern ---------- */

    /** Übernimmt die Formulardaten in den gespeicherten Bericht (nur erlaubte Felder, IDs aufgelöst). */
    public static function applyInput(array $report, array $in): array
    {
        $str = static fn ($v, int $max = 500) => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, $max);
        $dt = static function ($v) {
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v)) {
                return null;
            }
            return substr($v, 0, 16);
        };

        $report['start'] = $dt($in['start'] ?? null);
        $report['end'] = $dt($in['end'] ?? null);
        $report['duration_min'] = null;
        if ($report['start'] && $report['end']) {
            $d = (int) round((strtotime($report['end']) - strtotime($report['start'])) / 60);
            $report['duration_min'] = $d >= 0 ? $d : null;
        }
        $report['location'] = $str($in['location'] ?? '');
        $report['subject'] = $str($in['subject'] ?? '');
        $report['narrative'] = $str($in['narrative'] ?? '', 20000);
        $report['defects'] = Settings::moduleOn('defects') ? $str($in['defects'] ?? '', 10000) : '';

        // Alarmierung: Auswahl oder freie Eingabe (bleibt nur im Bericht)
        $report['alert_source'] = null;
        $as = $in['alert_source'] ?? null;
        if (Settings::moduleOn('alert_source') && is_array($as)) {
            if (!empty($as['id']) && ($src = MasterData::alertSource((string) $as['id']))) {
                $report['alert_source'] = $src;
            } elseif (($label = $str($as['label'] ?? '', 200)) !== '') {
                $report['alert_source'] = ['id' => null, 'label' => $label];
            }
        }

        $report['equipment'] = [];
        if (Settings::moduleOn('equipment')) {
            foreach ((array) ($in['equipment'] ?? []) as $id) {
                if (is_string($id) && ($q = MasterData::findEquipment($id))) {
                    $report['equipment'][] = ['id' => $q['id'], 'label' => $q['label']];
                }
            }
        }

        // Personal inkl. Personenfelder
        $personFields = Settings::fields('person');
        $known = [];
        foreach ($report['personnel'] as $p) {
            $known[$p['id']] = $p;
        }
        $report['personnel'] = [];
        $seen = [];
        if (Settings::moduleOn('personnel')) {
            foreach ((array) ($in['personnel'] ?? []) as $p) {
                if (!is_array($p) || empty($p['id']) || !is_string($p['id']) || isset($seen[$p['id']])) {
                    continue;
                }
                $seen[$p['id']] = true;
                $found = MasterData::findPerson($p['id']) ?? $known[$p['id']] ?? null;
                $name = $found['name'] ?? $str($p['name'] ?? '', 120);
                if ($name === '') {
                    continue;
                }
                $entry = ['id' => $p['id'], 'name' => $name];
                $diveraId = $found['divera_id'] ?? ($known[$p['id']]['divera_id'] ?? ($p['divera_id'] ?? null));
                if ($diveraId !== null && is_numeric($diveraId)) {
                    $entry['divera_id'] = (int) $diveraId;
                }
                $entry['fields'] = self::cleanFields($personFields, (array) ($p['fields'] ?? []));
                $entry += self::cleanPersonTimes($p);
                $report['personnel'][] = $entry;
            }
        }
        $lead = is_string($in['lead'] ?? null) ? $in['lead'] : null;
        $report['lead'] = ($lead !== null && isset($seen[$lead])) ? $lead : null;

        if (Settings::moduleOn('vehicles')) {
            $vehicleFields = Settings::fields('vehicle');
            $knownV = [];
            foreach ($report['vehicles'] ?? [] as $v) {
                $knownV[$v['id']] = $v;
            }
            $report['vehicles'] = [];
            $seenV = [];
            foreach ((array) ($in['vehicles'] ?? []) as $v) {
                if (!is_array($v) || empty($v['id']) || !is_string($v['id']) || isset($seenV[$v['id']])) {
                    continue;
                }
                $seenV[$v['id']] = true;
                $found = MasterData::findVehicle($v['id']) ?? $knownV[$v['id']] ?? null;
                if (!$found) {
                    continue;
                }
                $report['vehicles'][] = [
                    'id' => $found['id'],
                    'label' => $found['label'],
                    'source' => $found['source'] ?? 'local',
                    'fields' => self::cleanFields($vehicleFields, (array) ($v['fields'] ?? [])),
                ];
            }
        }

        $report['custom_fields'] = self::cleanFields(Settings::fields('report'), (array) ($in['custom_fields'] ?? [])) ?: new \stdClass();

        // Bildanhänge: Hochladen läuft über addAttachment(); hier nur Beschriftungen und Entfernen (fehlt in der Liste = entfernt)
        if (Settings::moduleOn('attachments') && is_array($in['attachments'] ?? null)) {
            $captions = [];
            foreach ($in['attachments'] as $a) {
                if (is_array($a) && is_string($a['file'] ?? null)) {
                    $captions[$a['file']] = $str($a['caption'] ?? '', self::ATTACHMENT_CAPTION_MAX);
                }
            }
            $kept = [];
            foreach ($report['attachments'] ?? [] as $a) {
                if (isset($captions[$a['file']])) {
                    $kept[] = ['caption' => $captions[$a['file']]] + $a;
                }
            }
            $report['attachments'] = $kept;
        }

        if (array_key_exists('created_by', $report) || isset($in['created_by'])) {
            $cb = is_string($in['created_by'] ?? null) ? MasterData::findPerson($in['created_by']) : null;
            $report['created_by'] = $cb ? ['id' => $cb['id'], 'name' => $cb['name']] : null;
        }

        $report['field_definitions'] = array_merge($report['field_definitions'] ?? [], self::fieldDefinitions());
        return $report;
    }

    /** Abweichende Zeit einer Person: nur exakt `YYYY-MM-DDTHH:MM`; auch Werte gleich Beginn/Ende bleiben erhalten. */
    public static function cleanPersonTimes(array $p): array
    {
        $out = [];
        foreach (['from', 'to'] as $k) {
            $v = $p[$k] ?? null;
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) {
                continue;
            }
            $d = \DateTime::createFromFormat('!Y-m-d\TH:i', $v);
            if (!$d || $d->format('Y-m-d\TH:i') !== $v) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /** Fehlertext, wenn die abweichende Zeit nicht passt (fehlende Angabe = Beginn/Ende des Berichts). */
    public static function personTimeIssue(array $p, ?string $start, ?string $end): ?string
    {
        if (!isset($p['from']) && !isset($p['to'])) {
            return null;
        }
        $from = $p['from'] ?? $start;
        $to = $p['to'] ?? $end;
        // gleiches Format → Textvergleich genügt
        if (($start && $from < $start) || ($end && ($from > $end || ($to && $to > $end))) || ($start && $to && $to < $start)) {
            return 'Abweichende Zeit bei ' . $p['name'] . ' liegt außerhalb von Beginn und Ende';
        }
        if ($from && $to && $from >= $to) {
            return 'Abweichende Zeit bei ' . $p['name'] . ': „Gekommen“ muss vor „Gegangen“ liegen';
        }
        return null;
    }

    /** Anzeige der abweichenden Zeit, z. B. „18:30–23:10“ oder „03.11. 22:00–04.11. 01:30“; '' ohne Angabe. */
    public static function personTimeText(array $p, ?string $start, ?string $end): string
    {
        if (!isset($p['from']) && !isset($p['to'])) {
            return '';
        }
        $from = $p['from'] ?? $start;
        $to = $p['to'] ?? $end;
        $day = substr((string) $start, 0, 10);
        $withDate = ($from && substr($from, 0, 10) !== $day) || ($to && substr($to, 0, 10) !== $day);
        $f = static fn (string $v) => ($withDate ? substr($v, 8, 2) . '.' . substr($v, 5, 2) . '. ' : '') . substr($v, 11, 5);
        if (!$from) {
            return 'bis ' . $f($to);
        }
        return $to ? $f($from) . '–' . $f($to) : 'ab ' . $f($from);
    }

    private static function cleanFields(array $defs, array $values): array
    {
        $out = [];
        foreach ($defs as $f) {
            $v = $values[$f['id']] ?? null;
            $out[$f['id']] = match ($f['type']) {
                'number' => (is_numeric($v) ? (str_contains((string) $v, '.') ? (float) $v : (int) $v) : null),
                'checkbox' => ($v === null ? null : (bool) $v),
                'date' => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null),
                'select' => (is_string($v) && in_array($v, (array) ($f['options'] ?? []), true) ? $v : null),
                default => (is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, 2000) : null),
            };
        }
        return $out;
    }

    /**
     * Zwischenspeichern eines Entwurfs. Prüft die Revision, damit niemand fremde Änderungen überschreibt.
     * @return array{ok: bool, rev?: int, error?: string, conflict?: bool}
     */
    public static function saveDraft(string $number, array $input, int $rev, array $actor): array
    {
        return Storage::locked('report-' . $number, static function () use ($number, $input, $rev, $actor) {
            $meta = self::meta($number);
            $report = self::load($number);
            if (!$meta || !$report) {
                return ['ok' => false, 'error' => 'Bericht nicht gefunden.'];
            }
            if ($report['status'] !== 'draft') {
                return ['ok' => false, 'error' => 'Bericht ist bereits abgeschlossen.', 'closed' => true];
            }
            if ($rev !== (int) $meta['rev']) {
                Log::warning('report', 'save_conflict', $number . ': Revision veraltet', ['client_rev' => $rev, 'server_rev' => (int) $meta['rev'], 'last_editor' => $meta['last_editor'] ?? null]);
                return ['ok' => false, 'conflict' => true, 'error' => 'Der Bericht wurde inzwischen auf einem anderen Gerät geändert.'];
            }
            $before = $report;
            $report = self::applyInput($report, $input);
            self::persist(self::jsonPath($meta), $report);
            self::deleteRemovedAttachments($meta, $before, $report);
            $newRev = (int) $meta['rev'] + 1;
            self::updateIndex($number, ['rev' => $newRev, 'last_editor' => $actor['by']] + self::summary($report));
            return ['ok' => true, 'rev' => $newRev, 'saved_at' => now_iso()];
        });
    }

    /** Divera-Daten in den gespeicherten Entwurf übernehmen. */
    public static function applyDivera(string $number, array $import, array $actor): array
    {
        return Storage::locked('report-' . $number, static function () use ($number, $import, $actor) {
            $meta = self::meta($number);
            $report = self::load($number);
            if (!$meta || !$report || $report['status'] !== 'draft') {
                return ['ok' => false, 'error' => 'Bericht nicht gefunden oder abgeschlossen.'];
            }
            foreach (['start', 'end', 'subject', 'location', 'alert_source'] as $k) {
                if (!empty($import[$k])) {
                    $report[$k] = $import[$k];
                }
            }
            if ($report['start'] && $report['end']) {
                $d = (int) round((strtotime($report['end']) - strtotime($report['start'])) / 60);
                $report['duration_min'] = $d >= 0 ? $d : null;
            }
            $ids = array_column($report['personnel'], 'id');
            foreach ($import['personnel'] as $p) {
                if (!in_array($p['id'], $ids, true)) {
                    $report['personnel'][] = $p + ['fields' => self::emptyFields('person')];
                    $ids[] = $p['id'];
                }
            }
            if (Settings::moduleOn('vehicles')) {
                $vids = array_column($report['vehicles'] ?? [], 'id');
                foreach ($import['vehicles'] as $v) {
                    if (!in_array($v['id'], $vids, true)) {
                        $report['vehicles'][] = $v + ['fields' => self::emptyFields('vehicle')];
                    }
                }
            }
            $report['divera'] = $import['divera'];
            Log::info('report', 'divera_imported', $number . ': Divera-Daten übernommen', ['divera' => $import['divera']['type'] . ':' . $import['divera']['id'], 'persons' => count($import['personnel']), 'vehicles' => count($import['vehicles'])]);
            $report['history'][] = self::historyEntry('divera_imported', $actor, ['divera_id' => $import['divera']['id']]);
            self::persist(self::jsonPath($meta), $report);
            $newRev = (int) $meta['rev'] + 1;
            self::updateIndex($number, ['rev' => $newRev] + self::summary($report));
            return ['ok' => true, 'rev' => $newRev, 'report' => $report];
        });
    }

    /* ---------- Prüfen & Abschließen ---------- */

    public static function requiredLabels(): array
    {
        return [
            'type' => 'Berichtsart',
            'start' => 'Beginn',
            'end' => 'Ende',
            'location' => 'Ort',
            'subject' => 'Thema / Stichwort',
            'alert_source' => 'Alarmierung durch',
            'narrative' => 'Bericht / Maßnahmen',
            'personnel' => 'mindestens eine Person',
            'lead' => 'Einheitsführer',
            'equipment' => 'mindestens ein Gerät',
            'vehicles' => 'mindestens ein Fahrzeug',
        ];
    }

    /** @return array<string,string> fehlende Pflichtangaben: Schlüssel → Text */
    public static function validate(array $r, bool $quickEntry = false): array
    {
        $missing = [];
        $labels = self::requiredLabels();
        foreach (Settings::unit()['required_fields'] as $f) {
            $empty = match ($f) {
                'type' => empty($r['type']['id']),
                'alert_source' => Settings::moduleOn('alert_source') && empty($r['alert_source']),
                'personnel' => Settings::moduleOn('personnel') && empty($r['personnel']),
                'lead' => Settings::moduleOn('personnel') && empty($r['lead']),
                'equipment' => Settings::moduleOn('equipment') && empty($r['equipment']),
                'vehicles' => Settings::moduleOn('vehicles') && empty($r['vehicles']),
                default => trim((string) ($r[$f] ?? '')) === '',
            };
            if ($empty && isset($labels[$f])) {
                $missing[$f] = $labels[$f];
            }
        }
        if (!empty($r['start']) && !empty($r['end']) && strtotime($r['end']) < strtotime($r['start'])) {
            $missing['end'] = 'Ende liegt vor dem Beginn';
        }
        foreach (Settings::fields('report') as $f) {
            if (!empty($f['required']) && ($r['custom_fields'][$f['id']] ?? null) === null) {
                $missing['cf_' . $f['id']] = $f['label'];
            }
        }
        foreach (Settings::fields('person') as $f) {
            if (!empty($f['required'])) {
                foreach ($r['personnel'] as $p) {
                    if (($p['fields'][$f['id']] ?? null) === null) {
                        $missing['pf_' . $f['id']] = $f['label'] . ' bei ' . $p['name'];
                        break;
                    }
                }
            }
        }
        foreach (Settings::fields('vehicle') as $f) {
            if (!empty($f['required'])) {
                foreach ($r['vehicles'] ?? [] as $v) {
                    if (($v['fields'][$f['id']] ?? null) === null) {
                        $missing['vf_' . $f['id']] = $f['label'] . ' bei ' . $v['label'];
                        break;
                    }
                }
            }
        }
        foreach ($r['personnel'] as $p) {
            if (($issue = self::personTimeIssue($p, $r['start'] ?? null, $r['end'] ?? null)) !== null) {
                $missing['ptime'] = $issue;
                break;
            }
        }
        if ($quickEntry && empty($r['created_by'])) {
            $missing['created_by'] = 'Erstellt durch';
        }
        return $missing;
    }

    /**
     * Abschließen (auch Korrektur eines abgeschlossenen Berichts): JSON + PDF schreiben, Mail senden.
     * @return array{ok: bool, missing?: array, error?: string, mail?: array, correction?: int}
     */
    public static function close(string $number, array $input, ?int $rev, array $actor, bool $quickEntry = false): array
    {
        $result = Storage::locked('report-' . $number, static function () use ($number, $input, $rev, $actor, $quickEntry) {
            $meta = self::meta($number);
            $report = self::load($number);
            if (!$meta || !$report) {
                return ['ok' => false, 'error' => 'Bericht nicht gefunden.'];
            }
            // in der Sperre prüfen: zwei parallele QR-Abschlüsse dürfen nicht als Korrektur durchgehen
            if ($quickEntry && $report['status'] !== 'draft') {
                return ['ok' => false, 'error' => 'Korrekturen sind nur nach Anmeldung möglich.'];
            }
            // auch bei Korrekturen: veralteter Stand überschreibt sonst still eine andere Korrektur bzw. den Abschluss eines anderen Geräts
            if ($rev !== null && $rev !== (int) $meta['rev']) {
                return ['ok' => false, 'conflict' => true, 'error' => $report['status'] === 'closed'
                    ? 'Der Bericht wurde inzwischen auf einem anderen Gerät abgeschlossen oder korrigiert.'
                    : 'Der Bericht wurde inzwischen auf einem anderen Gerät geändert.'];
            }
            $wasClosed = $report['status'] === 'closed';
            $before = $report;
            $report = self::applyInput($report, $input);
            $missing = self::validate($report, $quickEntry);
            if ($missing) {
                Log::debug('report', 'close_incomplete', $number . ': Pflichtangaben fehlen', ['missing' => array_keys($missing)]);
                return ['ok' => false, 'missing' => $missing];
            }
            $correction = 0;
            if ($wasClosed) {
                $correction = count(array_filter($report['history'], static fn ($h) => $h['action'] === 'corrected')) + 1;
                $report['history'][] = self::historyEntry('corrected', $actor, ['correction' => $correction]);
            } else {
                $report['history'][] = self::historyEntry('closed', $actor);
            }
            $report['status'] = 'closed';
            self::persist(self::jsonPath($meta), $report);
            self::deleteRemovedAttachments($meta, $before, $report);
            $newRev = (int) $meta['rev'] + 1;
            self::updateIndex($number, [
                'rev' => $newRev,
                'closed_at' => now_iso(),
                'closed_by' => $actor['by_name'] ?? $actor['by'],
                'correction' => $correction,
            ] + self::summary($report));
            Log::info('report', $wasClosed ? 'corrected' : 'closed', $number . ($wasClosed ? ' korrigiert (' . $correction . ')' : ' abgeschlossen'), ['by' => $actor['by_name'] ?? $actor['by'], 'source' => $actor['source'] ?? null, 'persons' => count($report['personnel'])]);
            // PDF noch in der Sperre: eine parallele Korrektur kann sonst ein PDF zu einem anderen JSON-Stand hinterlassen.
            // Bei Fehlern bleibt das JSON gespeichert. Die Mail (SMTP kann dauern) geht erst nach der Sperre raus.
            $pdf = true;
            try {
                Pdf::writeReport($report, self::pdfPath($meta));
            } catch (\Throwable $e) {
                Log::error('pdf', 'failed', $e->getMessage(), ['report' => $number, 'file' => $e->getFile() . ':' . $e->getLine()]);
                $pdf = false;
            }
            return ['ok' => true, 'pdf' => $pdf, 'correction' => $correction];
        });
        if (!$result['ok']) {
            return $result;
        }
        if (!$result['pdf']) {
            return ['ok' => true, 'pdf' => false, 'mail' => ['status' => 'failed', 'error' => 'PDF konnte nicht erzeugt werden.'], 'correction' => $result['correction']];
        }
        $mail = self::sendMail($number, $result['correction'], $actor);
        return ['ok' => true, 'pdf' => true, 'mail' => $mail, 'correction' => $result['correction']];
    }

    /** Versand (auch „Erneut senden“). Jeder Versuch steht in der history. */
    public static function sendMail(string $number, int $correction, array $actor): array
    {
        $meta = self::meta($number);
        $report = self::load($number);
        if (!$meta || !$report || $report['status'] !== 'closed') {
            return ['status' => 'failed', 'error' => 'Bericht nicht abgeschlossen.'];
        }
        if (!Mailer::enabled()) {
            self::updateIndex($number, ['mail' => ['status' => 'off', 'at' => now_iso()]]);
            return ['status' => 'off'];
        }
        $res = Mailer::sendReport($report, self::pdfPath($meta), Storage::path(self::jsonPath($meta)), self::fileBase(Settings::reportType($report['type']['id']) ?? $report['type'], $number), $correction);
        Storage::locked('report-' . $number, static function () use ($number, $res, $actor) {
            $meta = self::meta($number);
            $report = self::load($number);
            $report['history'][] = self::historyEntry($res['success'] ? 'mail_sent' : 'mail_failed', $actor, array_filter([
                'to' => $res['to'],
                'success' => $res['success'],
                'error' => $res['error'] ?? null,
            ], static fn ($v) => $v !== null));
            self::persist(self::jsonPath($meta), $report);
        });
        $status = $res['success'] ? 'sent' : 'failed';
        self::updateIndex($number, ['mail' => ['status' => $status, 'at' => now_iso(), 'to' => $res['to'], 'error' => $res['error'] ?? null]]);
        return ['status' => $status, 'to' => $res['to'], 'error' => $res['error'] ?? null];
    }

    /* ---------- Bildanhänge ---------- */

    /** Pfad einer Anlage im Berichtsordner; der Dateiname kommt aus dem Bericht, wird aber trotzdem geprüft. */
    private static function attachmentFile(array $meta, string $file): string
    {
        return Storage::path('reports/' . dirname($meta['file']) . '/' . (preg_match('/^[A-Za-z0-9._-]+_attachment_[0-9a-f]{12}\.(jpg|png)$/', $file) ? $file : 'invalid'));
    }

    /** Datei einer Anlage, wenn sie zum Bericht gehört und existiert (Auslieferung, PDF). */
    public static function attachmentPath(array $meta, array $report, string $file): ?string
    {
        if (!in_array($file, array_column($report['attachments'] ?? [], 'file'), true)) {
            return null;
        }
        $path = self::attachmentFile($meta, $file);
        return is_file($path) ? $path : null;
    }

    private static function deleteRemovedAttachments(array $meta, array $before, array $after): void
    {
        $keep = array_column($after['attachments'] ?? [], 'file');
        foreach ($before['attachments'] ?? [] as $a) {
            if (!in_array($a['file'], $keep, true)) {
                @unlink(self::attachmentFile($meta, $a['file']));
                Log::info('report', 'attachment_removed', $after['report_number'] . ': Bildanhang entfernt', ['file' => $a['file']]);
            }
        }
    }

    /**
     * Bild zu einem Entwurf (oder einer Korrektur) hinzufügen. $upload ist der $_FILES-Eintrag.
     * @return array{ok: bool, attachment?: array, error?: string}
     */
    public static function addAttachment(string $number, ?array $upload, string $caption, array $actor, bool $quickEntry, int $rev): array
    {
        return Storage::locked('report-' . $number, static function () use ($number, $upload, $caption, $actor, $quickEntry, $rev) {
            $meta = self::meta($number);
            $report = self::load($number);
            if (!$meta || !$report) {
                return ['ok' => false, 'error' => 'Bericht nicht gefunden.'];
            }
            if (!Settings::moduleOn('attachments')) {
                return ['ok' => false, 'error' => 'Bildanhänge sind nicht eingeschaltet.'];
            }
            if ($report['status'] !== 'draft') {
                return ['ok' => false, 'error' => 'Bilder können nur zu Entwürfen hinzugefügt werden.'];
            }
            // wie bei save/close: veralteter Stand darf nicht neben einer anderen Änderung weiterarbeiten
            if ($rev !== (int) $meta['rev']) {
                return ['ok' => false, 'conflict' => true, 'error' => 'Der Bericht wurde inzwischen auf einem anderen Gerät geändert.'];
            }
            if (count($report['attachments'] ?? []) >= self::MAX_ATTACHMENTS) {
                return ['ok' => false, 'error' => 'Höchstens ' . self::MAX_ATTACHMENTS . ' Bilder pro Bericht.'];
            }
            $err = is_array($upload) ? (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($err === UPLOAD_ERR_OK && (int) $upload['size'] > self::MAX_ATTACHMENT_BYTES)) {
                return ['ok' => false, 'error' => 'Bild zu groß (max. ' . (self::MAX_ATTACHMENT_BYTES >> 20) . ' MB).'];
            }
            $tmp = $err === UPLOAD_ERR_OK ? (string) $upload['tmp_name'] : '';
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                return ['ok' => false, 'error' => 'Keine Datei empfangen.'];
            }
            $mime = class_exists(\finfo::class) ? (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
            $size = $mime === 'image/jpeg' || $mime === 'image/png' ? @getimagesize($tmp) : false;
            // Obergrenze der Kantenlänge schützt den Speicher beim PDF-Erzeugen (der Browser verkleinert auf 2000 px)
            if (!$size || $size[0] < 1 || $size[1] < 1 || max($size[0], $size[1]) > 6000 || $size[0] * $size[1] > 12_000_000) {
                return ['ok' => false, 'error' => 'Nur Bilder (JPEG oder PNG) bis 6000 Pixel Kantenlänge und 12 Megapixel sind erlaubt.'];
            }
            $file = basename($meta['file']) . '_attachment_' . bin2hex(random_bytes(6)) . ($mime === 'image/png' ? '.png' : '.jpg');
            $target = self::attachmentFile($meta, $file);
            if (!move_uploaded_file($tmp, $target)) {
                return ['ok' => false, 'error' => 'Bild konnte nicht gespeichert werden.'];
            }
            $entry = [
                'file' => $file,
                'caption' => mb_substr(trim($caption), 0, self::ATTACHMENT_CAPTION_MAX),
                'uploaded_at' => now_iso(),
                'uploaded_by' => $actor['by'],
            ];
            $report['attachments'][] = $entry;
            self::persist(self::jsonPath($meta), $report);
            Log::info('report', 'attachment_added', $number . ': Bildanhang hinzugefügt', ['file' => $file, 'kb' => (int) round(filesize($target) / 1024), 'by' => $actor['by'], 'source' => $actor['source'] ?? null]);
            $newRev = (int) $meta['rev'] + 1;
            self::updateIndex($number, ['rev' => $newRev, 'last_editor' => $actor['by']] + self::summary($report));
            return ['ok' => true, 'attachment' => $entry, 'rev' => $newRev];
        });
    }

    public static function discardDraft(string $number): bool
    {
        return Storage::locked('report-' . $number, static function () use ($number) {
            $meta = self::meta($number);
            if (!$meta || $meta['status'] !== 'draft') {
                return false;
            }
            foreach (self::load($number)['attachments'] ?? [] as $a) {
                @unlink(self::attachmentFile($meta, $a['file']));
            }
            @unlink(Storage::path(self::jsonPath($meta)));
            Log::info('report', 'discarded', $number . ': Entwurf verworfen');
            self::updateIndex($number, null);
            self::releaseNumber($meta + ['number' => $number]);
            return true;
        });
    }

    /* ---------- Übersicht ---------- */

    public static function list(array $filter = []): array
    {
        $rows = array_values(self::index());
        $rows = array_filter($rows, static function ($r) use ($filter) {
            if (!empty($filter['year']) && (int) $r['year'] !== (int) $filter['year']) {
                return false;
            }
            if (!empty($filter['type']) && $r['type'] !== $filter['type']) {
                return false;
            }
            if (!empty($filter['status']) && $r['status'] !== $filter['status']) {
                return false;
            }
            if (!empty($filter['q'])) {
                $hay = mb_strtolower($r['number'] . ' ' . $r['subject'] . ' ' . $r['location']);
                if (!str_contains($hay, mb_strtolower($filter['q']))) {
                    return false;
                }
            }
            return true;
        });
        usort($rows, static fn ($a, $b) => strcmp((string) ($b['start'] ?? $b['created_at']), (string) ($a['start'] ?? $a['created_at'])));
        return $rows;
    }

    public static function years(): array
    {
        $y = array_unique(array_map(static fn ($r) => (int) $r['year'], self::index()));
        rsort($y);
        return $y ?: [(int) date('Y')];
    }

    /** 'alarm:123' → Berichtsnummer (damit kein doppelter Bericht entsteht) */
    public static function diveraImports(): array
    {
        $out = [];
        foreach (self::index() as $r) {
            if (!empty($r['divera_key'])) {
                $out[$r['divera_key']] = $r['number'];
            }
        }
        return $out;
    }

    public static function subjectSuggestions(): array
    {
        $s = [];
        foreach (self::index() as $r) {
            if (!empty($r['subject'])) {
                $s[$r['subject']] = ($s[$r['subject']] ?? 0) + 1;
            }
        }
        arsort($s);
        return array_slice(array_keys($s), 0, 50);
    }

    /** Index aus den Dateien neu aufbauen (z. B. nach Rückspielen einer Sicherung). */
    public static function rebuildIndex(): int
    {
        $old = self::index();
        $idx = [];
        foreach (glob(Storage::path('reports/*/*.json')) ?: [] as $file) {
            $r = json_decode((string) file_get_contents($file), true);
            if (!is_array($r) || empty($r['report_number'])) {
                continue;
            }
            $n = $r['report_number'];
            $year = basename(dirname($file));
            $idx[$n] = array_merge($old[$n] ?? ['rev' => 1, 'created_at' => $r['history'][0]['at'] ?? now_iso()], [
                'number' => $n,
                'year' => (int) $year,
                'type' => $r['type']['id'],
                'file' => $year . '/' . basename($file, '.json'),
            ], self::summary($r));
        }
        Storage::locked('index', static fn () => Storage::write(self::INDEX, $idx));
        return count($idx);
    }
}
