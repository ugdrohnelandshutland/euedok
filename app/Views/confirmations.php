<?php
use Euedok\Confirmations;
use Euedok\Reports;
use Euedok\Settings;

/** @var array $r @var array $people @var array $values @var array $errors @var ?array $results @var ?string $notReady */
$title = Confirmations::title($r['type']);
$n = rawurlencode($r['report_number']);
$cc = Settings::get('confirmation')['cc'];
?>
<main class="wrap">
  <div class="kick"><?= e(Reports::documentTitle($r['type'])) ?> <?= e($r['report_number']) ?></div>
  <h1><?= e($title) ?>en</h1>
  <p class="muted"><?= e($r['subject']) ?> · <?= e(fmt_datetime($r['start'])) ?> – <?= e(fmt_datetime($r['end'])) ?></p>

  <?php if ($results !== null): ?>
  <section class="card stack" style="gap:8px">
    <h3><?= icon('send') ?>Ergebnis</h3>
    <?php foreach ($results as $res): ?>
    <div class="row" style="align-items:center;gap:8px">
      <span style="flex:1;min-width:200px"><b><?= e($res['name']) ?></b> <span class="muted small"><?= e($res['email']) ?></span><?php if (!$res['success']): ?><br><span class="small"><?= e($res['error'] ?? '') ?></span><?php endif; ?></span>
      <span class="bd <?= $res['success'] ? 'ok' : 'er' ?>" style="flex:none"><?= $res['success'] ? 'versendet' : 'Fehler' ?></span>
    </div>
    <?php endforeach; ?>
    <?php if (!$results): ?><p class="hint">Nichts versendet.</p><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php foreach ($errors as $err): ?><div class="msg err"><?= icon('alert') ?><span><?= e($err) ?></span></div><?php endforeach; ?>
  <?php if ($notReady !== null && !in_array($notReady, $errors, true)): ?><div class="msg warn"><?= icon('alert') ?><span><?= e($notReady) ?></span></div><?php endif; ?>

  <form method="post" action="<?= e(base_url('reports/' . $n . '/confirmations')) ?>" class="stack">
    <?= csrf_field() ?>
    <?php foreach ($people as $id => $p):
        $v = $values[$id] ?? null;
        $sel = $values ? !empty($v['sel']) : $p['email'] !== '';
        $key = 'p[' . e($id) . ']';
        $pt = Reports::personTimeText(['from' => $p['from'], 'to' => $p['to']], $r['start'], $r['end']);
    ?>
    <section class="card stack" style="gap:10px">
      <label class="check"><input type="checkbox" name="<?= $key ?>[sel]" value="1"<?= $sel ? ' checked' : '' ?>><span><b><?= e($p['name']) ?></b><?= $p['from'] !== $r['start'] || $p['to'] !== $r['end'] ? ' <span class="muted small">abweichend ' . e($pt) . '</span>' : '' ?></span></label>
      <div class="row">
        <label class="f">E-Mail <input class="in" type="email" inputmode="email" autocapitalize="none" name="<?= $key ?>[email]" value="<?= e($v['email'] ?? $p['email']) ?>" placeholder="name@example.org"></label>
        <label class="f">Von <input class="in" type="datetime-local" name="<?= $key ?>[from]" value="<?= e($v['from'] ?? $p['from']) ?>"></label>
        <label class="f">Bis <input class="in" type="datetime-local" name="<?= $key ?>[to]" value="<?= e($v['to'] ?? $p['to']) ?>"></label>
      </div>
    </section>
    <?php endforeach; ?>
    <p class="hint">Die Zeiten gelten nur für die Bestätigung – der Bericht bleibt unverändert. Je Person ein PDF und eine Mail<?= $cc ? ', in Kopie an ' . e(implode(', ', $cc)) : '' ?>.</p>
    <div class="row" style="flex-wrap:wrap">
      <button class="btn pri" style="flex:0 1 auto"<?= $notReady !== null ? ' disabled' : '' ?>><?= icon('send') ?>Bestätigungen senden</button>
      <a class="btn" style="flex:0 1 auto" href="<?= e(base_url('reports/' . $n)) ?>"><?= icon('cl') ?>Zurück zum Bericht</a>
    </div>
  </form>
</main>
