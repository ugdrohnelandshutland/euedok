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

/**
 * Einfache Liste: Bezeichnung (+ Zusatzspalten) + aktiv + Reihenfolge.
 * @param array<string,string> $cols Schlüssel → Überschrift (erste Spalte = Bezeichnung)
 */
$listEditor = static function (string $name, array $items, array $cols) use ($sw, $mv): string {
    $grid = 'grid-template-columns:' . implode(' ', array_fill(0, count($cols), 'minmax(0,1fr)')) . ' 64px 80px';
    $row = static function ($idx, array $it) use ($cols, $name, $sw, $mv, $grid): string {
        $h = '<div class="ed-row' . (empty($it['active']) && isset($it['id']) ? ' inactive' : '') . '" style="' . $grid . '">';
        $h .= '<input type="hidden" name="' . $name . '[' . $idx . '][id]" value="' . e($it['id'] ?? '') . '">';
        $first = true;
        foreach ($cols as $k => $title) {
            $h .= '<input class="in' . ($first ? ' wide' : '') . '" name="' . $name . '[' . $idx . '][' . $k . ']" value="' . e($it[$k] ?? '') . '" placeholder="' . e($title) . '" aria-label="' . e($title) . '">';
            $first = false;
        }
        $h .= '<label title="aktiv">' . $sw($name . '[' . $idx . '][active]', !isset($it['id']) || !empty($it['active'])) . '</label>' . $mv . '</div>';
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
        <div class="row">
          <label class="f">Leitung <input class="in" name="footer_lead" value="<?= e($unit['footer']['lead']) ?>"></label>
          <label class="f">Adresse <input class="in" name="footer_address" value="<?= e($unit['footer']['address']) ?>"></label>
          <label class="f">Kontakt <input class="in" name="footer_contact" value="<?= e($unit['footer']['contact']) ?>"></label>
        </div>
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
        $typeRow = static function ($i, array $t) use ($sw, $mv, $grid): string {
            $isNew = !isset($t['id']);
            $sel = static fn ($v, $cur) => $v === $cur ? ' selected' : '';
            return '<div class="ed-row" style="' . $grid . '">'
                . '<input type="hidden" name="types[' . $i . '][id]" value="' . e($t['id'] ?? '') . '">'
                . '<input class="in wide" name="types[' . $i . '][label]" value="' . e($t['label'] ?? '') . '" placeholder="Bezeichnung, z. B. Ausbildung">'
                . '<input class="in" name="types[' . $i . '][number_format]" value="' . e($t['number_format'] ?? '') . '" placeholder="A-{YYYY}-{NNN}">'
                . '<input class="in" name="types[' . $i . '][lead_label]" value="' . e($t['lead_label'] ?? '') . '" placeholder="Leitung">'
                . '<select class="in" name="types[' . $i . '][style]"><option value="calm"' . $sel('calm', $t['style'] ?? 'calm') . '>ruhig</option><option value="alert"' . $sel('alert', $t['style'] ?? '') . '>laut</option></select>'
                . '<select class="in" name="types[' . $i . '][divera_source]"><option value="">kein Divera</option><option value="alarm"' . $sel('alarm', $t['divera_source'] ?? '') . '>Alarme</option><option value="event"' . $sel('event', $t['divera_source'] ?? '') . '>Termine</option></select>'
                . '<label>' . $sw('types[' . $i . '][active]', $isNew || !empty($t['active'])) . '</label>' . $mv
                . ($isNew ? '<input class="in wide" name="types[' . $i . '][new_id]" placeholder="Technische ID (Englisch, z. B. training)" style="grid-column:1/4">' : '')
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
        $fieldRow = static function (string $kind, $i, array $f) use ($sw, $mv, $types, $grid): string {
            $n = 'fields_' . $kind . '[' . $i . ']';
            $isNew = !isset($f['id']);
            $opts = '';
            foreach ($types as $k => $l) {
                $opts .= '<option value="' . $k . '"' . (($f['type'] ?? 'number') === $k ? ' selected' : '') . '>' . $l . '</option>';
            }
            return '<div class="ed-row' . (!$isNew && empty($f['active']) ? ' inactive' : '') . '" style="' . $grid . '">'
                . '<input type="hidden" name="' . $n . '[id]" value="' . e($f['id'] ?? '') . '">'
                . '<input class="in wide" name="' . $n . '[label]" value="' . e($f['label'] ?? '') . '" placeholder="Bezeichnung">'
                . '<select class="in" name="' . $n . '[type]" data-field-type>' . $opts . '</select>'
                . '<input class="in" name="' . $n . '[unit]" value="' . e($f['unit'] ?? '') . '" placeholder="Einheit">'
                . '<label title="Pflicht" class="check" style="min-height:0;font-size:12px">' . $sw($n . '[required]', !empty($f['required'])) . '</label>'
                . '<label title="Summe anzeigen" class="check" style="min-height:0;font-size:12px">' . $sw($n . '[show_total]', !empty($f['show_total'])) . '</label>'
                . '<label title="aktiv">' . $sw($n . '[active]', $isNew || !empty($f['active'])) . '</label>' . $mv
                . '<input class="in wide" data-options name="' . $n . '[options]" value="' . e(implode(', ', $f['options'] ?? [])) . '" placeholder="Auswahlwerte, mit Komma getrennt" style="grid-column:1/-1"' . (($f['type'] ?? 'number') === 'select' ? '' : ' hidden') . '>'
                . ($isNew ? '<input class="in wide" name="' . $n . '[new_id]" placeholder="Technische ID (Englisch, z. B. flight_minutes)" style="grid-column:1/-1">' : '<span class="hint wide" style="grid-column:1/-1">ID: <span class="mono">' . e($f['id']) . '</span></span>')
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
    $userRow = static function ($i, array $u) use ($sw, $people, $grid, $user): string {
        $opts = '<option value="">– keine Person –</option>';
        foreach ($people as $p) {
            $opts .= '<option value="' . e($p['id']) . '"' . (($u['person_id'] ?? '') === $p['id'] ? ' selected' : '') . '>' . e($p['name']) . '</option>';
        }
        $self = ($u['id'] ?? '') === $user['id'];
        return '<div class="ed-row' . (isset($u['id']) && empty($u['active']) ? ' inactive' : '') . '" style="' . $grid . '">'
            . '<input type="hidden" name="users[' . $i . '][id]" value="' . e($u['id'] ?? '') . '">'
            . '<input class="in" name="users[' . $i . '][username]" value="' . e($u['username'] ?? '') . '" placeholder="Benutzername" autocapitalize="none" autocomplete="off">'
            . '<input class="in" name="users[' . $i . '][display_name]" value="' . e($u['display_name'] ?? '') . '" placeholder="Anzeigename">'
            . '<select class="in" name="users[' . $i . '][person_id]" aria-label="Person im Personal">' . $opts . '</select>'
            . '<input class="in" type="password" name="users[' . $i . '][password]" placeholder="' . (isset($u['id']) ? 'neues Passwort' : 'Passwort') . '" autocomplete="new-password">'
            . '<label class="check" style="font-size:12px;min-height:0" title="darf Einstellungen ändern">' . $sw('users[' . $i . '][can_manage_settings]', !empty($u['can_manage_settings']), $self ? ' disabled' : '') . '</label>'
            . '<label title="aktiv">' . $sw('users[' . $i . '][active]', !isset($u['id']) || !empty($u['active']), $self ? ' disabled' : '') . '</label>'
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
