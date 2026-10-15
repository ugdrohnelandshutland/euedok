<?php
declare(strict_types=1);

namespace Euedok;

use Mpdf\Mpdf;

/** Serverseitige PDFs: Bericht (festes, modernes Layout) und QR-Aufkleber. */
final class Pdf
{
    private static function make(array $opts = []): Mpdf
    {
        $tmp = Storage::path('cache/mpdf');
        Storage::ensureDir($tmp);
        $mpdf = new Mpdf($opts + [
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'dejavusans',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 30,
            'margin_bottom' => 22,
            'margin_header' => 10,
            'margin_footer' => 9,
            'tempDir' => $tmp,
        ]);
        $mpdf->SetCreator('EÜDOK ' . EUEDOK_VERSION);
        return $mpdf;
    }

    private static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function para(?string $s): string
    {
        return nl2br(self::h($s));
    }

    private static function fieldValue(array $def, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '–';
        }
        if (($def['type'] ?? '') === 'checkbox') {
            return $v ? 'ja' : 'nein';
        }
        if (($def['type'] ?? '') === 'date') {
            return fmt_date((string) $v);
        }
        if (is_float($v)) {
            $v = number_format($v, 1, ',', '.');
        }
        return self::h((string) $v) . (!empty($def['unit']) ? ' ' . self::h($def['unit']) : '');
    }

    /** „Max Muster (Schnellerfassung · MTW UG-Drohne) am 13.10.2026 08:30“ */
    public static function closedByText(array $r): string
    {
        $last = null;
        foreach ($r['history'] as $h) {
            if (in_array($h['action'], ['closed', 'corrected'], true)) {
                $last = $h;
            }
        }
        if (!$last) {
            return '';
        }
        $who = $last['by_name'] ?? $last['by'];
        if (!empty($last['source']) && str_starts_with($last['source'], 'qr:')) {
            $who .= ' (Schnellerfassung · ' . ($last['qr_label'] ?? substr($last['source'], 3)) . ')';
        }
        $txt = $who . ' am ' . fmt_datetime($last['at']);
        if ($last['action'] === 'corrected') {
            $txt .= ' · Korrektur ' . ($last['correction'] ?? '');
        }
        return $txt;
    }

    public static function reportHtml(array $r): string
    {
        $unit = Settings::unit();
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) $unit['accent_color']) ? $unit['accent_color'] : '#0f6f68';
        $type = Settings::reportType($r['type']['id']) ?? ['style' => 'calm', 'lead_label' => 'Leitung'];
        $badge = ($type['style'] ?? 'calm') === 'alert' ? '#c4202b' : '#24394e';
        $defs = $r['field_definitions'] ?? [];

        $leadName = '';
        foreach ($r['personnel'] as $p) {
            if ($p['id'] === $r['lead']) {
                $leadName = $p['name'];
            }
        }

        $rows = [
            ['Beginn', fmt_datetime($r['start'])],
            ['Ende', fmt_datetime($r['end'])],
            ['Dauer', fmt_duration($r['duration_min'])],
            ['Ort', $r['location']],
            ['Thema / Stichwort', $r['subject']],
        ];
        if (Settings::moduleOn('alert_source') || !empty($r['alert_source'])) {
            $rows[] = ['Alarmierung durch', $r['alert_source']['label'] ?? ''];
        }
        if (Settings::moduleOn('personnel')) {
            $rows[] = [$type['lead_label'] ?? 'Leitung', $leadName];
        }
        foreach ($r['custom_fields'] ?? [] as $fid => $val) {
            $d = $defs[$fid] ?? ['label' => $fid];
            $rows[] = [$d['label'], html_entity_decode(strip_tags(self::fieldValue($d, $val)))];
        }
        $facts = '';
        foreach (array_chunk($rows, 2) as $pair) {
            $facts .= '<tr>';
            foreach ($pair as [$k, $v]) {
                $facts .= '<td class="k">' . self::h($k) . '</td><td class="v">' . (trim((string) $v) !== '' ? self::h((string) $v) : '<span class="q">–</span>') . '</td>';
            }
            if (count($pair) === 1) {
                $facts .= '<td class="k"></td><td class="v"></td>';
            }
            $facts .= '</tr>';
        }

        $html = '<style>
            body{font-family:dejavusans;font-size:9.6pt;color:#0b1a28;line-height:1.45}
            h2{font-size:8pt;letter-spacing:1.5pt;text-transform:uppercase;color:#3b5063;margin:14pt 0 5pt 0;padding-bottom:3pt;border-bottom:0.6pt solid #b9c8d4}
            table.facts{width:100%;border-collapse:collapse}
            table.facts td{padding:5pt 6pt;border-bottom:0.5pt solid #d3dde6;vertical-align:top}
            table.facts td.k{width:17%;color:#3b5063;font-size:8.2pt}
            table.facts td.v{width:33%;font-weight:bold}
            .q{color:#6f8597;font-weight:normal}
            .box{border:0.6pt solid #d3dde6;background:#f4f7fa;padding:8pt 10pt;border-radius:6pt}
            table.list{width:100%;border-collapse:collapse}
            table.list th{text-align:left;font-size:7.6pt;letter-spacing:.8pt;text-transform:uppercase;color:#3b5063;padding:4pt 6pt;border-bottom:0.8pt solid #6f8597}
            table.list td{padding:5pt 6pt;border-bottom:0.5pt solid #d3dde6}
            table.list td.n, table.list th.n{text-align:right}
            table.list tr.sum td{font-weight:bold;border-top:0.8pt solid #6f8597;border-bottom:none;color:' . $accent . '}
            .lead-mark{font-size:7pt;color:#fff;background:' . $accent . ';padding:1pt 4pt;border-radius:3pt}
            .chip{border:0.6pt solid #6f8597;border-radius:4pt;padding:2pt 6pt;margin-right:4pt}
            .hint{font-size:8pt;color:#8a5a00}
            .small{font-size:7.8pt;color:#3b5063}
        </style>';

        $html .= '<table class="facts">' . $facts . '</table>';

        $html .= '<h2>Bericht / getroffene Maßnahmen</h2><div class="box">' . (trim($r['narrative']) !== '' ? self::para($r['narrative']) : '<span class="q">–</span>') . '</div>';

        if (Settings::moduleOn('defects') || trim($r['defects']) !== '') {
            $html .= '<h2>Defekte / Vorfälle</h2><div class="box">' . (trim($r['defects']) !== '' ? self::para($r['defects']) : '<span class="q">keine</span>') . '</div>';
        }

        if (!empty($r['personnel'])) {
            $pf = array_filter($defs, static fn ($d) => ($d['scope'] ?? '') === 'person');
            $usedPf = [];
            foreach ($pf as $fid => $d) {
                foreach ($r['personnel'] as $p) {
                    if (array_key_exists($fid, $p['fields'] ?? [])) {
                        $usedPf[$fid] = $d;
                        break;
                    }
                }
            }
            $html .= '<h2>Eingesetztes Personal (' . count($r['personnel']) . ')</h2><table class="list"><tr><th>Name</th>';
            foreach ($usedPf as $d) {
                $html .= '<th class="n">' . self::h($d['label']) . '</th>';
            }
            $html .= '</tr>';
            $sums = [];
            foreach ($r['personnel'] as $p) {
                $html .= '<tr><td>' . self::h($p['name']) . ($p['id'] === $r['lead'] ? ' &nbsp;<span class="lead-mark">' . self::h($type['lead_label'] ?? 'Leitung') . '</span>' : '') . '</td>';
                foreach ($usedPf as $fid => $d) {
                    $v = $p['fields'][$fid] ?? null;
                    if (is_numeric($v)) {
                        $sums[$fid] = ($sums[$fid] ?? 0) + $v;
                    }
                    $html .= '<td class="n">' . self::fieldValue($d, $v) . '</td>';
                }
                $html .= '</tr>';
            }
            $totals = self::totalsRow($usedPf, $sums, 'person');
            if ($totals !== '') {
                $html .= '<tr class="sum"><td>Summe</td>' . $totals . '</tr>';
            }
            $html .= '</table>';
        }

        if (!empty($r['equipment'])) {
            $html .= '<h2>Eingesetzte Geräte (' . count($r['equipment']) . ')</h2><div>'
                . implode(' &nbsp;·&nbsp; ', array_map(static fn ($q) => self::h($q['label']), $r['equipment'])) . '</div>';
        }

        if (!empty($r['vehicles'])) {
            $vf = array_filter($defs, static fn ($d) => ($d['scope'] ?? '') === 'vehicle');
            $html .= '<h2>Eingesetzte Fahrzeuge</h2><table class="list"><tr><th>Fahrzeug</th><th>Funkrufname</th>';
            foreach ($vf as $d) {
                $html .= '<th class="n">' . self::h($d['label']) . '</th>';
            }
            $html .= '</tr>';
            $sums = [];
            foreach ($r['vehicles'] as $v) {
                $full = MasterData::findVehicle($v['id']);
                $html .= '<tr><td>' . self::h($v['label']) . '</td><td>' . self::h($full['radio_call_sign'] ?? '') . '</td>';
                foreach ($vf as $fid => $d) {
                    $val = $v['fields'][$fid] ?? null;
                    if (is_numeric($val)) {
                        $sums[$fid] = ($sums[$fid] ?? 0) + $val;
                    }
                    $html .= '<td class="n">' . self::fieldValue($d, $val) . '</td>';
                }
                $html .= '</tr>';
            }
            if (count($r['vehicles']) > 1) {
                $totals = self::totalsRow($vf, $sums, 'vehicle');
                if ($totals !== '') {
                    $html .= '<tr class="sum"><td>Summe</td><td></td>' . $totals . '</tr>';
                }
            }
            $html .= '</table>';
        }

        if (!empty($r['divera'])) {
            $html .= '<h2>Divera247-Bezug</h2><div class="small">' . ($r['divera']['type'] === 'alarm' ? 'Alarm' : 'Termin') . ' Nr. ' . self::h((string) $r['divera']['id'])
                . ' · ' . self::h($r['divera']['title']) . (trim((string) $r['divera']['text']) !== '' ? '<br>' . self::para($r['divera']['text']) : '') . '</div>';
        }

        $html .= '<p class="small" style="margin-top:18pt">Abgeschlossen durch ' . self::h(self::closedByText($r)) . '</p>';

        // Kopf und Fuß
        $header = '<table width="100%" style="border-bottom:0.8pt solid ' . $accent . ';padding-bottom:4pt"><tr>'
            . '<td style="vertical-align:bottom"><span style="font-size:13pt;font-weight:bold;color:#0b1a28">' . self::h($unit['name'] ?: 'EÜDOK') . '</span><br>'
            . '<span style="font-size:7pt;letter-spacing:1.6pt;color:#3b5063;text-transform:uppercase">' . self::h($unit['subtitle'] ?: 'Einsatz- & Übungsdokumentation') . '</span></td>'
            . '<td style="text-align:right;vertical-align:bottom"><span style="background:' . $badge . ';color:#fff;font-size:7.5pt;font-weight:bold;letter-spacing:1.2pt;padding:2pt 6pt;text-transform:uppercase">'
            . self::h(mb_strtoupper(Reports::documentTitle($r['type']))) . '</span><br><span style="font-size:15pt;font-weight:bold">' . self::h($r['report_number']) . '</span></td>'
            . '</tr></table>';
        $f = $unit['footer'];
        $footLeft = implode(' · ', array_filter([trim((string) $f['lead']), trim((string) $f['address']), trim((string) $f['contact'])]));
        $footer = '<table width="100%" style="font-size:7pt;color:#6f8597;border-top:0.5pt solid #d3dde6;padding-top:3pt"><tr>'
            . '<td>' . self::h($footLeft) . '</td><td style="text-align:right;white-space:nowrap">' . self::h($r['report_number']) . ' · Seite {PAGENO} / {nbpg}</td></tr></table>';

        return '<htmlpageheader name="h">' . $header . '</htmlpageheader><htmlpagefooter name="f">' . $footer . '</htmlpagefooter>'
            . '<sethtmlpageheader name="h" value="on" show-this-page="1" /><sethtmlpagefooter name="f" value="on" />' . $html;
    }

    private static function totalsRow(array $defs, array $sums, string $scope): string
    {
        $fields = [];
        foreach (Settings::customFields()[$scope] ?? [] as $f) {
            $fields[$f['id']] = $f;
        }
        $out = '';
        $any = false;
        foreach ($defs as $fid => $d) {
            if (!empty($fields[$fid]['show_total']) && isset($sums[$fid])) {
                $any = true;
                $out .= '<td class="n">' . self::fieldValue($d, $sums[$fid]) . '</td>';
            } else {
                $out .= '<td></td>';
            }
        }
        return $any ? $out : '';
    }

    public static function writeReport(array $r, string $file): void
    {
        $mpdf = self::make();
        $mpdf->SetTitle(Reports::documentTitle($r['type']) . ' ' . $r['report_number']);
        $mpdf->SetAuthor((string) (Settings::unit()['name'] ?: 'EÜDOK'));
        $mpdf->WriteHTML(self::reportHtml($r));
        Storage::ensureDir(dirname($file));
        $mpdf->Output($file, \Mpdf\Output\Destination::FILE);
    }

    /** Druckvorlage für den QR-Aufkleber (A6). */
    public static function qrSticker(string $url, string $label): string
    {
        $unit = Settings::unit();
        $mpdf = self::make(['format' => 'A6', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 8, 'margin_header' => 0, 'margin_footer' => 0]);
        $mpdf->SetTitle('QR-Aufkleber ' . $label);
        $html = '<div style="text-align:center;font-family:dejavusans">'
            . '<div style="font-size:16pt;font-weight:bold;color:#0b1a28">EÜ<span style="color:#0f6f68">DOK</span></div>'
            . '<div style="font-size:6.5pt;letter-spacing:1.4pt;color:#3b5063;text-transform:uppercase">' . self::h($unit['name']) . '</div>'
            . '<div style="font-size:12pt;font-weight:bold;margin-top:6pt">Einsatzbericht erstellen</div>'
            . '<div style="margin-top:6pt"><barcode code="' . self::h($url) . '" type="QR" size="1.6" error="M" disableborder="1" /></div>'
            . '<div style="font-size:10pt;font-weight:bold;margin-top:4pt">' . self::h($label) . '</div>'
            . '<div style="font-size:7pt;color:#3b5063;margin-top:4pt">Handy-Kamera auf den Code halten.<br>Nur für Mitglieder der Einheit.</div>'
            . '</div>';
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
