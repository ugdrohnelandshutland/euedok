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

    /** Abschluss: [Name, „15.10.2026 08:30 Uhr“, Zusatz wie „Schnellerfassung · MTW“ / „Korrektur 1“] */
    public static function closedByParts(array $r): array
    {
        $last = null;
        foreach ($r['history'] as $h) {
            if (in_array($h['action'], ['closed', 'corrected'], true)) {
                $last = $h;
            }
        }
        if (!$last) {
            return ['–', '', ''];
        }
        $notes = [];
        if (!empty($last['source']) && str_starts_with($last['source'], 'qr:')) {
            $notes[] = 'Schnellerfassung · ' . ($last['qr_label'] ?? substr($last['source'], 3));
        }
        if ($last['action'] === 'corrected') {
            $notes[] = 'Korrektur ' . ($last['correction'] ?? '');
        }
        return [(string) ($last['by_name'] ?? $last['by']), fmt_datetime($last['at']) . ' Uhr', implode(' · ', $notes)];
    }

    /**
     * Berichts-PDF im Formular-Layout (Variante C): geschlossenes Raster, nummerierte Abschnitte,
     * Personal zweispaltig, unten Einheitsführer / Abschluss / Divera – druck- und s/w-freundlich.
     */
    public static function reportHtml(array $r): string
    {
        $unit = Settings::unit();
        $defs = $r['field_definitions'] ?? [];
        $h = static fn ($s) => self::h($s);
        $L = static fn (string $l) => '<span class="lbl">' . self::h($l) . '</span><br>';
        $V = static fn ($v) => '<span class="val">' . (trim((string) $v) !== '' ? self::h((string) $v) : '–') . '</span>';
        $two = static fn (string $a, string $b) => '<span class="val">' . self::h($a) . '</span>' . ($b !== '' ? '<br>' . self::h($b) : '');
        $sec = 0;
        $hd = static function (string $title, int $cols = 1) use (&$sec): string {
            $sec++;
            return '<tr><td colspan="' . $cols . '" class="hd">' . $sec . ' · ' . self::h($title) . '</td></tr>';
        };

        $css = '<style>
            body{font-family:dejavusans;font-size:9pt;color:#000;line-height:1.4}
            table.g{width:100%;border-collapse:collapse}
            table.g td,table.g th{border:0.6pt solid #6f8597;padding:4.5pt 6pt;vertical-align:top}
            table.g th{background:#e8eef3;text-align:left;font-size:7.2pt;letter-spacing:.8pt;text-transform:uppercase;color:#24394e}
            table.p td,table.p th{padding:3pt 5pt}
            table.p td.sep,table.p th.sep{border-top:none;border-bottom:none;background:#fff;width:3mm;padding:0}
            .lbl{font-size:6.8pt;color:#3b5063;text-transform:uppercase;letter-spacing:.6pt}
            .val{font-weight:bold;font-size:9.6pt}
            .hd{background:#24394e;color:#fff;font-weight:bold;font-size:7.6pt;letter-spacing:1.4pt;text-transform:uppercase}
            .n,table.g th.n{text-align:right}
            .nr{color:#6f8597;width:7mm}
            .gap{margin-top:8pt}
        </style>';

        /* 1 · Art und Zeit (+ Berichtsfelder) */
        $types = '';
        foreach (Settings::activeReportTypes() as $t) {
            $types .= ($t['id'] === $r['type']['id'] ? '☒ ' : '☐ ') . $h($t['label']) . ' &nbsp; ';
        }
        if (!Settings::reportType($r['type']['id'])) {
            $types = '☒ ' . $h($r['type']['label']);
        }
        $showAlert = Settings::moduleOn('alert_source') || !empty($r['alert_source']);
        $html = '<table class="g">' . $hd('Art und Zeit', 4)
            . '<tr><td colspan="' . ($showAlert ? 2 : 3) . '">' . $L('Berichtsart') . $types . '</td>'
            . ($showAlert ? '<td>' . $L('Alarmierung durch') . $V($r['alert_source']['label'] ?? '') . '</td>' : '')
            . '<td>' . $L('Dauer') . $V(fmt_duration($r['duration_min'])) . '</td></tr>'
            . '<tr><td colspan="2" width="50%">' . $L('Beginn') . $V(fmt_datetime($r['start'])) . '</td><td colspan="2">' . $L('Ende') . $V(fmt_datetime($r['end'])) . '</td></tr>'
            . '<tr><td colspan="2">' . $L('Ort') . $V($r['location']) . '</td><td colspan="2">' . $L('Thema / Stichwort') . $V($r['subject']) . '</td></tr>';
        $cf = [];
        foreach ($r['custom_fields'] ?? [] as $fid => $val) {
            $d = $defs[$fid] ?? ['label' => $fid];
            $cf[] = '<td colspan="2">' . $L($d['label']) . '<span class="val">' . self::fieldValue($d, $val) . '</span></td>';
        }
        foreach (array_chunk($cf, 2) as $pair) {
            $html .= '<tr>' . implode('', $pair) . (count($pair) === 1 ? '<td colspan="2"></td>' : '') . '</tr>';
        }
        $html .= '</table>';

        /* 2 · Bericht, 3 · Defekte */
        $html .= '<table class="g gap">' . $hd('Bericht / getroffene Maßnahmen')
            . '<tr><td style="height:32mm">' . (trim($r['narrative']) !== '' ? self::para($r['narrative']) : '–') . '</td></tr>';
        if (Settings::moduleOn('defects') || trim($r['defects']) !== '') {
            $html .= $hd('Defekte / Vorfälle') . '<tr><td>' . (trim($r['defects']) !== '' ? self::para($r['defects']) : 'keine') . '</td></tr>';
        }
        $html .= '</table>';

        /* 4 · Personal – zweispaltig, solange höchstens zwei Personenfelder genutzt werden */
        if (!empty($r['personnel'])) {
            $pf = [];
            foreach ($defs as $fid => $d) {
                if (($d['scope'] ?? '') !== 'person') {
                    continue;
                }
                foreach ($r['personnel'] as $p) {
                    if (array_key_exists($fid, $p['fields'] ?? [])) {
                        $pf[$fid] = $d;
                        break;
                    }
                }
            }
            $people = array_values($r['personnel']);
            // gleiche Namen (z. B. doppeltes Divera-Konto) mit Kennung unterscheidbar machen
            $count = array_count_values(array_column($people, 'name'));
            foreach ($people as &$pp) {
                if (($count[$pp['name']] ?? 0) > 1) {
                    $pp['name'] .= ' (#' . ($pp['divera_id'] ?? $pp['id']) . ')';
                }
            }
            unset($pp);
            $twoCols = count($people) > 1 && count($pf) <= 2;
            $colsPer = 2 + count($pf);
            $width = $twoCols ? $colsPer * 2 + 1 : $colsPer;
            $headCells = '<th class="nr">Nr.</th><th>Name</th>';
            foreach ($pf as $d) {
                $short = mb_strlen($d['label']) > 11 ? mb_substr($d['label'], 0, 9) . '.' : $d['label'];
                $headCells .= '<th class="n" style="width:22mm">' . $h($short) . '</th>';
            }
            $cell = static function (?array $p, int $nr) use ($pf, $h): string {
                if ($p === null) {
                    return '<td class="nr"></td><td></td>' . str_repeat('<td></td>', count($pf));
                }
                $c = '<td class="nr">' . $nr . '</td><td>' . $h($p['name']) . '</td>';
                foreach ($pf as $fid => $d) {
                    $v = $p['fields'][$fid] ?? null;
                    $c .= '<td class="n">' . ($v === null || $v === '' ? '–' : self::fieldValue(['unit' => ''] + $d, $v)) . '</td>';
                }
                return $c;
            };
            $html .= '<table class="g p gap">' . $hd('Eingesetztes Personal (' . count($people) . ')', $width)
                . '<tr>' . $headCells . ($twoCols ? '<th class="sep"></th>' . $headCells : '') . '</tr>';
            if ($twoCols) {
                $half = (int) ceil(count($people) / 2);
                for ($i = 0; $i < $half; $i++) {
                    $html .= '<tr>' . $cell($people[$i], $i + 1) . '<td class="sep"></td>' . $cell($people[$half + $i] ?? null, $half + $i + 1) . '</tr>';
                }
            } else {
                foreach ($people as $i => $p) {
                    $html .= '<tr>' . $cell($p, $i + 1) . '</tr>';
                }
            }
            $totals = self::personTotals($pf, $people);
            if ($totals !== '') {
                $html .= '<tr><td colspan="' . $width . '" class="n">' . $totals . '</td></tr>';
            }
            $html .= '</table>';
        }

        /* 5 · Geräte, 6 · Fahrzeuge – nebeneinander, wenn beides vorhanden */
        $eq = '';
        if (!empty($r['equipment'])) {
            $eq = '<table class="g">' . $hd('Geräte', 2);
            foreach (array_chunk($r['equipment'], 2) as $pair) {
                $eq .= '<tr><td width="50%">☒ ' . $h($pair[0]['label']) . '</td><td width="50%">' . (isset($pair[1]) ? '☒ ' . $h($pair[1]['label']) : '') . '</td></tr>';
            }
            $eq .= '</table>';
        }
        $ve = '';
        if (!empty($r['vehicles'])) {
            $vf = array_filter($defs, static fn ($d) => ($d['scope'] ?? '') === 'vehicle');
            $ve = '<table class="g">' . $hd('Fahrzeuge', 2 + count($vf)) . '<tr><th>Fahrzeug</th><th>Funk</th>';
            foreach ($vf as $d) {
                $ve .= '<th class="n">' . $h($d['unit'] !== '' ? $d['unit'] : $d['label']) . '</th>';
            }
            $ve .= '</tr>';
            foreach ($r['vehicles'] as $v) {
                $full = MasterData::findVehicle($v['id']);
                $call = trim((string) ($full['radio_call_sign'] ?? ''));
                $ve .= '<tr><td>' . $h($v['label']) . '</td><td>' . ($call !== '' ? $h($call) : '–') . '</td>';
                foreach ($vf as $fid => $d) {
                    $val = $v['fields'][$fid] ?? null;
                    $ve .= '<td class="n">' . ($val === null || $val === '' ? '–' : $h(is_float($val) ? number_format($val, 1, ',', '.') : (string) $val)) . '</td>';
                }
                $ve .= '</tr>';
            }
            $ve .= '</table>';
        }
        if ($eq !== '' && $ve !== '') {
            $html .= '<table width="100%" class="gap" style="border-collapse:collapse"><tr><td width="50%" style="padding:0 4pt 0 0;vertical-align:top">' . $eq
                . '</td><td width="50%" style="padding:0 0 0 4pt;vertical-align:top">' . $ve . '</td></tr></table>';
        } elseif ($eq !== '' || $ve !== '') {
            $html .= '<div class="gap">' . $eq . $ve . '</div>';
        }

        /* 7 · Einheitsführer und Abschluss */
        $lead = '';
        foreach ($people ?? $r['personnel'] as $p) {
            if ($p['id'] === $r['lead']) {
                $lead = $p['name'];
            }
        }
        [$who, $when, $note] = self::closedByParts($r);
        $div = ['–', ''];
        if (!empty($r['divera'])) {
            $div = [($r['divera']['type'] === 'alarm' ? 'Alarm' : 'Termin') . ' Nr. ' . $r['divera']['id'], (string) ($r['divera']['title'] ?? '')];
        }
        $html .= '<table class="g gap">' . $hd('Einheitsführer und Abschluss', 3) . '<tr>'
            . '<td width="34%">' . $L('Einheitsführer') . $two($lead !== '' ? $lead : '–', '') . '</td>'
            . '<td width="33%">' . $L('Abgeschlossen durch') . $two($who, $when) . ($note !== '' ? '<br><span class="lbl">' . $h($note) . '</span>' : '') . '</td>'
            . '<td width="33%">' . $L('Divera247-Bezug') . $two($div[0], $div[1]) . '</td></tr></table>';

        /* Kopf und Fuß */
        $header = '<table width="100%" style="border-bottom:1.5pt solid #000"><tr>'
            . '<td style="padding-bottom:4pt"><b style="font-size:13pt">' . $h($unit['name'] ?: 'EÜDOK') . '</b><br>'
            . '<span style="font-size:7pt;letter-spacing:1.4pt;text-transform:uppercase">' . $h(Reports::documentTitle($r['type']) . ($unit['subtitle'] ? ' · ' . $unit['subtitle'] : '')) . '</span></td>'
            . '<td style="text-align:right;padding-bottom:4pt"><span style="font-size:7pt">Berichts-Nr.</span><br><b style="font-size:15pt">' . $h($r['report_number']) . '</b></td>'
            . '</tr></table>';
        $footer = self::footerHtml(Footer::parts($unit), [
            'number' => $r['report_number'],
            'date' => fmt_date($r['start']),
            'unit' => $unit['name'],
        ]);

        return '<htmlpageheader name="h">' . $header . '</htmlpageheader><htmlpagefooter name="f">' . $footer . '</htmlpagefooter>'
            . '<sethtmlpageheader name="h" value="on" show-this-page="1" /><sethtmlpagefooter name="f" value="on" />' . $css . $html;
    }

    /** „Flugminuten gesamt: 390 min (6:30 h)“ für Personenfelder mit „Summe anzeigen“ */
    private static function personTotals(array $pf, array $people): string
    {
        $fields = [];
        foreach (Settings::customFields()['person'] ?? [] as $f) {
            $fields[$f['id']] = $f;
        }
        $parts = [];
        foreach ($pf as $fid => $d) {
            if (empty($fields[$fid]['show_total'])) {
                continue;
            }
            $sum = 0;
            $any = false;
            foreach ($people as $p) {
                $v = $p['fields'][$fid] ?? null;
                if (is_numeric($v)) {
                    $sum += $v;
                    $any = true;
                }
            }
            if (!$any) {
                continue;
            }
            $txt = '<b>' . self::h($d['label']) . ' gesamt: ' . self::h(str_replace('.', ',', (string) round($sum, 1))) . ($d['unit'] !== '' ? ' ' . self::h($d['unit']) : '') . '</b>';
            if (($d['unit'] ?? '') === 'min' && $sum >= 60) {
                $txt .= ' &nbsp;(' . intdiv((int) $sum, 60) . ':' . sprintf('%02d', (int) $sum % 60) . ' h)';
            }
            $parts[] = $txt;
        }
        return implode(' &nbsp;·&nbsp; ', $parts);
    }

    /** Dreispaltige Fußzeile (links, Mitte, rechts) aus den Einstellungen. */
    public static function footerHtml(array $parts, array $vars): string
    {
        $cells = '';
        foreach (['left' => 'left', 'center' => 'center', 'right' => 'right'] as $k => $align) {
            $cells .= '<td width="33%" style="text-align:' . $align . ';vertical-align:top;padding-top:3pt">' . Footer::toHtml($parts[$k] ?? '', $vars) . '</td>';
        }
        return '<table width="100%" style="font-size:7pt;line-height:1.35;color:#3b5063;border-top:0.5pt solid #d3dde6;border-collapse:collapse"><tr>' . $cells . '</tr></table>';
    }

    /** Unterer Rand passend zur Zeilenzahl der Fußzeile (mm). */
    private static function bottomMargin(): int
    {
        return 14 + 3 * Footer::lineCount(Footer::parts());
    }

    public static function writeReport(array $r, string $file): void
    {
        $t0 = microtime(true);
        $mpdf = self::make(['margin_bottom' => self::bottomMargin(), 'margin_footer' => 8]);
        $mpdf->SetTitle(Reports::documentTitle($r['type']) . ' ' . $r['report_number']);
        $mpdf->SetAuthor((string) (Settings::unit()['name'] ?: 'EÜDOK'));
        $mpdf->WriteHTML(self::reportHtml($r));
        Storage::ensureDir(dirname($file));
        $mpdf->Output($file, \Mpdf\Output\Destination::FILE);
        Log::info('pdf', 'generated', 'PDF erzeugt: ' . basename($file), [
            'report' => $r['report_number'],
            'pages' => $mpdf->page,
            'kb' => (int) round(filesize($file) / 1024),
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
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
