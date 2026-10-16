<?php
declare(strict_types=1);

namespace Euedok;

/** Alles, was das Formular im Browser braucht – ohne Geheimnisse, ohne fremde Berichte. */
final class FormConfig
{
    public static function build(array $report, array $meta, array $opts): array
    {
        $unit = Settings::unit();
        $type = Settings::reportType($report['type']['id']) ?? ['id' => $report['type']['id'], 'label' => $report['type']['label'], 'style' => 'calm', 'lead_label' => 'Leitung'];
        $quick = $opts['mode'] === 'qr';

        $personnel = Settings::moduleOn('personnel') || $quick ? MasterData::personnel() : ['items' => [], 'source' => 'local', 'stale' => false];
        $vehicles = Settings::moduleOn('vehicles') ? MasterData::vehicles() : ['items' => [], 'stale' => false];

        $strip = static fn (array $list, array $keys) => array_map(static fn ($x) => array_intersect_key($x, array_flip($keys)), $list);

        return [
            'number' => $report['report_number'],
            'rev' => (int) $meta['rev'],
            'status' => $report['status'],
            'mode' => $opts['mode'],
            'correction' => !empty($opts['correction']),
            'updated_at' => $meta['updated_at'] ?? null,
            'report' => $report,
            'type' => ['id' => $type['id'], 'label' => $type['label'], 'style' => $type['style'] ?? 'calm', 'lead_label' => $type['lead_label'] ?? 'Leitung', 'title' => Reports::documentTitle($type)],
            'modules' => $unit['modules'],
            'required' => $unit['required_fields'],
            'required_labels' => Reports::requiredLabels(),
            'defects_hint' => $unit['defects_hint'],
            'fields' => [
                'report' => Settings::fields('report'),
                'person' => Settings::fields('person'),
                'vehicle' => Settings::fields('vehicle'),
            ],
            'lists' => [
                'personnel' => [
                    'items' => $strip($personnel['items'], ['id', 'name', 'divera_id', 'frequency']),
                    'source' => $personnel['source'] ?? 'local',
                    'stale' => $personnel['stale'] ?? false,
                    'fetched_at' => $personnel['fetched_at'] ?? null,
                ],
                'equipment' => $strip(MasterData::equipment(), ['id', 'label']),
                'vehicles' => [
                    'items' => $strip($vehicles['items'], ['id', 'label', 'radio_call_sign', 'license_plate', 'source']),
                    'stale' => $vehicles['stale'] ?? false,
                    'fetched_at' => $vehicles['fetched_at'] ?? null,
                ],
                'alert_sources' => $strip(MasterData::alertSources(), ['id', 'label']),
            ],
            // Thema-Vorschläge nur für Angemeldete (würden über QR alte Inhalte verraten)
            'subjects' => $quick ? [] : Reports::subjectSuggestions(),
            // Person des angemeldeten Zugangs – wird automatisch Einheitsführer
            'me_person' => $opts['me_person'] ?? null,
            'divera' => [
                'lookup' => $opts['divera_lookup'] ?? null,
                'ask' => !empty($opts['ask_divera']) && !empty($opts['divera_lookup']),
            ],
            'urls' => [
                'api' => base_url('api.php'),
                'home' => $opts['home'],
                'back' => $opts['back'],
                'pdf' => base_url('download.php?n=' . rawurlencode($report['report_number']) . '&f=pdf'),
                'restart' => $opts['restart'] ?? null,
                'sprite' => base_url('assets/icons/sprite.svg'),
            ],
        ];
    }
}
