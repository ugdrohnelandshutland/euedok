<?php
declare(strict_types=1);

namespace Euedok;

/**
 * Grundwerte der Einstellungen. Werden nur beim Einrichten geschrieben bzw. ergänzen
 * fehlende Schlüssel – geändert wird alles über die Einstellungsseite.
 */
final class Defaults
{
    public static function unit(): array
    {
        return [
            'name' => '',
            'subtitle' => '',
            'accent_color' => '#0f6f68',
            'public_url' => '',
            'timezone' => 'Europe/Berlin',
            'footer' => ['lead' => '', 'address' => '', 'contact' => ''],
            'report_types' => [
                ['id' => 'incident', 'label' => 'Einsatz', 'number_format' => 'E-{YYYY}-{NNN}', 'lead_label' => 'Einsatzleitung', 'style' => 'alert', 'divera_source' => 'alarm', 'active' => true],
                ['id' => 'exercise', 'label' => 'Übung', 'number_format' => 'U-{YYYY}-{NNN}', 'lead_label' => 'Übungsleitung', 'style' => 'calm', 'divera_source' => 'event', 'active' => true],
            ],
            'modules' => [
                'personnel' => true,
                'equipment' => true,
                'vehicles' => false,
                'defects' => true,
                'alert_source' => true,
            ],
            'required_fields' => ['type', 'start', 'end', 'location', 'subject', 'personnel', 'lead'],
            'defects_hint' => 'Defekte sind sofort dem Leiter zu melden.',
            'remember_days' => 30,
        ];
    }

    public static function masterData(): array
    {
        return ['personnel' => [], 'equipment' => [], 'vehicles' => [], 'alert_sources' => []];
    }

    public static function customFields(): array
    {
        return ['report' => [], 'person' => [], 'vehicle' => []];
    }

    public static function mail(): array
    {
        return [
            'enabled' => false,
            'transport' => 'smtp',
            'recipients' => [],
            'from_address' => '',
            'from_name' => 'EÜDOK',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_security' => 'tls',
            'smtp_user' => '',
            'smtp_password_enc' => '',
            'subject_template' => '[EÜDOK] {type} {number} – {subject} – {date}',
        ];
    }

    public static function divera(): array
    {
        return [
            'enabled' => false,
            'api_key_enc' => '',
            'base_url' => 'https://app.divera247.com',
            'window_hours' => 24,
            'incident_enabled' => true,
            'exercise_enabled' => true,
            'quick_entry_enabled' => true,
            'personnel_source' => 'local',
            'vehicles_from_divera' => false,
            'attendance_statuses' => [],
            'event_accept_counts' => true,
            'event_accept_ids' => ['1'],
            'default_alert_source' => ['incident' => '', 'exercise' => ''],
            'timeout_seconds' => 3,
        ];
    }

    public static function users(): array
    {
        return ['users' => []];
    }

    public static function qrAccess(): array
    {
        return ['entries' => []];
    }

    /** Startwerte UG-Drohne Landshut Land (Konzept Abschnitt 4). */
    public static function presetDrone(): array
    {
        $people = ['Muster, Max', 'Beispiel, Erika', 'Test, Tobias', 'Probe, Paula', 'Dummy, Daniel'];
        $equipment = ['Drohne M30T - 1', 'Drohne M30T - 2', 'Monitor', 'Tablet', 'Notebook', 'Powerstation', 'Suchscheinwerfer', 'Akku-Arbeitsscheinwerfer'];
        $sources = [
            'ils' => 'ILS Landshut',
            'police' => 'Polizei',
            'fire-command' => 'Einsatzleitung Feuerwehr vor Ort',
            'district-fire-inspection' => 'Kreisbrandinspektion',
            'district-office' => 'Landratsamt Landshut',
            'exercise-plan' => 'Übungsplan / eigene Planung',
        ];
        $master = self::masterData();
        foreach ($people as $p) {
            $master['personnel'][] = ['id' => slugify($p), 'name' => $p, 'active' => true];
        }
        foreach ($equipment as $q) {
            $master['equipment'][] = ['id' => slugify($q), 'label' => $q, 'active' => true];
        }
        $master['vehicles'][] = ['id' => 'mtw-ug-drohne', 'label' => 'MTW UG-Drohne', 'radio_call_sign' => '', 'license_plate' => '', 'active' => true];
        foreach ($sources as $id => $label) {
            $master['alert_sources'][] = ['id' => $id, 'label' => $label, 'active' => true];
        }

        $fields = self::customFields();
        $fields['person'][] = ['id' => 'flight_minutes', 'label' => 'Flugminuten', 'type' => 'number', 'unit' => 'min', 'options' => [], 'required' => false, 'show_total' => true, 'active' => true];
        $fields['vehicle'][] = ['id' => 'distance_km', 'label' => 'Kilometer', 'type' => 'number', 'unit' => 'km', 'options' => [], 'required' => false, 'show_total' => true, 'active' => true];

        $divera = self::divera();
        $divera['default_alert_source'] = ['incident' => 'ils', 'exercise' => 'exercise-plan'];

        return ['master-data' => $master, 'custom-fields' => $fields, 'divera' => $divera];
    }
}
