<?php
declare(strict_types=1);

namespace Euedok;

/** Speichert die Bereiche der Einstellungsseite. Jede Änderung landet im Änderungsprotokoll. */
final class SettingsPage
{
    public const SECTIONS = [
        'unit' => ['Einheit', 'shield'],
        'types' => ['Berichtsarten & Nummern', 'file'],
        'modules' => ['Pflichtfelder & Module', 'check'],
        'fields' => ['Zusatzfelder', 'sliders'],
        'personnel' => ['Personal', 'users'],
        'equipment' => ['Geräte', 'box'],
        'vehicles' => ['Fahrzeuge', 'truck'],
        'alerts' => ['Alarmierung', 'radio'],
        'mail' => ['Mailversand', 'mail'],
        'divera' => ['Divera247', 'radio'],
        'users' => ['Zugänge', 'user'],
        'qr' => ['QR-Zugänge', 'qr'],
        'logs' => ['Protokolle', 'server'],
        'audit' => ['Änderungsprotokoll', 'list'],
    ];

    /** Welche Schlüssel haben sich geändert? (für das Protokoll, ohne Werte von Geheimnissen) */
    private static function diff(array $old, array $new): string
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
            if (json_encode($old[$k] ?? null) !== json_encode($new[$k] ?? null)) {
                $changed[] = $k;
            }
        }
        return $changed ? implode(', ', $changed) : 'keine Änderung';
    }

    private static function rows(string $name): array
    {
        $rows = $_POST[$name] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private static function s(array $row, string $k, int $max = 200): string
    {
        return mb_substr(trim((string) ($row[$k] ?? '')), 0, $max);
    }

    /** @return string Meldung */
    public static function save(string $section): string
    {
        return match ($section) {
            'unit' => self::saveUnit(),
            'types' => self::saveTypes(),
            'modules' => self::saveModules(),
            'fields' => self::saveFields(),
            'personnel' => self::saveList('personnel', 'name', [], 'Personal'),
            'equipment' => self::saveList('equipment', 'label', [], 'Geräte'),
            'vehicles' => self::saveVehicles(),
            'alerts' => self::saveList('alert_sources', 'label', [], 'Alarmierungsquellen'),
            'mail' => self::saveMail(),
            'divera' => self::saveDivera(),
            'users' => self::saveUsers(),
            'logs' => self::saveLogLevel(),
            default => 'Unbekannter Bereich.',
        };
    }

    private static function saveUnit(): string
    {
        $old = Settings::unit();
        $u = $old;
        $u['name'] = post_str('name') ?: $old['name'];
        $u['subtitle'] = post_str('subtitle');
        $color = post_str('accent_color');
        $u['accent_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $old['accent_color'];
        $url = post_str('public_url');
        $u['public_url'] = ($url === '' || filter_var($url, FILTER_VALIDATE_URL)) ? rtrim($url, '/') : $old['public_url'];
        $u['footer'] = [];
        foreach (array_keys(Footer::POSITIONS) as $pos) {
            $u['footer'][$pos] = Footer::clean((string) ($_POST['footer_' . $pos] ?? ''));
        }
        $u['remember_days'] = max(1, min(365, (int) post_str('remember_days', '30')));
        $u['defects_hint'] = post_str('defects_hint');
        $tz = post_str('timezone');
        $u['timezone'] = in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : $old['timezone'];
        Settings::save('unit', $u, 'Einheit: ' . self::diff($old, $u));
        return 'Einheit gespeichert.';
    }

    private static function saveLogLevel(): string
    {
        $u = Settings::unit();
        $level = in_array(post_str('log_level'), ['debug', 'info', 'warning', 'error'], true) ? post_str('log_level') : 'info';
        $old = $u['log_level'] ?? 'info';
        $u['log_level'] = $level;
        Settings::save('unit', $u, 'Protokollstufe: ' . $old . ' → ' . $level);
        return 'Protokollstufe gespeichert.';
    }

    private static function saveTypes(): string
    {
        $old = Settings::unit();
        $existing = [];
        foreach ($old['report_types'] as $t) {
            $existing[$t['id']] = $t;
        }
        $types = [];
        foreach (self::rows('types') as $row) {
            $label = self::s($row, 'label', 40);
            if ($label === '') {
                continue;
            }
            $id = self::s($row, 'id', 40);
            if (!isset($existing[$id])) {
                $id = unique_id(slugify(self::s($row, 'new_id', 40) ?: $label), array_merge(array_keys($existing), array_column($types, 'id')));
            }
            $fmt = self::s($row, 'number_format', 40);
            if (!preg_match('/\{N+\}/', $fmt)) {
                $fmt = strtoupper(mb_substr(ascii_name($label), 0, 1)) . '-{YYYY}-{NNN}';
            }
            $types[] = [
                'id' => $id,
                'label' => $label,
                'number_format' => $fmt,
                'lead_label' => self::s($row, 'lead_label', 40) ?: 'Leitung',
                'style' => ($row['style'] ?? '') === 'alert' ? 'alert' : 'calm',
                'divera_source' => in_array($row['divera_source'] ?? '', ['alarm', 'event'], true) ? $row['divera_source'] : null,
                'active' => !empty($row['active']),
            ];
        }
        if (!array_filter($types, static fn ($t) => $t['active'])) {
            return 'Mindestens eine Berichtsart muss aktiv sein – nicht gespeichert.';
        }
        // Berichtsarten werden nie gelöscht, damit alte Berichte lesbar bleiben
        foreach ($existing as $id => $t) {
            if (!in_array($id, array_column($types, 'id'), true)) {
                $t['active'] = false;
                $types[] = $t;
            }
        }
        $u = $old;
        $u['report_types'] = $types;
        Settings::save('unit', $u, 'Berichtsarten: ' . count($types) . ' (' . implode(', ', array_column($types, 'label')) . ')');
        return 'Berichtsarten gespeichert.';
    }

    private static function saveModules(): string
    {
        $old = Settings::unit();
        $u = $old;
        foreach (array_keys(Defaults::unit()['modules']) as $m) {
            $u['modules'][$m] = post_bool('mod_' . $m);
        }
        $req = [];
        foreach (array_keys(Reports::requiredLabels()) as $f) {
            if (post_bool('req_' . $f)) {
                $req[] = $f;
            }
        }
        $u['required_fields'] = $req;
        Settings::save('unit', $u, 'Module/Pflichtfelder: ' . self::diff($old['modules'], $u['modules']) . ' · Pflicht: ' . implode(', ', $req));
        return 'Module und Pflichtfelder gespeichert.';
    }

    private static function saveFields(): string
    {
        $old = Settings::customFields();
        $new = $old;
        $allIds = [];
        foreach (['report', 'person', 'vehicle'] as $kind) {
            foreach ($old[$kind] as $f) {
                $allIds[] = $f['id'];
            }
        }
        foreach (['report', 'person', 'vehicle'] as $kind) {
            $byId = [];
            foreach ($old[$kind] as $f) {
                $byId[$f['id']] = $f;
            }
            $list = [];
            foreach (self::rows('fields_' . $kind) as $row) {
                $label = self::s($row, 'label', 60);
                if ($label === '') {
                    continue;
                }
                $id = self::s($row, 'id', 40);
                if (!isset($byId[$id])) {
                    $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', strtr(self::s($row, 'new_id', 40) ?: $label, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'])));
                    $id = trim($base, '_') ?: 'field';
                    $n = 2;
                    $try = $id;
                    while (in_array($try, $allIds, true)) {
                        $try = $id . '_' . $n++;
                    }
                    $id = $try;
                    $allIds[] = $id;
                }
                $type = in_array($row['type'] ?? '', ['number', 'text', 'select', 'checkbox', 'date'], true) ? $row['type'] : 'text';
                $list[] = [
                    'id' => $id,
                    'label' => $label,
                    'type' => $type,
                    'unit' => self::s($row, 'unit', 12),
                    'options' => $type === 'select' ? array_values(array_filter(array_map('trim', explode(',', self::s($row, 'options', 1000))))) : [],
                    'required' => !empty($row['required']),
                    'show_total' => $type === 'number' && !empty($row['show_total']),
                    'active' => !empty($row['active']),
                ];
            }
            // nie löschen, nur deaktivieren
            foreach ($byId as $id => $f) {
                if (!in_array($id, array_column($list, 'id'), true)) {
                    $f['active'] = false;
                    $list[] = $f;
                }
            }
            $new[$kind] = $list;
        }
        Settings::save('custom-fields', $new, 'Zusatzfelder: ' . self::diff($old, $new));
        return 'Zusatzfelder gespeichert.';
    }

    private static function saveList(string $key, string $labelKey, array $extra, string $title): string
    {
        $old = Settings::masterData();
        $existingIds = array_column($old[$key], 'id');
        $list = [];
        foreach (self::rows($key) as $row) {
            $label = self::s($row, $labelKey, 120);
            if ($label === '') {
                continue;
            }
            $id = self::s($row, 'id', 60);
            if (!in_array($id, $existingIds, true)) {
                $id = unique_id(slugify($label), array_merge($existingIds, array_column($list, 'id')));
            }
            $item = ['id' => $id, $labelKey => $label];
            foreach ($extra as $x) {
                $item[$x] = self::s($row, $x, 60);
            }
            $item['active'] = !empty($row['active']);
            $list[] = $item;
        }
        foreach ($old[$key] as $o) {
            if (!in_array($o['id'], array_column($list, 'id'), true)) {
                $o['active'] = false;
                $list[] = $o;
            }
        }
        $new = $old;
        $new[$key] = $list;
        $active = count(array_filter($list, static fn ($x) => $x['active']));
        Settings::save('master-data', $new, $title . ': ' . $active . ' aktiv, ' . (count($list) - $active) . ' inaktiv');
        return $title . ' gespeichert.';
    }

    private static function saveVehicles(): string
    {
        return self::saveList('vehicles', 'label', ['radio_call_sign', 'license_plate'], 'Fahrzeuge');
    }

    private static function saveMail(): string
    {
        $old = Settings::mail();
        $m = $old;
        $m['enabled'] = post_bool('enabled');
        $m['transport'] = post_str('transport') === 'file' ? 'file' : 'smtp';
        $rcpt = preg_split('/[\s,;]+/', post_str('recipients')) ?: [];
        $m['recipients'] = array_values(array_filter($rcpt, static fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        $from = post_str('from_address');
        $m['from_address'] = ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL)) ? $from : $old['from_address'];
        $m['from_name'] = post_str('from_name') ?: 'EÜDOK';
        $m['smtp_host'] = post_str('smtp_host');
        $m['smtp_port'] = max(1, min(65535, (int) post_str('smtp_port', '587')));
        $m['smtp_security'] = in_array(post_str('smtp_security'), ['tls', 'ssl', 'none'], true) ? post_str('smtp_security') : 'tls';
        $m['smtp_user'] = post_str('smtp_user');
        $pw = (string) ($_POST['smtp_password'] ?? '');
        if ($pw !== '') {
            $m['smtp_password_enc'] = Crypto::encrypt($pw);
        } elseif (post_bool('smtp_password_clear')) {
            $m['smtp_password_enc'] = '';
        }
        $m['subject_template'] = post_str('subject_template') ?: Defaults::mail()['subject_template'];
        $audit = self::diff(array_diff_key($old, ['smtp_password_enc' => 1]), array_diff_key($m, ['smtp_password_enc' => 1]));
        if ($m['smtp_password_enc'] !== $old['smtp_password_enc']) {
            $audit .= ', SMTP-Passwort ersetzt';
        }
        Settings::save('mail', $m, 'Mailversand: ' . $audit);
        $msg = 'Mailversand gespeichert.';
        if ($m['enabled'] && !$m['recipients']) {
            $msg .= ' Achtung: keine gültige Empfängeradresse – es wird nichts versendet.';
        }
        return $msg;
    }

    private static function saveDivera(): string
    {
        $old = Settings::divera();
        $d = $old;
        $d['enabled'] = post_bool('enabled');
        $key = trim((string) ($_POST['api_key'] ?? ''));
        if ($key !== '') {
            $d['api_key_enc'] = Crypto::encrypt($key);
        } elseif (post_bool('api_key_clear')) {
            $d['api_key_enc'] = '';
        }
        $d['window_hours'] = max(1, min(168, (int) post_str('window_hours', '24')));
        $d['incident_enabled'] = post_bool('incident_enabled');
        $d['exercise_enabled'] = post_bool('exercise_enabled');
        $d['quick_entry_enabled'] = post_bool('quick_entry_enabled');
        $d['personnel_source'] = post_str('personnel_source') === 'divera' ? 'divera' : 'local';
        $d['vehicle_source'] = post_str('vehicle_source') === 'divera' ? 'divera' : 'local';
        unset($d['vehicles_from_divera']);
        $d['event_accept_counts'] = post_bool('event_accept_counts');
        $d['event_accept_ids'] = array_values(array_filter(array_map('trim', explode(',', post_str('event_accept_ids')))));
        $d['timeout_seconds'] = max(1, min(10, (int) post_str('timeout_seconds', '3')));
        $d['default_alert_source'] = [
            'incident' => post_str('default_alert_incident'),
            'exercise' => post_str('default_alert_exercise'),
        ];
        // Rückmeldestatus „war dabei“: ID und Bezeichnung speichern
        $labels = $_POST['status_label'] ?? [];
        $statuses = [];
        foreach ((array) ($_POST['attendance'] ?? []) as $sid) {
            $sid = (string) $sid;
            $statuses[] = ['id' => $sid, 'label' => is_array($labels) ? mb_substr(trim((string) ($labels[$sid] ?? '')), 0, 80) : ''];
        }
        // Antwortoptionen am Alarm („JA zur Drohne“) haben eigene IDs – frei eintragbar
        foreach (preg_split('/[\s,;]+/', post_str('attendance_extra')) ?: [] as $extra) {
            if ($extra !== '' && preg_match('/^\d{1,12}$/', $extra) && !in_array($extra, array_column($statuses, 'id'), true)) {
                $statuses[] = ['id' => $extra, 'label' => 'Antwort-ID ' . $extra];
            }
        }
        $d['attendance_statuses'] = $statuses;
        $audit = self::diff(array_diff_key($old, ['api_key_enc' => 1]), array_diff_key($d, ['api_key_enc' => 1]));
        if ($d['api_key_enc'] !== $old['api_key_enc']) {
            $audit .= ', API-Schlüssel ' . ($d['api_key_enc'] === '' ? 'entfernt' : 'ersetzt');
            @unlink(Storage::path('cache/divera-pull.json'));
        }
        Settings::save('divera', $d, 'Divera247: ' . $audit);
        return 'Divera247 gespeichert.';
    }

    private static function saveUsers(): string
    {
        $me = Auth::user();
        $all = Settings::get('users');
        $byId = [];
        foreach ($all['users'] as $u) {
            $byId[$u['id']] = $u;
        }
        $out = [];
        $notes = [];
        foreach (self::rows('users') as $row) {
            $id = self::s($row, 'id', 20);
            $username = strtolower(self::s($row, 'username', 32));
            $display = self::s($row, 'display_name', 80);
            $pw = (string) ($row['password'] ?? '');
            if ($username === '' && $display === '' && !isset($byId[$id])) {
                continue;
            }
            if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
                return 'Benutzername „' . $username . '“ ungültig (3–32 Zeichen: a–z, 0–9, . _ -). Nichts gespeichert.';
            }
            if (in_array($username, array_column($out, 'username'), true)) {
                return 'Benutzername „' . $username . '“ doppelt. Nichts gespeichert.';
            }
            if ($pw !== '' && mb_strlen($pw) < 10) {
                return 'Passwort für „' . $username . '“ zu kurz (mind. 10 Zeichen). Nichts gespeichert.';
            }
            $u = $byId[$id] ?? [
                'id' => bin2hex(random_bytes(6)),
                'password_hash' => '',
                'created_at' => now_iso(),
            ];
            if (!isset($byId[$id]) && $pw === '') {
                return 'Neuer Zugang „' . $username . '“ braucht ein Passwort. Nichts gespeichert.';
            }
            $u['username'] = $username;
            $u['display_name'] = $display ?: $username;
            $u['person_id'] = self::s($row, 'person_id', 60) ?: null;
            $u['can_manage_settings'] = !empty($row['can_manage_settings']);
            $u['active'] = !empty($row['active']);
            if ($u['id'] === $me['id']) {
                // sich selbst nicht aussperren
                $u['can_manage_settings'] = true;
                $u['active'] = true;
            }
            if ($pw !== '') {
                $u['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
                $u['password_changed_at'] = now_iso();
                if ($u['id'] !== $me['id']) {
                    Auth::revokeRememberTokens($u['id']);
                }
                $notes[] = 'Passwort ' . $username;
            }
            if (!$u['active'] && ($byId[$u['id']]['active'] ?? false)) {
                Auth::revokeRememberTokens($u['id']);
            }
            $out[] = $u;
        }
        foreach ($byId as $id => $u) {
            if (!in_array($id, array_column($out, 'id'), true)) {
                $u['active'] = false;
                $out[] = $u;
            }
        }
        Settings::save('users', ['users' => $out], 'Zugänge: ' . implode(', ', array_map(static fn ($u) => $u['username'] . ($u['active'] ? '' : ' (inaktiv)') . ($u['can_manage_settings'] ? ' [Einstellungen]' : ''), $out)) . ($notes ? ' · ' . implode(', ', $notes) : ''));
        return 'Zugänge gespeichert.';
    }
}
