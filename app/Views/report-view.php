<?php
use Euedok\Pdf;
use Euedok\Settings;

/** @var array $r @var array $meta */
$type = Settings::reportType($r['type']['id']) ?? ['style' => 'calm', 'lead_label' => 'Leitung'];
$alert = ($type['style'] ?? '') === 'alert';
$defs = $r['field_definitions'] ?? [];
$lead = '';
foreach ($r['personnel'] as $p) {
    if ($p['id'] === $r['lead']) {
        $lead = $p['name'];
    }
}
$fv = static function (array $d, $v): string {
    if ($v === null || $v === '') {
        return '–';
    }
    if (($d['type'] ?? '') === 'checkbox') {
        return $v ? 'ja' : 'nein';
    }
    return (string) $v . (!empty($d['unit']) ? ' ' . $d['unit'] : '');
};
$actions = [
    'created' => 'angelegt', 'closed' => 'abgeschlossen', 'corrected' => 'korrigiert', 'mail_sent' => 'Mail versendet',
    'mail_failed' => 'Mailversand fehlgeschlagen', 'divera_imported' => 'Divera-Daten übernommen',
];
?>
<main class="wrap">
  <div class="row" style="align-items:center">
    <div style="flex:1;min-width:240px">
      <div class="kick"><?= e(\Euedok\Reports::documentTitle($r['type'])) ?></div>
      <h1 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><span class="bd <?= $alert ? 'e' : 'u' ?>"><?= e($r['type']['label']) ?></span><?= e($r['report_number']) ?>
        <?php if (!empty($meta['qr_access_id'])): ?><span class="bd qr">QR</span><?php endif; ?></h1>
      <p class="muted"><?= e($r['subject']) ?> · <?= e(fmt_datetime($r['start'])) ?></p>
    </div>
    <div style="flex:0 1 auto;display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn" href="<?= e(base_url('download.php?n=' . rawurlencode($r['report_number']) . '&f=pdf')) ?>" target="_blank"><?= icon('file') ?>PDF</a>
      <a class="btn" href="<?= e(base_url('download.php?n=' . rawurlencode($r['report_number']) . '&f=json')) ?>"><?= icon('json') ?>JSON</a>
      <a class="btn pri" href="<?= e(base_url('report.php?n=' . rawurlencode($r['report_number']) . '&edit=1')) ?>"><?= icon('edit') ?>Korrigieren</a>
    </div>
  </div>

  <?php if (($meta['mail']['status'] ?? '') === 'failed'): ?>
  <div class="msg warn"><?= icon('alert') ?><span style="flex:1">Mailversand fehlgeschlagen: <?= e($meta['mail']['error'] ?? '') ?></span>
    <form method="post" action="<?= e(base_url('index.php?action=resend')) ?>"><?= csrf_field() ?><input type="hidden" name="n" value="<?= e($r['report_number']) ?>"><button class="btn sm"><?= icon('send') ?>Erneut senden</button></form></div>
  <?php endif; ?>

  <div class="grid2">
    <section class="card">
      <h3><?= icon('clock') ?>Eckdaten</h3>
      <dl class="facts">
        <dt>Beginn</dt><dd><?= e(fmt_datetime($r['start'])) ?></dd>
        <dt>Ende</dt><dd><?= e(fmt_datetime($r['end'])) ?></dd>
        <dt>Dauer</dt><dd><?= e(fmt_duration($r['duration_min'])) ?></dd>
        <dt>Ort</dt><dd><?= e($r['location']) ?></dd>
        <dt>Thema</dt><dd><?= e($r['subject']) ?></dd>
        <?php if (!empty($r['alert_source'])): ?><dt>Alarmierung</dt><dd><?= e($r['alert_source']['label']) ?></dd><?php endif; ?>
        <dt><?= e($type['lead_label'] ?? 'Leitung') ?></dt><dd><?= e($lead) ?></dd>
        <?php foreach ($r['custom_fields'] ?? [] as $fid => $v): ?><dt><?= e($defs[$fid]['label'] ?? $fid) ?></dt><dd><?= e($fv($defs[$fid] ?? [], $v)) ?></dd><?php endforeach; ?>
      </dl>
    </section>
    <section class="card">
      <h3><?= icon('users') ?>Personal (<?= count($r['personnel']) ?>)</h3>
      <div class="stack" style="gap:6px">
        <?php foreach ($r['personnel'] as $p): ?>
        <div class="row" style="align-items:center;gap:8px;flex-wrap:nowrap">
          <span style="flex:1;min-width:0"><b><?= e($p['name']) ?></b><?= $p['id'] === $r['lead'] ? ' <span class="src">Leitung</span>' : '' ?></span>
          <?php foreach ($p['fields'] ?? [] as $fid => $v): ?><span class="muted small" style="flex:none;min-width:0"><?= e(($defs[$fid]['label'] ?? $fid) . ': ' . $fv($defs[$fid] ?? [], $v)) ?></span><?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <section class="card"><h3><?= icon('file') ?>Bericht / getroffene Maßnahmen</h3><div class="prose"><?= e($r['narrative'] ?: '–') ?></div></section>
  <?php if (trim($r['defects']) !== ''): ?><section class="card"><h3><?= icon('alert') ?>Defekte / Vorfälle</h3><div class="prose"><?= e($r['defects']) ?></div></section><?php endif; ?>

  <div class="grid2">
    <?php if (!empty($r['equipment'])): ?>
    <section class="card"><h3><?= icon('box') ?>Geräte</h3><div class="chips"><?php foreach ($r['equipment'] as $q): ?><span class="chip" style="cursor:default"><?= e($q['label']) ?></span><?php endforeach; ?></div></section>
    <?php endif; ?>
    <?php if (!empty($r['vehicles'])): ?>
    <section class="card"><h3><?= icon('truck') ?>Fahrzeuge</h3>
      <?php foreach ($r['vehicles'] as $v): ?><div><b><?= e($v['label']) ?></b> <span class="muted small"><?php foreach ($v['fields'] ?? [] as $fid => $val): ?><?= e(($defs[$fid]['label'] ?? $fid) . ': ' . $fv($defs[$fid] ?? [], $val)) ?> <?php endforeach; ?></span></div><?php endforeach; ?>
    </section>
    <?php endif; ?>
  </div>

  <section class="card">
    <h3><?= icon('list') ?>Verlauf</h3>
    <div class="hist">
      <?php foreach ($r['history'] as $h): ?>
      <div><?= e(fmt_datetime($h['at'])) ?> · <?= e($actions[$h['action']] ?? $h['action']) ?><?= isset($h['correction']) ? ' ' . (int) $h['correction'] : '' ?> · <?= e($h['by_name'] ?? $h['by']) ?><?= !empty($h['source']) ? ' (QR ' . e($h['qr_label'] ?? '') . ')' : '' ?><?= !empty($h['to']) ? ' → ' . e(implode(', ', (array) $h['to'])) : '' ?><?= !empty($h['error']) ? ' – ' . e($h['error']) : '' ?></div>
      <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin-top:8px">Abgeschlossen durch <?= e(Pdf::closedByText($r)) ?></p>
  </section>
</main>
