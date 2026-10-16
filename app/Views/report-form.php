<?php
use Euedok\Settings;

/** @var array $cfg @var array $r */
$alert = $cfg['type']['style'] === 'alert';
$req = static fn (string $f) => in_array($f, $cfg['required'], true) ? ' req-dot' : '';
$quick = $cfg['mode'] === 'qr';
$showPeople = !empty($cfg['modules']['personnel']);
$showGear = !empty($cfg['modules']['equipment']) || !empty($cfg['modules']['vehicles']);
$gearLabel = !empty($cfg['modules']['equipment']) ? 'Geräte' : 'Fahrzeuge';
?>
<header class="tb">
  <a class="ib lbl" href="<?= e($cfg['urls']['back']) ?>"><?= icon('cl') ?><span class="ib-l">Zurück</span></a>
  <div class="ttl">
    <b><span class="bd <?= $alert ? 'e' : 'u' ?>"><?= e($cfg['type']['label']) ?></span><?= e($cfg['number']) ?></b>
    <span class="sub"><span class="mode"><?= $cfg['correction'] ? 'Korrektur' : ($quick ? 'QR · ' . e($qrLabel ?? '') : 'Entwurf') ?></span><span class="save" id="save-state" aria-live="polite">Gespeichert</span></span>
  </div>
  <button type="button" class="ib lbl" data-theme-toggle aria-label="Tag / Nacht umschalten"><?= icon('moon', 'theme-light') ?><?= icon('sun', 'theme-dark') ?><span class="ib-l">Tag/Nacht</span></button>
</header>
<div id="banners"></div>
<nav class="tabs" id="tabs" aria-label="Abschnitte">
  <button type="button" data-pane="basics" class="on">Basisdaten</button>
  <?php if ($showPeople): ?><button type="button" data-pane="people">Personal</button><?php endif; ?>
  <?php if ($showGear): ?><button type="button" data-pane="gear"><?= e($gearLabel) ?></button><?php endif; ?>
  <button type="button" data-pane="text">Bericht</button>
</nav>

