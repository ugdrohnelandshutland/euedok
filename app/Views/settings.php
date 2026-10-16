<?php
use Euedok\Divera;
use Euedok\Log;
use Euedok\QrAccess;
use Euedok\Reports;
use Euedok\Settings;
use Euedok\SettingsPage;

$unit = Settings::unit();
$md = Settings::masterData();
$action = static fn (string $a) => e(base_url('settings.php?s=' . $section . '&action=' . $a));
$sw = static fn (string $name, bool $on, string $extra = '') => '<span class="sw"><input type="checkbox" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . $extra . '><span></span></span>';
$toggle = static fn (string $name, bool $on, string $title, string $sub = '') => '<label class="toggle-row"><span class="tx"><b>' . e($title) . '</b>' . ($sub !== '' ? '<span>' . e($sub) . '</span>' : '') . '</span>' . $sw($name, $on) . '</label>';
$mv = '<span class="mv"><button type="button" data-move="up" aria-label="nach oben" style="transform:rotate(-90deg)">' . icon('cr') . '</button><button type="button" data-move="down" aria-label="nach unten">' . icon('cd') . '</button></span>';
// Zellen der Listen-Editoren: Beschriftung am Handy sichtbar (am PC steht sie in der Kopfzeile)
$cell = static fn (string $label, string $control, string $cls = '') => '<label class="cell ' . $cls . '"><span class="cl">' . e($label) . '</span>' . $control . '</label>';
$swCell = static fn (string $label, string $name, bool $on, string $extra = '') => '<label class="cell sw-cell" title="' . e($label) . '"><span class="cl">' . e($label) . '</span>' . $sw($name, $on, $extra) . '</label>';
$mvCell = '<div class="cell mv-cell"><span class="cl">Reihenfolge</span>' . $mv . '</div>';

/**
 * Einfache Liste: Bezeichnung (+ Zusatzspalten) + aktiv + Reihenfolge.
 * @param array<string,string> $cols Schlüssel → Überschrift (erste Spalte = Bezeichnung)
 */
$listEditor = static function (string $name, array $items, array $cols) use ($cell, $swCell, $mvCell): string {
    $grid = 'grid-template-columns:' . implode(' ', array_fill(0, count($cols), 'minmax(0,1fr)')) . ' 64px 80px';
    $row = static function ($idx, array $it) use ($cols, $name, $cell, $swCell, $mvCell, $grid): string {
        $h = '<div class="ed-row' . (empty($it['active']) && isset($it['id']) ? ' inactive' : '') . '" style="' . $grid . '">';
        $h .= '<input type="hidden" name="' . $name . '[' . $idx . '][id]" value="' . e($it['id'] ?? '') . '">';
        $first = true;
        foreach ($cols as $k => $title) {
            $h .= $cell($title, '<input class="in" name="' . $name . '[' . $idx . '][' . $k . ']" value="' . e($it[$k] ?? '') . '" placeholder="' . e($title) . '">', $first ? 'wide' : '');
            $first = false;
        }
        $h .= $swCell('aktiv', $name . '[' . $idx . '][active]', !isset($it['id']) || !empty($it['active'])) . $mvCell . '</div>';
        return $h;
    };
    $out = '<div class="ed-head" style="' . $grid . '">';
    foreach ($cols as $title) {
        $out .= '<span>' . e($title) . '</span>';
    }
    $out .= '<span>aktiv</span><span></span></div><div id="list-' . $name . '">';
    foreach (array_values($items) as $i => $it) {
        $out .= $row($i, $it);
    }
    $out .= '</div><template id="list-' . $name . '-tpl">' . $row('__i__', []) . '</template>';
    $out .= '<button type="button" class="btn sm" data-add-row="list-' . $name . '" style="margin-top:10px">' . icon('plus') . 'Hinzufügen</button>';
    return $out;
};
?>
<main class="wrap">
  <h1>Einstellungen</h1>
  <div class="set">
    <nav class="set-nav" aria-label="Bereiche">
      <?php foreach (SettingsPage::SECTIONS as $k => [$label, $ic]): ?>
      <a href="<?= e(base_url('settings.php?s=' . $k)) ?>" class="<?= $k === $section ? 'on' : '' ?>"><?= icon($ic) ?><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="stack">

