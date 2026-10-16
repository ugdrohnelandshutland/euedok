<?php
use Euedok\Settings;

$types = Settings::activeReportTypes();
$big = array_slice($types, 0, 2);
$more = array_slice($types, 2);
?>
<main class="wrap">
  <div>
    <div class="kick"><?= e(Settings::unit()['name']) ?></div>
    <h1>Hallo <?= e(explode(' ', $user['display_name'])[0]) ?></h1>
  </div>

  <div class="hero">
    <?php foreach ($big as $t): $alert = ($t['style'] ?? '') === 'alert'; ?>
    <form method="post" action="<?= e(base_url('index.php?action=new')) ?>">
      <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($t['id']) ?>">
      <button class="rc big">
        <span class="blk <?= $alert ? 'e' : 'u' ?>"><?= icon($alert ? 'siren' : 'target') ?><small><?= e($t['label']) ?></small></span>
        <span class="tx"><b><?= e(\Euedok\Reports::newLabel($t)) ?></b><span><?= e(\Euedok\Reports::documentTitle($t)) ?> anlegen</span></span>
        <span class="go"><span><?= icon('plus') ?></span></span>
      </button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php if ($more): ?>
  <div class="chips">
    <?php foreach ($more as $t): ?>
    <form method="post" action="<?= e(base_url('index.php?action=new')) ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= e($t['id']) ?>">
      <button class="chip"><?= icon('plus') ?><?= e($t['label']) ?></button></form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <section class="stack">
    <div class="sec-t">Offene Entwürfe <span><?= count($drafts) ?></span></div>
    <?php if (!$drafts): ?>
      <div class="empty">Keine offenen Entwürfe.</div>
    <?php else: ?>
      <div class="list"><?php foreach ($drafts as $r): ?>
        <div class="rc-line">
          <?php render('partials/report-row', ['r' => $r]); ?>
          <form method="post" action="<?= e(base_url('index.php?action=discard')) ?>">
            <?= csrf_field() ?><input type="hidden" name="n" value="<?= e($r['number']) ?>">
            <button class="del-tile" data-confirm="Entwurf <?= e($r['number']) ?> endgültig löschen? Das lässt sich nicht rückgängig machen." aria-label="Entwurf <?= e($r['number']) ?> löschen"><?= icon('trash') ?><span>Löschen</span></button>
          </form>
        </div>
      <?php endforeach; ?></div>
    <?php endif; ?>
  </section>

  <section class="stack">
    <div class="sec-t">Letzte Berichte <a href="<?= e(base_url('index.php?view=reports')) ?>">Alle anzeigen</a></div>
    <?php if (!$recent): ?>
      <div class="empty">Noch keine abgeschlossenen Berichte.</div>
    <?php else: ?>
      <div class="list"><?php foreach ($recent as $r) { render('partials/report-row', ['r' => $r]); } ?></div>
    <?php endif; ?>
  </section>
</main>
