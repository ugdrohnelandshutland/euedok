<?php
use Euedok\Reports;
use Euedok\Settings;

$types = Settings::activeReportTypes();
?>
<header class="top">
  <span class="brand"><svg aria-hidden="true"><use href="<?= e(base_url('assets/icons/sprite.svg')) ?>#logo"/></svg><span class="wm">EÜ<em>DOK</em></span><span class="unit-label"><?= e(Settings::unit()['name']) ?></span></span>
  <div class="right"><button type="button" class="ib" data-theme-toggle aria-label="Tag / Nacht umschalten"><?= icon('moon', 'theme-light') ?><?= icon('sun', 'theme-dark') ?></button></div>
</header>
<main class="wrap narrow">
  <div>
    <div class="kick"><span class="bd qr">QR</span> Schnellerfassung · <?= e($entry['label']) ?></div>
    <h1>Bericht erstellen</h1>
  </div>
  <?php if ($draft): ?>
  <a class="rc" href="<?= e(base_url('quick-entry.php?n=' . rawurlencode($draft))) ?>">
    <span class="blk o"><?= icon('edit') ?></span>
    <span class="tx"><b>Entwurf <?= e($draft) ?> fortsetzen</b><span>auf diesem Gerät begonnen</span></span>
  </a>
  <?php endif; ?>
  <div class="stack">
    <?php foreach ($types as $t): $alert = ($t['style'] ?? '') === 'alert'; ?>
    <form method="post" action="<?= e(base_url('quick-entry.php?action=start')) ?>">
      <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($t['id']) ?>">
      <button class="rc big">
        <span class="blk <?= $alert ? 'e' : 'u' ?>"><?= icon($alert ? 'siren' : 'target') ?></span>
        <span class="tx"><b><?= e(Reports::documentTitle($t)) ?></b><span><?= e(Reports::newLabel($t)) ?> erfassen</span></span>
        <span class="go"><span><?= icon('plus') ?></span></span>
      </button>
    </form>
    <?php endforeach; ?>
  </div>
  <p class="hint">Über den QR-Code kannst du nur neue Berichte erstellen und abschließen. Korrekturen und die Übersicht gibt es nach der Anmeldung.</p>
</main>