<?php if ($section === 'unit'): ?>
      <form method="post" action="<?= $action('save') ?>" class="card stack">
        <?= csrf_field() ?>
        <h3><?= icon('shield') ?>Einheit</h3>
        <div class="row">
          <label class="f">Name der Einheit <input class="in" name="name" required value="<?= e($unit['name']) ?>"></label>
          <label class="f">Untertitel <input class="in" name="subtitle" value="<?= e($unit['subtitle']) ?>"></label>
        </div>
        <div class="row">
          <label class="f">Akzentfarbe im PDF <input class="in" type="color" name="accent_color" value="<?= e($unit['accent_color']) ?>" style="padding:4px;max-width:120px"></label>
          <label class="f">Öffentliche Adresse (für QR-Links, optional) <input class="in" name="public_url" value="<?= e($unit['public_url']) ?>" placeholder="https://berichte.example.de"></label>
        </div>
        <h3 style="margin-top:8px"><?= icon('file') ?>Fußzeile im PDF</h3>
        <p class="hint">Drei frei gestaltbare Bereiche. Text markieren und mit <b>F</b>, <i>K</i> oder <u>U</u> formatieren; Platzhalter werden im PDF ersetzt.</p>
        <?php $fp = \Euedok\Footer::parts($unit); ?>
        <div class="foot-ed">
          <?php foreach (\Euedok\Footer::POSITIONS as $pos => $posLabel): ?>
          <div class="foot-col">
            <label class="f" for="footer_<?= $pos ?>"><?= e($posLabel) ?></label>
            <div class="fmt-bar" role="toolbar" aria-label="Formatierung <?= e($posLabel) ?>">
              <button type="button" data-fmt="**" data-fmt-target="footer_<?= $pos ?>" title="Fett"><b>F</b></button>
              <button type="button" data-fmt="*" data-fmt-target="footer_<?= $pos ?>" title="Kursiv"><i>K</i></button>
              <button type="button" data-fmt="__" data-fmt-target="footer_<?= $pos ?>" title="Unterstrichen"><u>U</u></button>
              <select data-insert-target="footer_<?= $pos ?>" aria-label="Platzhalter einfügen">
                <option value="">{…} einfügen</option>
                <?php foreach (\Euedok\Footer::PLACEHOLDERS as $ph => $phLabel): ?><option value="<?= e($ph) ?>"><?= e($phLabel) ?></option><?php endforeach; ?>
              </select>
            </div>
            <textarea class="in foot-ta" id="footer_<?= $pos ?>" name="footer_<?= $pos ?>" rows="4" data-footer-part="<?= $pos ?>"><?= e($fp[$pos]) ?></textarea>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="sec-t" style="margin-top:4px">Vorschau</div>
        <div class="foot-prev" id="footer-preview" data-unit="<?= e($unit['name']) ?>"><div data-prev="left"></div><div data-prev="center"></div><div data-prev="right"></div></div>
        <div class="row">
          <label class="f">Hinweis unter „Defekte / Vorfälle“ <input class="in" name="defects_hint" value="<?= e($unit['defects_hint']) ?>"></label>
          <label class="f">Angemeldet bleiben (Tage) <input class="in" type="number" min="1" max="365" name="remember_days" value="<?= (int) $unit['remember_days'] ?>"></label>
          <label class="f">Zeitzone <input class="in" name="timezone" value="<?= e($unit['timezone']) ?>"></label>
        </div>
        <p class="hint">Logo-Upload folgt mit Stufe 2 – bis dahin steht der Name der Einheit als Schriftzug im PDF.</p>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>

<?php elseif ($section === 'types'): ?>
      <form method="post" action="<?= $action('save') ?>" class="card stack">
        <?= csrf_field() ?>
        <h3><?= icon('file') ?>Berichtsarten &amp; Nummern</h3>
        <p class="hint">Nummernformat: {YYYY} = Jahr, {YY} = Jahr zweistellig, {NNN} = laufende Nummer (Zählung je Art und Jahr). „Laut“ = roter Block wie beim Einsatz.</p>
        <?php
        $grid = 'grid-template-columns:minmax(0,1.1fr) minmax(0,1.1fr) minmax(0,1.1fr) 110px 120px 64px 80px';
        $typeRow = static function ($i, array $t) use ($cell, $swCell, $mvCell, $grid): string {
            $isNew = !isset($t['id']);
            $sel = static fn ($v, $cur) => $v === $cur ? ' selected' : '';
            return '<div class="ed-row" style="' . $grid . '">'
                . '<input type="hidden" name="types[' . $i . '][id]" value="' . e($t['id'] ?? '') . '">'
                . $cell('Bezeichnung', '<input class="in" name="types[' . $i . '][label]" value="' . e($t['label'] ?? '') . '" placeholder="z. B. Ausbildung">', 'wide')
                . $cell('Nummernformat', '<input class="in" name="types[' . $i . '][number_format]" value="' . e($t['number_format'] ?? '') . '" placeholder="A-{YYYY}-{NNN}">')
                . $cell('Bezeichnung Leitung', '<input class="in" name="types[' . $i . '][lead_label]" value="' . e($t['lead_label'] ?? '') . '" placeholder="Leitung">')
                . $cell('Farbe', '<select class="in" name="types[' . $i . '][style]"><option value="calm"' . $sel('calm', $t['style'] ?? 'calm') . '>ruhig</option><option value="alert"' . $sel('alert', $t['style'] ?? '') . '>laut</option></select>')
                . $cell('Divera', '<select class="in" name="types[' . $i . '][divera_source]"><option value="">kein Divera</option><option value="alarm"' . $sel('alarm', $t['divera_source'] ?? '') . '>Alarme</option><option value="event"' . $sel('event', $t['divera_source'] ?? '') . '>Termine</option></select>')
                . $swCell('aktiv', 'types[' . $i . '][active]', $isNew || !empty($t['active'])) . $mvCell
                . ($isNew ? $cell('Technische ID', '<input class="in" name="types[' . $i . '][new_id]" placeholder="Englisch, z. B. training">', 'wide full') : '')
                . '</div>';
        };
        ?>
        <div class="ed-head" style="<?= $grid ?>"><span>Bezeichnung</span><span>Nummernformat</span><span>Bezeichnung Leitung</span><span>Farbe</span><span>Divera</span><span>aktiv</span><span></span></div>
        <div id="list-types"><?php foreach ($unit['report_types'] as $i => $t) { echo $typeRow($i, $t); } ?></div>
        <template id="list-types-tpl"><?= $typeRow('__i__', []) ?></template>
        <button type="button" class="btn sm" data-add-row="list-types"><?= icon('plus') ?>Berichtsart hinzufügen</button>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>

