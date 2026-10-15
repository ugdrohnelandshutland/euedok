<?php $unit = \Euedok\Settings::unit(); ?>
<main class="auth">
  <form class="card" method="post" action="<?= e(base_url('index.php?action=login')) ?>">
    <?= csrf_field() ?>
    <div class="brand">
      <svg aria-hidden="true"><use href="<?= e(base_url('assets/icons/sprite.svg')) ?>#logo"/></svg>
      <span class="wm">EÜ<em>DOK</em></span>
      <span class="kick" style="text-align:center"><?= e($unit['name']) ?></span>
    </div>
    <?php foreach (flash() ?? [] as $f): ?>
    <div class="msg <?= e($f['kind']) ?>"><?= icon('alert') ?><span><?= e($f['text']) ?></span></div>
    <?php endforeach; ?>
    <label class="f">Benutzername <input class="in" name="username" autocomplete="username" autocapitalize="none" required autofocus></label>
    <label class="f">Passwort <input class="in" type="password" name="password" autocomplete="current-password" required></label>
    <label class="check"><input type="checkbox" name="remember" value="1" checked> Auf diesem Gerät angemeldet bleiben (<?= (int) $unit['remember_days'] ?> Tage)</label>
    <button class="btn pri block"><?= icon('lock') ?>Anmelden</button>
    <button type="button" class="btn sm" data-theme-toggle style="align-self:center"><?= icon('moon', 'theme-light') ?><?= icon('sun', 'theme-dark') ?>Tag / Nacht</button>
  </form>
</main>