<main class="form" id="form">
 <div class="fcol">
  <section class="pane on" id="pane-basics" data-pane="basics">
    <?php if ($quick): ?>
    <div class="card fieldset" data-req="created_by">
      <h3><?= icon('user') ?><span class="req-dot">Erstellt durch</span></h3>
      <select class="in" id="f-created_by" aria-label="Erstellt durch">
        <option value="">– bitte wählen –</option>
      </select>
      <span class="hint">Niemand ist angemeldet – wer füllt den Bericht aus? Das Gerät merkt sich die Auswahl.</span>
    </div>
    <?php endif; ?>

    <?php if ($cfg['divera']['lookup']): ?>
    <button type="button" class="btn" id="divera-btn" <?= !empty($r['divera']) ? 'hidden' : '' ?>><?= icon('radio') ?><?= $cfg['divera']['lookup'] === 'alarm' ? 'Alarm aus Divera übernehmen' : 'Termin aus Divera übernehmen' ?></button>
    <?php endif; ?>

    <div class="card fieldset">
      <h3><?= icon('clock') ?>Zeit</h3>
      <label class="f" data-req="start"><span class="<?= $req('start') ?>">Beginn</span><input class="in" type="datetime-local" id="f-start"></label>
      <div class="dt-row" data-req="end">
        <label class="f"><span class="<?= $req('end') ?>">Ende</span><input class="in" type="datetime-local" id="f-end"></label>
        <button type="button" class="btn" id="end-now"><?= icon('clock') ?>Jetzt</button>
      </div>
      <div class="dur">Dauer <b id="duration">–</b><span id="dur-hint" class="hint warn"></span></div>
    </div>

    <div class="card fieldset">
      <h3><?= icon('pin') ?>Ort &amp; Thema</h3>
      <label class="f" data-req="location"><span class="<?= $req('location') ?>">Ort</span><input class="in" id="f-location" autocomplete="off" placeholder="z. B. Ergolding, Isarauen"></label>
      <label class="f" data-req="subject"><span class="<?= $req('subject') ?>">Thema / Stichwort</span><input class="in" id="f-subject" list="subjects" autocomplete="off" placeholder="z. B. Vermisste Person"></label>
      <datalist id="subjects"></datalist>
    </div>

    <?php if (!empty($cfg['modules']['alert_source'])): ?>
    <div class="card fieldset" data-req="alert_source">
      <h3><?= icon('radio') ?><span class="<?= $req('alert_source') ?>">Alarmierung durch</span></h3>
      <div class="chips" id="alert-chips"></div>
      <input class="in" id="f-alert-other" placeholder="Andere Quelle eintragen …" hidden>
    </div>
    <?php endif; ?>

    <div class="card fieldset" id="report-fields-card" hidden>
      <h3><?= icon('sliders') ?>Weitere Angaben</h3>
      <div class="stack" id="report-fields"></div>
    </div>
  </section>

  <?php if ($showGear): ?>
  <section class="pane" id="pane-gear" data-pane="gear">
    <?php if (!empty($cfg['modules']['equipment'])): ?>
    <div class="card fieldset" data-req="equipment">
      <h3><?= icon('box') ?><span class="<?= $req('equipment') ?>">Eingesetzte Geräte</span><span class="r" id="equip-count"></span></h3>
      <div class="chips" id="equip-chips"></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($cfg['modules']['vehicles'])): ?>
    <div class="card fieldset" data-req="vehicles">
      <h3><?= icon('truck') ?><span class="<?= $req('vehicles') ?>">Eingesetzte Fahrzeuge</span><span class="r" id="veh-src"></span></h3>
      <div class="stack" id="veh-list"></div>
    </div>
    <div id="veh-sums" class="stack"></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
 </div>
 <div class="fcol">
  <?php if ($showPeople): ?>
  <section class="pane" id="pane-people" data-pane="people">
    <div class="card lead-line" data-req="lead">
      <?= icon('star', 'lead-star') ?>
      <span class="lead-tx"><small class="<?= $req('lead') ?>">Einheitsführer</small><b id="lead-name">–</b></span>
      <button type="button" class="btn sm" id="lead-change">Ändern</button>
    </div>
    <div class="card fieldset" data-req="personnel">
      <h3><?= icon('users') ?><span class="<?= $req('personnel') ?>">Eingesetztes Personal</span><span class="r" id="people-src"></span></h3>
      <input class="in" type="search" id="people-search" placeholder="Person suchen …" autocomplete="off">
      <div class="chips" id="people-chips"></div>
    </div>
    <div class="sec-t"><span>Eingesetzt (<span id="people-count">0</span>)</span></div>
    <div class="stack" id="people-list"></div>
    <div id="people-sums" class="stack"></div>
  </section>
  <?php endif; ?>


  <section class="pane" id="pane-text" data-pane="text">
    <div class="card fieldset">
      <h3><?= icon('file') ?><span class="<?= $req('narrative') ?>">Bericht / getroffene Maßnahmen</span></h3>
      <textarea class="in narrative" id="f-narrative" rows="12" data-req="narrative" placeholder="Was ist passiert, was wurde gemacht?"></textarea>
    </div>
    <?php if (!empty($cfg['modules']['defects'])): ?>
    <details class="card fieldset defects-box" id="defects-box"<?= trim((string) ($r['defects'] ?? '')) !== '' ? ' open' : '' ?>>
      <summary><?= icon('plus', 'when-closed') ?><?= icon('alert', 'when-open') ?>Defekte / Vorfälle <span class="hint">(optional)</span></summary>
      <textarea class="in" id="f-defects" rows="4" placeholder="keine"></textarea>
      <?php if ($cfg['defects_hint'] !== ''): ?><span class="hint warn"><?= e($cfg['defects_hint']) ?></span><?php endif; ?>
    </details>
    <?php endif; ?>
    <?php if (!$quick && !$cfg['correction'] && $cfg['status'] === 'draft'): ?>
    <div class="card fieldset">
      <h3><?= icon('trash') ?>Entwurf löschen</h3>
      <span class="hint">Löscht diesen Entwurf endgültig, z. B. wenn er versehentlich angelegt wurde.</span>
      <?php render('partials/discard-button', ['number' => $cfg['number'], 'back' => 'index.php', 'small' => false]); ?>
    </div>
    <?php endif; ?>
    <div class="card fieldset" id="divera-ref" hidden>
      <h3><?= icon('radio') ?>Divera247-Bezug</h3>
      <div id="divera-ref-text" class="small"></div>
    </div>
  </section>
 </div>
</main>

<div class="bb">
  <div class="prog"><span id="prog-text">Pflichtangaben</span><div class="bar" id="prog-bar"><i style="width:0"></i></div></div>
  <button type="button" class="btn" id="btn-next"><span>Weiter</span><?= icon('cr') ?></button>
  <button type="button" class="btn pri" id="btn-close"><?= icon('check') ?><span><?= $cfg['correction'] ? 'Korrektur abschließen' : 'Abschließen' ?></span></button>
</div>

<script type="application/json" id="cfg"><?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