<?php elseif ($section === 'modules'): ?>
      <form method="post" action="<?= $action('save') ?>" class="stack">
        <?= csrf_field() ?>
        <section class="card">
          <h3><?= icon('sliders') ?>Module</h3>
          <?= $toggle('mod_personnel', $unit['modules']['personnel'], 'Personal', 'Eingesetzte Personen und Leitung') ?>
          <?= $toggle('mod_equipment', $unit['modules']['equipment'], 'Geräte', 'Eingesetzte Geräte als Kacheln') ?>
          <?= $toggle('mod_vehicles', $unit['modules']['vehicles'], 'Fahrzeuge', 'Eingesetzte Fahrzeuge mit Fahrzeugfeldern') ?>
          <?= $toggle('mod_defects', $unit['modules']['defects'], 'Defekte / Vorfälle', 'Freitext mit Hinweis') ?>
          <?= $toggle('mod_alert_source', $unit['modules']['alert_source'], 'Alarmierung', 'Schnellauswahl plus freie Eingabe') ?>
        </section>
        <section class="card">
          <h3><?= icon('check') ?>Pflichtangaben zum Abschließen</h3>
          <div class="req-list">
            <?php foreach (Reports::requiredLabels() as $k => $label): ?>
            <label class="check"><input type="checkbox" name="req_<?= e($k) ?>" value="1"<?= in_array($k, $unit['required_fields'], true) ? ' checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
          </div>
          <p class="hint">Pflicht-Zusatzfelder werden unter „Zusatzfelder“ festgelegt. Über QR ist „Erstellt durch“ immer Pflicht.</p>
        </section>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>

<?php elseif ($section === 'fields'): ?>
      <form method="post" action="<?= $action('save') ?>" class="stack">
        <?= csrf_field() ?>
        <p class="muted">Felder werden deaktiviert statt gelöscht, damit alte Berichte vollständig bleiben. Sie erscheinen in Formular, PDF und JSON.</p>
        <?php
        $types = ['number' => 'Zahl', 'text' => 'Text', 'select' => 'Auswahl', 'checkbox' => 'Haken', 'date' => 'Datum'];
        $grid = 'grid-template-columns:minmax(0,1.3fr) 110px 80px 72px 72px 64px 80px';
        $fieldRow = static function (string $kind, $i, array $f) use ($cell, $swCell, $mvCell, $types, $grid): string {
            $n = 'fields_' . $kind . '[' . $i . ']';
            $isNew = !isset($f['id']);
            $opts = '';
            foreach ($types as $k => $l) {
                $opts .= '<option value="' . $k . '"' . (($f['type'] ?? 'number') === $k ? ' selected' : '') . '>' . $l . '</option>';
            }
            return '<div class="ed-row' . (!$isNew && empty($f['active']) ? ' inactive' : '') . '" style="' . $grid . '">'
                . '<input type="hidden" name="' . $n . '[id]" value="' . e($f['id'] ?? '') . '">'
                . $cell('Bezeichnung', '<input class="in" name="' . $n . '[label]" value="' . e($f['label'] ?? '') . '" placeholder="z. B. Flugminuten">', 'wide')
                . $cell('Typ', '<select class="in" name="' . $n . '[type]" data-field-type>' . $opts . '</select>')
                . $cell('Einheit', '<input class="in" name="' . $n . '[unit]" value="' . e($f['unit'] ?? '') . '" placeholder="z. B. min">')
                . $swCell('Pflicht', $n . '[required]', !empty($f['required']))
                . $swCell('Summe', $n . '[show_total]', !empty($f['show_total']))
                . $swCell('aktiv', $n . '[active]', $isNew || !empty($f['active'])) . $mvCell
                . '<label class="cell wide full" data-options' . (($f['type'] ?? 'number') === 'select' ? '' : ' hidden') . '><span class="cl" style="display:block">Auswahlwerte (mit Komma getrennt)</span><input class="in" name="' . $n . '[options]" value="' . e(implode(', ', $f['options'] ?? [])) . '"></label>'
                . ($isNew ? $cell('Technische ID', '<input class="in" name="' . $n . '[new_id]" placeholder="Englisch, z. B. flight_minutes">', 'wide full') : '<span class="hint wide full">ID: <span class="mono">' . e($f['id']) . '</span></span>')
                . '</div>';
        };
        $kinds = ['report' => ['Berichtsfelder', 'einmal je Bericht', 'file'], 'person' => ['Personenfelder', 'je eingesetzter Person (z. B. Flugminuten)', 'users'], 'vehicle' => ['Fahrzeugfelder', 'je eingesetztem Fahrzeug (z. B. Kilometer)', 'truck']];
        foreach ($kinds as $kind => [$title, $sub, $ic]): ?>
        <section class="card">
          <h3><?= icon($ic) ?><?= e($title) ?> <span class="r muted small"><?= e($sub) ?></span></h3>
          <div class="ed-head" style="<?= $grid ?>"><span>Bezeichnung</span><span>Typ</span><span>Einheit</span><span>Pflicht</span><span>Summe</span><span>aktiv</span><span></span></div>
          <div id="list-f-<?= $kind ?>"><?php foreach (Settings::customFields()[$kind] as $i => $f) { echo $fieldRow($kind, $i, $f); } ?></div>
          <template id="list-f-<?= $kind ?>-tpl"><?= $fieldRow($kind, '__i__', []) ?></template>
          <button type="button" class="btn sm" data-add-row="list-f-<?= $kind ?>" style="margin-top:10px"><?= icon('plus') ?>Feld hinzufügen</button>
        </section>
        <?php endforeach; ?>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>

