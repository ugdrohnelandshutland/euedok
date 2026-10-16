<?php
use Euedok\Settings;

$mailLabels = ['sent' => ['ok', 'versendet'], 'failed' => ['wa', 'Fehler'], 'off' => ['dr', 'aus']];
?>
<main class="wrap">
  <div class="row" style="align-items:center">
    <h1 style="flex:1">Berichte</h1>
    <div style="flex:0 0 auto;display:flex;gap:8px">
      <a class="btn sm" href="<?= e(base_url('download.php?zip=' . (int) $filter['year'])) ?>"><?= icon('archive') ?>ZIP <?= (int) $filter['year'] ?></a>
      <a class="btn sm" href="<?= e(base_url('download.php?zip=all')) ?>"><?= icon('archive') ?>ZIP alle</a>
    </div>
  </div>

  <form class="toolbar" method="get" data-autosubmit>
    <input type="hidden" name="view" value="reports">
    <input class="in" type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Nummer, Thema oder Ort suchen …">
    <select class="in" name="year" aria-label="Jahr">
      <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $y === $filter['year'] ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
    </select>
    <select class="in" name="type" aria-label="Berichtsart">
      <option value="">Alle Arten</option>
      <?php foreach (Settings::unit()['report_types'] as $t): ?><option value="<?= e($t['id']) ?>"<?= $t['id'] === $filter['type'] ? ' selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?>
    </select>
    <select class="in" name="status" aria-label="Status">
      <option value="">Alle</option>
      <option value="draft"<?= $filter['status'] === 'draft' ? ' selected' : '' ?>>Entwürfe</option>
      <option value="closed"<?= $filter['status'] === 'closed' ? ' selected' : '' ?>>Abgeschlossen</option>
    </select>
    <button class="btn sm"><?= icon('search') ?>Suchen</button>
  </form>

  <?php if (!$rows): ?>
    <div class="empty">Keine Berichte gefunden.</div>
  <?php else: ?>
  <table class="tbl">
    <thead><tr><th>Nummer</th><th>Datum</th><th>Thema / Ort</th><th>Status</th><th>Mail</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $type = Settings::reportType($r['type']);
        $alert = ($type['style'] ?? '') === 'alert';
        $m = $mailLabels[$r['mail']['status'] ?? ''] ?? null; ?>
      <tr class="link-row" data-href="<?= e(base_url('report.php?n=' . rawurlencode($r['number']))) ?>">
        <td><span class="bd <?= $alert ? 'e' : 'u' ?>"><?= e($r['type_label']) ?></span> <a class="num" href="<?= e(base_url('report.php?n=' . rawurlencode($r['number']))) ?>"><?= e($r['number']) ?></a>
          <?php if (!empty($r['qr_access_id'])): ?> <span class="bd qr">QR</span><?php endif; ?></td>
        <td data-label="Beginn"><?= e(fmt_datetime($r['start'] ?? null)) ?></td>
        <td data-label="Thema / Ort"><span><b><?= e($r['subject'] ?: '(noch ohne Thema)') ?></b><br><span class="muted small"><?= e($r['location']) ?></span></span></td>
        <td data-label="Status"><?= $r['status'] === 'draft' ? '<span class="bd dr">Entwurf</span>' : '<span><span class="bd ok">Abgeschlossen</span>' . (!empty($r['correction']) ? ' <span class="muted small">Korr. ' . (int) $r['correction'] . '</span>' : '') . '</span>' ?></td>
        <td data-label="Mail"<?= $m ? '' : ' class="empty-cell"' ?>><?php if ($m): ?><span class="bd <?= $m[0] ?>" title="<?= e($r['mail']['error'] ?? '') ?>"><?= e($m[1]) ?></span><?php endif; ?></td>
        <td class="act">
          <?php if ($r['status'] === 'draft'): ?>
            <a class="btn sm" href="<?= e(base_url('report.php?n=' . rawurlencode($r['number']))) ?>"><?= icon('edit') ?>Bearbeiten</a>
            <?php render('partials/discard-button', ['number' => $r['number'], 'back' => 'index.php?' . http_build_query(['view' => 'reports'] + array_filter($filter))]); ?>
          <?php endif; ?>
          <?php if ($r['status'] === 'closed'): ?>
            <a class="btn sm" href="<?= e(base_url('download.php?n=' . rawurlencode($r['number']) . '&f=pdf')) ?>"><?= icon('file') ?>PDF</a>
            <a class="btn sm" href="<?= e(base_url('download.php?n=' . rawurlencode($r['number']) . '&f=json')) ?>"><?= icon('json') ?>JSON</a>
            <?php if (($r['mail']['status'] ?? '') === 'failed'): ?>
            <form method="post" action="<?= e(base_url('index.php?action=resend')) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="n" value="<?= e($r['number']) ?>"><button class="btn sm"><?= icon('send') ?>Erneut senden</button></form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</main>
