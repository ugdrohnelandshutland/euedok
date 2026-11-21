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
        // text_blocks nur bei fehlendem Schlüssel gesät (Settings::merge): eine bewusst geleerte Liste bleibt leer
        return ['personnel' => [], 'equipment' => [], 'vehicles' => [], 'alert_sources' => [], 'text_blocks' => self::textBlocks()];
    }

    /** Standard-Textbausteine für „Bericht / getroffene Maßnahmen“. */
    public static function textBlocks(): array
    {
        return [
            ['id' => 'tb-vu-2-pkw-nur-betriebsmittel', 'name' => 'VU 2 PKW, nur Betriebsmittel', 'group' => 'Technische Hilfe', 'text' => 'Verkehrsunfall mit 2 beteiligten PKW. Keine eingeklemmten Personen. Einsatzstelle abgesichert, ausgelaufene Betriebsmittel mit Bindemittel aufgenommen. Batterien abgeklemmt. Fahrbahn gereinigt und an die Polizei übergeben.', 'active' => true],
            ['id' => 'tb-vu-mit-eingeklemmter-person', 'name' => 'VU mit eingeklemmter Person', 'group' => 'Technische Hilfe', 'text' => 'Verkehrsunfall, eine Person im Fahrzeug eingeklemmt. Einsatzstelle abgesichert, Brandschutz sichergestellt, Fahrzeug stabilisiert. Person mit hydraulischem Rettungsgerät befreit und an den Rettungsdienst übergeben.', 'active' => true],
            ['id' => 'tb-oelspur', 'name' => 'Ölspur', 'group' => 'Technische Hilfe', 'text' => 'Ölspur auf einer Länge von ca. ___ m. Einsatzstelle abgesichert, Betriebsmittel mit Bindemittel abgestreut und aufgenommen. Straßenmeisterei bzw. Polizei informiert.', 'active' => true],
            ['id' => 'tb-baum-auf-fahrbahn', 'name' => 'Baum auf Fahrbahn', 'group' => 'Technische Hilfe', 'text' => 'Umgestürzter Baum auf der Fahrbahn. Einsatzstelle abgesichert, Baum mit der Motorsäge zerkleinert und von der Fahrbahn entfernt. Fahrbahn gereinigt und freigegeben.', 'active' => true],
            ['id' => 'tb-tueroeffnung', 'name' => 'Türöffnung', 'group' => 'Technische Hilfe', 'text' => 'Türöffnung für den Rettungsdienst. Wohnungstür ohne/mit Beschädigung geöffnet, Zugang an Rettungsdienst und Polizei übergeben.', 'active' => true],
            ['id' => 'tb-wasserschaden', 'name' => 'Wasserschaden', 'group' => 'Technische Hilfe', 'text' => 'Wasserschaden. Wasserzufuhr abgestellt, Wasser mit Wassersauger bzw. Tauchpumpe entfernt. Einsatzstelle an den Eigentümer übergeben.', 'active' => true],
            ['id' => 'tb-tragehilfe', 'name' => 'Tragehilfe', 'group' => 'Technische Hilfe', 'text' => 'Tragehilfe für den Rettungsdienst. Patient mit dem Rettungsdienst schonend zum Rettungswagen getragen.', 'active' => true],
            ['id' => 'tb-angebranntes-essen', 'name' => 'Angebranntes Essen', 'group' => 'Brand', 'text' => 'Angebranntes Essen in einer Wohnung. Topf vom Herd genommen und ins Freie gebracht, Herd abgeschaltet. Wohnung belüftet und mit Wärmebildkamera kontrolliert. Keine verletzten Personen.', 'active' => true],
            ['id' => 'tb-brandmeldeanlage-fehlalarm', 'name' => 'Brandmeldeanlage, Fehlalarm', 'group' => 'Brand', 'text' => 'Auslösung der Brandmeldeanlage. Objekt erkundet, kein Brand festgestellt. Auslösegrund: ___. Anlage zurückgestellt und an den Betreiber übergeben.', 'active' => true],
            ['id' => 'tb-kleinbrand-im-freien', 'name' => 'Kleinbrand im Freien', 'group' => 'Brand', 'text' => 'Kleinbrand (Mülltonne/Böschung). Brand mit einem C-Rohr bzw. Kübelspritze abgelöscht, Brandstelle mit Wärmebildkamera kontrolliert.', 'active' => true],
            ['id' => 'tb-pkw-brand', 'name' => 'PKW-Brand', 'group' => 'Brand', 'text' => 'PKW-Brand. Brand unter Atemschutz mit Schaum abgelöscht, Batterie abgeklemmt, Nachkontrolle mit Wärmebildkamera. Einsatzstelle an die Polizei übergeben.', 'active' => true],
            ['id' => 'tb-rauchwarnmelder', 'name' => 'Rauchwarnmelder', 'group' => 'Brand', 'text' => 'Auslösender Heimrauchmelder. Wohnung erkundet, kein Brand. Ursache: ___. Rauchmelder deaktiviert, Wohnung an den Bewohner übergeben.', 'active' => true],
        ];
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