<?php elseif ($section === 'personnel'): ?>
      <?php if (Divera::personnelFromDivera()): $p = Divera::personnel(); ?>
      <section class="card stack">
        <h3><?= icon('users') ?>Personal</h3>
        <div class="msg info"><?= icon('radio') ?><span>Wird in Divera247 verwaltet (live, kein Abgleich). Aktuell <?= count($p['items']) ?> Personen<?= $p['stale'] ? ' – Divera nicht erreichbar, Stand ' . e(fmt_datetime($p['fetched_at'])) : '' ?>.</span></div>
        <p class="hint">Umstellen unter „Divera247 → Personalquelle“.</p>
      </section>
      <?php else: ?>
      <form method="post" action="<?= $action('save') ?>" class="card">
        <?= csrf_field() ?>
        <h3><?= icon('users') ?>Personal</h3>
        <p class="hint" style="margin-bottom:10px">Schreibweise „Nachname, Vorname“. Ausgeschiedene Personen deaktivieren statt löschen.</p>
        <?= $listEditor('personnel', $md['personnel'], ['name' => 'Name']) ?>
        <div style="margin-top:14px"><button class="btn pri"><?= icon('check') ?>Speichern</button></div>
      </form>
      <?php endif; ?>

<?php elseif ($section === 'equipment'): ?>
      <form method="post" action="<?= $action('save') ?>" class="card">
        <?= csrf_field() ?>
        <h3><?= icon('box') ?>Geräte</h3>
        <?= $listEditor('equipment', $md['equipment'], ['label' => 'Bezeichnung']) ?>
        <div style="margin-top:14px"><button class="btn pri"><?= icon('check') ?>Speichern</button></div>
      </form>

<?php elseif ($section === 'vehicles'): ?>
      <form method="post" action="<?= $action('save') ?>" class="card stack">
        <?= csrf_field() ?>
        <h3><?= icon('truck') ?>Fahrzeuge</h3>
        <?php if (!Settings::moduleOn('vehicles')): ?><div class="msg warn"><?= icon('alert') ?><span>Das Modul „Fahrzeuge“ ist aus (Pflichtfelder &amp; Module).</span></div><?php endif; ?>
        <?= $toggle('vehicles_from_divera', (bool) Settings::divera()['vehicles_from_divera'], 'Fahrzeuge live aus Divera247', Divera::configured() ? 'zusätzlich zu den selbst hinterlegten' : 'Divera247 ist nicht eingerichtet') ?>
        <div class="sec-t">Selbst hinterlegte Fahrzeuge</div>
        <?= $listEditor('vehicles', $md['vehicles'], ['label' => 'Bezeichnung', 'radio_call_sign' => 'Funkrufname', 'license_plate' => 'Kennzeichen']) ?>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>

<?php elseif ($section === 'alerts'): ?>
      <form method="post" action="<?= $action('save') ?>" class="card">
        <?= csrf_field() ?>
        <h3><?= icon('radio') ?>Alarmierungsquellen (Schnellauswahl)</h3>
        <p class="hint" style="margin-bottom:10px">Frei eingegebene Quellen im Bericht werden hier nicht übernommen.</p>
        <?= $listEditor('alert_sources', $md['alert_sources'], ['label' => 'Bezeichnung']) ?>
        <div style="margin-top:14px"><button class="btn pri"><?= icon('check') ?>Speichern</button></div>
      </form>

