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
            'app_name' => '', // Name unter dem Symbol auf dem Startbildschirm (leer = EÜDOK)
            'accent_color' => '#0f6f68',
            'public_url' => '',
            'timezone' => 'Europe/Berlin',
            // Fußzeile: left/center/right (siehe Footer); leer = Standard, alte Felder werden übernommen
            'footer' => [],
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
                'person_times' => true,
                'attachments' => false,
            ],
            'required_fields' => ['type', 'start', 'end', 'location', 'subject', 'personnel', 'lead'],
            'defects_hint' => 'Defekte sind sofort dem Leiter zu melden.',
            'remember_days' => 30,
            'log_level' => 'info',
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
            'vehicle_source' => 'local',
            'attendance_statuses' => [],
            'event_accept_counts' => true,
            'event_accept_ids' => ['1'],
            'default_alert_source' => ['incident' => '', 'exercise' => ''],
            'timeout_seconds' => 3,
        ];
    }

    /** Einsatzbestätigung: CC, Mailtext, Vorlage (PDF unter data/templates/) und Feldpositionen in mm. */
    public static function confirmation(): array
    {
        return [
            'cc' => [],
            'subject' => '',
            'body' => "Hallo {vorname} {nachname},\n\n"
                . "anbei deine Bestätigung für {berichtsart} {berichtsnummer} am {datum}.\n\n"
                . 'im Auftrag EÜDOK von {einheit}',
            'templates' => [], // je Vorlage: id, label, file (templates/…pdf), name (Originalname), pages, uploaded_at; Felder gelten für alle
            'default_template' => null, // id der Standardvorlage
            'fields' => [], // je Feld: text, page, x, y, w, size, bold, align (L|C|R), lines
        ];
    }

    public static function users(): array
    {
        return ['users' => [], 'admins_require_tfa' => false];
    }

    public static function qrAccess(): array
    {
        return ['entries' => []];
    }
}