<?php elseif ($section === 'mail'): $m = Settings::mail(); ?>
      <form method="post" action="<?= $action('save') ?>" class="card stack" autocomplete="off">
        <?= csrf_field() ?>
        <h3><?= icon('mail') ?>Mailversand beim Abschließen</h3>
        <?= $toggle('enabled', (bool) $m['enabled'], 'Mailversand an', 'PDF + JSON gehen an alle Empfänger') ?>
        <label class="f">Empfänger (eine Adresse je Zeile) <textarea class="in" name="recipients" rows="3" style="min-height:90px"><?= e(implode("\n", $m['recipients'])) ?></textarea></label>
        <div class="row">
          <label class="f">Absenderadresse <input class="in" type="email" name="from_address" value="<?= e($m['from_address']) ?>"></label>
          <label class="f">Absendername <input class="in" name="from_name" value="<?= e($m['from_name']) ?>"></label>
        </div>
        <label class="f">Betreffvorlage <input class="in" name="subject_template" value="<?= e($m['subject_template']) ?>"><span class="hint">Platzhalter: {type} {number} {subject} {location} {date} {unit}</span></label>
        <div class="row">
          <label class="f">Versandweg <select class="in" name="transport"><option value="smtp"<?= $m['transport'] === 'smtp' ? ' selected' : '' ?>>SMTP</option><option value="file"<?= $m['transport'] === 'file' ? ' selected' : '' ?>>Nur Datei (Test, data/mail-outbox/)</option></select></label>
          <label class="f">SMTP-Server <input class="in" name="smtp_host" value="<?= e($m['smtp_host']) ?>" placeholder="z. B. mxf9a1.netcup.net"></label>
        </div>
        <div class="row">
          <label class="f">Port <input class="in" type="number" name="smtp_port" value="<?= (int) $m['smtp_port'] ?>"></label>
          <label class="f">Verschlüsselung <select class="in" name="smtp_security"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'keine'] as $k => $l): ?><option value="<?= $k ?>"<?= $m['smtp_security'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
          <label class="f">Benutzer <input class="in" name="smtp_user" value="<?= e($m['smtp_user']) ?>" autocomplete="off"></label>
        </div>
        <div class="secret">
          <label class="f" style="flex:1;min-width:220px">SMTP-Passwort <input class="in" type="password" name="smtp_password" autocomplete="new-password" placeholder="<?= $m['smtp_password_enc'] ? 'hinterlegt ✓ – leer lassen zum Behalten' : 'nicht hinterlegt' ?>"></label>
          <?php if ($m['smtp_password_enc']): ?><label class="check"><input type="checkbox" name="smtp_password_clear" value="1"> Passwort entfernen</label><?php endif; ?>
        </div>
        <p class="hint">Das Passwort wird verschlüsselt gespeichert und nie wieder angezeigt.</p>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>
      <form method="post" action="<?= $action('test-mail') ?>"><?= csrf_field() ?><button class="btn"><?= icon('send') ?>Testmail an die Empfänger senden</button></form>

<?php elseif ($section === 'divera'):
    $d = Settings::divera();
    $test = $_SESSION['divera_test'] ?? null;
    unset($_SESSION['divera_test']);
    $statusList = [];
    if (Divera::configured()) {
        $st = Divera::statuses();
        foreach ($st['items'] as $s) {
            $statusList[$s['id']] = $s['label'];
        }
    }
    if ($test && $test['ok']) {
        foreach ($test['data']['statuses'] as $s) {
            $statusList[$s['id']] = $s['label'];
        }
        foreach ($test['data']['answer_keys']['alarm'] as $k => $n) {
            $statusList[(string) $k] ??= 'Antwort-ID ' . $k;
        }
    }
    $chosen = [];
    foreach ($d['attendance_statuses'] as $s) {
        $chosen[(string) $s['id']] = $s['label'];
        $statusList[(string) $s['id']] ??= $s['label'];
    }
    $sources = $md['alert_sources'];
    ?>
      <?php if ($test): ?>
        <?php if ($test['ok']): $t = $test['data']; ?>
        <div class="msg ok"><?= icon('check') ?><span>Divera erreichbar<?= $t['unit'] !== '' ? ' – Einheit „' . e($t['unit']) . '“' : '' ?>: <?= (int) $t['personnel'] ?> Personen, <?= (int) $t['vehicles'] ?> Fahrzeuge, <?= count($t['statuses']) ?> Status, <?= (int) $t['alarms'] ?> Alarme, <?= (int) $t['events'] ?> Termine.
          <?php foreach (['alarm' => 'Rückmeldungen in Alarmen', 'event' => 'Rückmeldungen in Terminen'] as $src => $l): if ($t['answer_keys'][$src]): ?><br><?= e($l) ?>: <?php foreach ($t['answer_keys'][$src] as $k => $n): ?><span class="mono">ID <?= e((string) $k) ?></span> (<?= (int) $n ?>) <?php endforeach; endif; endforeach; ?></span></div>
        <?php else: ?>
        <div class="msg err"><?= icon('alert') ?><span>Testabruf fehlgeschlagen: <?= e($test['error']) ?></span></div>
        <?php endif; ?>
      <?php endif; ?>
      <form method="post" action="<?= $action('save') ?>" class="stack" autocomplete="off">
        <?= csrf_field() ?>
        <section class="card stack">
          <h3><?= icon('radio') ?>Divera247 (nur lesend)</h3>
          <?= $toggle('enabled', (bool) $d['enabled'], 'Anbindung an', 'Abfrage nur serverseitig, der Schlüssel kommt nie in den Browser') ?>
          <div class="secret">
            <label class="f" style="flex:1;min-width:240px">API-Schlüssel (Accesskey) <input class="in" type="password" name="api_key" autocomplete="off" placeholder="<?= $d['api_key_enc'] ? 'hinterlegt ✓ – leer lassen zum Behalten' : 'nicht hinterlegt' ?>"></label>
            <?php if ($d['api_key_enc']): ?><label class="check"><input type="checkbox" name="api_key_clear" value="1"> Schlüssel entfernen</label><?php endif; ?>
          </div>
          <div class="row">
            <label class="f">Zeitfenster (Stunden) <input class="in" type="number" min="1" max="168" name="window_hours" value="<?= (int) $d['window_hours'] ?>"></label>
            <label class="f">Zeitlimit (Sekunden) <input class="in" type="number" min="1" max="10" name="timeout_seconds" value="<?= (int) $d['timeout_seconds'] ?>"></label>
          </div>
          <?= $toggle('incident_enabled', (bool) $d['incident_enabled'], 'Für Einsätze: Alarme anbieten') ?>
          <?= $toggle('exercise_enabled', (bool) $d['exercise_enabled'], 'Für Übungen: Termine anbieten') ?>
          <?= $toggle('quick_entry_enabled', (bool) $d['quick_entry_enabled'], 'Auch über QR-Schnellerfassung abfragen') ?>
        </section>
        <section class="card stack">
          <h3><?= icon('users') ?>Personal &amp; Fahrzeuge</h3>
          <label class="f">Personalquelle <select class="in" name="personnel_source"><option value="local"<?= $d['personnel_source'] === 'local' ? ' selected' : '' ?>>EÜDOK-Liste</option><option value="divera"<?= $d['personnel_source'] === 'divera' ? ' selected' : '' ?>>Divera247 (live)</option></select></label>
          <?= $toggle('vehicles_from_divera', (bool) $d['vehicles_from_divera'], 'Fahrzeuge live aus Divera247') ?>
        </section>
        <section class="card stack">
          <h3><?= icon('check') ?>Rückmeldestatus „war beim Einsatz dabei“</h3>
          <?php if (!$statusList): ?>
            <p class="hint">Liste erscheint nach Speichern des Schlüssels und einem Testabruf.</p>
          <?php else: ?>
            <div class="req-list">
            <?php foreach ($statusList as $sid => $label): ?>
              <label class="check"><input type="checkbox" name="attendance[]" value="<?= e((string) $sid) ?>"<?= isset($chosen[(string) $sid]) ? ' checked' : '' ?>> <?= e($label) ?></label>
              <input type="hidden" name="status_label[<?= e((string) $sid) ?>]" value="<?= e($label) ?>">
            <?php endforeach; ?>
            </div>
            <?php foreach ($chosen as $sid => $label): if (isset($statusList[$sid]) && $statusList[$sid] !== $label && Divera::configured()): ?>
              <div class="msg warn"><?= icon('alert') ?><span>Status „<?= e($label) ?>“ heißt in Divera jetzt „<?= e($statusList[$sid]) ?>“ – bitte prüfen und speichern.</span></div>
            <?php endif; endforeach; ?>
          <?php endif; ?>
          <p class="hint">Mehrfachauswahl. UG-Drohne: „JA zur Drohne“ und „JA zur ES“.</p>
          <label class="f">Weitere Antwort-IDs (mit Komma) <input class="in" name="attendance_extra" inputmode="numeric" placeholder="z. B. 123456, 123457"><span class="hint">Eigene Antwortoptionen am Alarm erscheinen erst, wenn jemand so geantwortet hat. Der Testabruf zeigt dann ihre IDs unter „Rückmeldungen in Alarmen“.</span></label>
          <?= $toggle('event_accept_counts', (bool) $d['event_accept_counts'], 'Übungen: Zusage zählt als eingesetzt') ?>
          <label class="f">Antwort-IDs für „Zusage“ bei Terminen (mit Komma) <input class="in" name="event_accept_ids" value="<?= e(implode(', ', $d['event_accept_ids'])) ?>"><span class="hint">Nach dem Testabruf oben ablesbar („Rückmeldungen in Terminen“).</span></label>
        </section>
        <section class="card stack">
          <h3><?= icon('radio') ?>Standard-Alarmierungsquelle bei Übernahme</h3>
          <div class="row">
            <?php foreach (['incident' => 'Einsätze (Alarme)', 'exercise' => 'Übungen (Termine)'] as $k => $l): ?>
            <label class="f"><?= e($l) ?> <select class="in" name="default_alert_<?= $k ?>"><option value="">– keine –</option><?php foreach ($sources as $s): ?><option value="<?= e($s['id']) ?>"<?= ($d['default_alert_source'][$k] ?? '') === $s['id'] ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></label>
            <?php endforeach; ?>
          </div>
        </section>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>
      <form method="post" action="<?= $action('test-divera') ?>"><?= csrf_field() ?><button class="btn"<?= $d['api_key_enc'] ? '' : ' disabled' ?>><?= icon('refresh') ?>Testabruf</button></form>

<?php elseif ($section === 'users'):
    $people = Settings::masterData()['personnel'];
    if (Divera::personnelFromDivera()) {
        $people = Divera::personnel()['items'];
    }
    $grid = 'grid-template-columns:minmax(0,1fr) minmax(0,1.2fr) minmax(0,1.2fr) minmax(0,1fr) 90px 64px';
    $userRow = static function ($i, array $u) use ($cell, $swCell, $people, $grid, $user): string {
        $opts = '<option value="">– keine Person –</option>';
        foreach ($people as $p) {
            $opts .= '<option value="' . e($p['id']) . '"' . (($u['person_id'] ?? '') === $p['id'] ? ' selected' : '') . '>' . e($p['name']) . '</option>';
        }
        $self = ($u['id'] ?? '') === $user['id'];
        return '<div class="ed-row' . (isset($u['id']) && empty($u['active']) ? ' inactive' : '') . '" style="' . $grid . '">'
            . '<input type="hidden" name="users[' . $i . '][id]" value="' . e($u['id'] ?? '') . '">'
            . $cell('Benutzername', '<input class="in" name="users[' . $i . '][username]" value="' . e($u['username'] ?? '') . '" autocapitalize="none" autocomplete="off">', 'wide')
            . $cell('Anzeigename', '<input class="in" name="users[' . $i . '][display_name]" value="' . e($u['display_name'] ?? '') . '">', 'wide')
            . $cell('Person im Personal', '<select class="in" name="users[' . $i . '][person_id]">' . $opts . '</select>', 'wide')
            . $cell(isset($u['id']) ? 'Neues Passwort (leer = unverändert)' : 'Passwort', '<input class="in" type="password" name="users[' . $i . '][password]" autocomplete="new-password">', 'wide')
            . $swCell('Darf Einstellungen ändern', 'users[' . $i . '][can_manage_settings]', !empty($u['can_manage_settings']), $self ? ' disabled' : '')
            . $swCell('aktiv', 'users[' . $i . '][active]', !isset($u['id']) || !empty($u['active']), $self ? ' disabled' : '')
            . '</div>';
    };
    ?>
      <form method="post" action="<?= $action('save') ?>" class="card" autocomplete="off">
        <?= csrf_field() ?>
        <h3><?= icon('user') ?>Zugänge</h3>
        <p class="hint" style="margin-bottom:10px">Passwort leer lassen = unverändert (mind. 10 Zeichen). Die zugeordnete Person ist in neuen Berichten automatisch eingetragen. Deaktivieren beendet alle Anmeldungen.</p>
        <div class="ed-head" style="<?= $grid ?>"><span>Benutzername</span><span>Anzeigename</span><span>Person</span><span>Passwort</span><span title="darf Einstellungen ändern">Admin</span><span>aktiv</span></div>
        <div id="list-users"><?php foreach (Settings::get('users')['users'] as $i => $u) { echo $userRow($i, $u); } ?></div>
        <template id="list-users-tpl"><?= $userRow('__i__', []) ?></template>
        <button type="button" class="btn sm" data-add-row="list-users" style="margin-top:10px"><?= icon('plus') ?>Zugang hinzufügen</button>
        <div style="margin-top:14px"><button class="btn pri"><?= icon('check') ?>Speichern</button></div>
      </form>

<?php elseif ($section === 'qr'):
    $newId = is_string($_GET['new'] ?? null) ? $_GET['new'] : '';
    ?>
      <?php if ($newId !== '' && isset($_SESSION['qr_keys'][$newId]) && ($ne = QrAccess::find($newId))): ?>
      <section class="card stack" style="border-color:var(--accent)">
        <h3><?= icon('qr') ?>Neuer Link für „<?= e($ne['label']) ?>“</h3>
        <div class="keybox"><?= e(absolute_url('q/' . $_SESSION['qr_keys'][$newId])) ?></div>
        <p class="hint warn">Der Link ist nur jetzt sichtbar – gespeichert wird nur ein Hash. Jetzt die Druckvorlage öffnen und ausdrucken.</p>
        <a class="btn pri" href="<?= e(base_url('settings.php?sticker=' . rawurlencode($newId))) ?>" target="_blank"><?= icon('printer') ?>Druckvorlage (PDF) öffnen</a>
      </section>
      <?php endif; ?>

      <?php foreach (QrAccess::entries() as $q): ?>
      <section class="card stack">
        <h3><?= icon('qr') ?><?= e($q['label']) ?> <span class="r"><?= $q['active'] ? '<span class="bd ok">aktiv</span>' : '<span class="bd er">gesperrt</span>' ?></span></h3>
        <p class="hint">Dienst-Zugang <span class="mono"><?= e($q['service_account']) ?></span> · Schlüssel beginnt mit <span class="mono"><?= e($q['key_hint']) ?>…</span> · angelegt <?= e(fmt_datetime($q['created_at'])) ?><?= $q['renewed_at'] ? ' · erneuert ' . e(fmt_datetime($q['renewed_at'])) : '' ?> · PIN <?= $q['pin_enabled'] ? 'an' : 'aus' ?></p>
        <form method="post" action="<?= $action('qr-update') ?>" class="stack">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($q['id']) ?>">
          <div class="row">
            <label class="f">Bezeichnung <input class="in" name="label" value="<?= e($q['label']) ?>"></label>
            <label class="f"><?= $q['pin_enabled'] ? 'Neue PIN (leer = unverändert)' : 'PIN setzen (4–6 Ziffern)' ?> <input class="in" name="pin" inputmode="numeric" pattern="\d{4,6}" autocomplete="off"></label>
            <label class="f">PIN merken (Tage, 0 = jedes Mal) <input class="in" type="number" min="0" max="365" name="pin_remember_days" value="<?= (int) $q['pin_remember_days'] ?>"></label>
          </div>
          <div class="row" style="align-items:center">
            <?= $toggle('divera', (bool) $q['divera_enabled'], 'Divera-Abfrage über diesen QR-Code') ?>
            <?php if ($q['pin_enabled']): ?><label class="check" style="flex:0 1 auto"><input type="checkbox" name="pin_off" value="1"> PIN ausschalten</label><?php endif; ?>
          </div>
          <div class="row" style="flex-wrap:wrap">
            <button class="btn pri" style="flex:0 1 auto"><?= icon('check') ?>Speichern</button>
          </div>
        </form>
        <div class="row" style="gap:8px">
          <form method="post" action="<?= $action('qr-toggle') ?>" style="flex:0 1 auto"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($q['id']) ?>"><button class="btn sm"><?= icon($q['active'] ? 'ban' : 'check') ?><?= $q['active'] ? 'Sperren' : 'Entsperren' ?></button></form>
          <form method="post" action="<?= $action('qr-renew') ?>" style="flex:0 1 auto"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($q['id']) ?>"><button class="btn sm" data-confirm="Neuen Schlüssel erzeugen? Der alte Aufkleber funktioniert dann sofort nicht mehr."><?= icon('refresh') ?>Schlüssel erneuern</button></form>
          <form method="post" action="<?= $action('qr-delete') ?>" style="flex:0 1 auto"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($q['id']) ?>"><button class="btn sm danger" data-confirm="QR-Zugang endgültig löschen? Der Link wird ungültig."><?= icon('x') ?>Löschen</button></form>
        </div>
      </section>
      <?php endforeach; ?>

      <form method="post" action="<?= $action('qr-create') ?>" class="card stack">
        <?= csrf_field() ?>
        <h3><?= icon('plus') ?>QR-Zugang anlegen</h3>
        <label class="f">Bezeichnung (z. B. „MTW UG-Drohne“, „Gerätehaus“) <input class="in" name="label" required></label>
        <?= $toggle('divera', Divera::configured(), 'Divera-Abfrage über diesen QR-Code') ?>
        <p class="hint">Über den QR-Code kann man nur neue Berichte erstellen und das eigene PDF 24 h abrufen. Höchstens <?= QrAccess::RATE_LIMIT_PER_HOUR ?> Berichte pro Stunde. Aufkleber nur innen anbringen.</p>
        <button class="btn pri"><?= icon('qr') ?>Anlegen</button>
      </form>

<?php elseif ($section === 'logs'):
    $ch = is_string($_GET['ch'] ?? null) && isset(Log::CHANNELS[$_GET['ch']]) ? $_GET['ch'] : 'error';
    $lv = in_array($_GET['lv'] ?? '', ['debug', 'info', 'warning', 'error'], true) ? $_GET['lv'] : 'debug';
    $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    $rows = Log::tail($ch, 300, $lv, $q);
    $lvClass = ['debug' => 'dr', 'info' => 'ok', 'warning' => 'wa', 'error' => 'er'];
    $lvText = ['debug' => 'Debug', 'info' => 'Info', 'warning' => 'Warnung', 'error' => 'Fehler'];
    $sizes = [];
    foreach (Log::CHANNELS as $k => $l) {
        $f = \Euedok\Storage::path('logs/' . $k . '.log');
        $sizes[$k] = is_file($f) ? filesize($f) : 0;
    }
    ?>
      <section class="card stack">
        <h3><?= icon('server') ?>Protokolle <span class="r"><a class="btn sm" href="<?= e(base_url('settings.php?logs-zip=1')) ?>"><?= icon('archive') ?>Alle als ZIP</a></span></h3>
        <form method="get" class="toolbar" data-autosubmit>
          <input type="hidden" name="s" value="logs">
          <select class="in" name="ch" aria-label="Bereich">
            <?php foreach (Log::CHANNELS as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $ch ? ' selected' : '' ?>><?= e($l) ?> (<?= e(number_format($sizes[$k] / 1024, 0, ',', '.')) ?> KB)</option><?php endforeach; ?>
          </select>
          <select class="in" name="lv" aria-label="ab Stufe">
            <?php foreach ($lvText as $k => $l): ?><option value="<?= $k ?>"<?= $k === $lv ? ' selected' : '' ?>>ab <?= e($l) ?></option><?php endforeach; ?>
          </select>
          <input class="in" type="search" name="q" value="<?= e($q) ?>" placeholder="Text, Berichtsnummer, Anfrage-ID …">
          <button class="btn sm"><?= icon('search') ?>Anzeigen</button>
        </form>
        <?php if (!$rows): ?><p class="hint">Keine Einträge.</p><?php else: ?>
        <table class="tbl audit"><thead><tr><th>Zeit</th><th>Stufe</th><th>Ereignis</th><th>Meldung</th><th>Wer</th></tr></thead><tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td style="white-space:nowrap"><?= e(substr(str_replace('T', ' ', (string) $row['at']), 0, 19)) ?><br><span class="muted mono small"><?= e($row['request'] ?? '') ?></span></td>
            <td><span class="bd <?= $lvClass[$row['level']] ?? 'dr' ?>"><?= e($lvText[$row['level']] ?? $row['level']) ?></span></td>
            <td class="mono small"><?= e(($row['channel'] !== $ch ? $row['channel'] . '.' : '') . $row['event']) ?></td>
            <td><?= e($row['message']) ?>
              <?php if (!empty($row['context'])): ?><details><summary class="muted small" style="cursor:pointer">Details</summary><pre class="mono small" style="white-space:pre-wrap;word-break:break-word;margin-top:4px"><?= e(json_encode($row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif; ?></td>
            <td class="small"><?= e((string) ($row['user'] ?? '')) ?><br><span class="muted"><?= e((string) ($row['ip'] ?? '')) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </section>
      <form method="post" action="<?= $action('save') ?>" class="card stack">
        <?= csrf_field() ?>
        <h3><?= icon('sliders') ?>Protokollstufe</h3>
        <label class="f">Was wird mitgeschrieben? <select class="in" name="log_level">
          <?php foreach (['debug' => 'Debug – alles inkl. jeder Anfrage (nur zur Fehlersuche)', 'info' => 'Info – normaler Betrieb (empfohlen)', 'warning' => 'nur Warnungen und Fehler', 'error' => 'nur Fehler'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= ($unit['log_level'] ?? 'info') === $k ? ' selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?></select></label>
        <p class="hint">Je Datei höchstens <?= Log::MAX_BYTES / 1048576 ?> MB, danach wird umbenannt (<?= Log::KEEP ?> alte Dateien bleiben). Divera-Rohdaten bei Fehlern und Übernahmen werden nach <?= Log::RAW_DAYS ?> Tagen gelöscht. Passwörter, PINs und Schlüssel werden nie geschrieben.</p>
        <button class="btn pri"><?= icon('check') ?>Speichern</button>
      </form>

<?php elseif ($section === 'audit'): $rows = Log::readAudit(300); ?>
      <section class="card">
        <h3><?= icon('list') ?>Änderungsprotokoll <span class="r muted small">letzte <?= count($rows) ?></span></h3>
        <?php if (!$rows): ?><p class="hint">Noch keine Einträge.</p><?php else: ?>
        <table class="tbl audit"><thead><tr><th>Wann</th><th>Wer</th><th>Bereich</th><th>Was</th></tr></thead><tbody>
          <?php foreach ($rows as $a): ?><tr><td><?= e(fmt_datetime($a['at'])) ?></td><td><?= e($a['by']) ?></td><td><?= e($a['area']) ?></td><td><?= e($a['change']) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </section>
      <form method="post" action="<?= $action('rebuild-index') ?>" class="card stack">
        <?= csrf_field() ?>
        <h3><?= icon('refresh') ?>Wartung</h3>
        <p class="hint">Übersicht aus den Berichtsdateien neu aufbauen (z. B. nach dem Zurückspielen einer Sicherung).</p>
        <button class="btn"><?= icon('refresh') ?>Übersicht neu aufbauen</button>
        <p class="hint">EÜDOK <?= e(EUEDOK_VERSION) ?> · PHP <?= e(PHP_VERSION) ?></p>
      </form>
<?php endif; ?>
    </div>
  </div>
</main>
