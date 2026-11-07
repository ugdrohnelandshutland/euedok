<?php $unit = \Euedok\Settings::unit(); ?>
<main class="auth">
  <form class="card" method="post" action="<?= e(base_url('actions/code')) ?>">
    <?= csrf_field() ?>
    <div class="brand">
      <svg aria-hidden="true"><use href="<?= e(base_url('assets/icons/sprite.svg')) ?>#logo"/></svg>
      <span class="wm">EÜ<em>DOK</em></span>
      <span class="kick" style="text-align:center"><?= e($unit['name']) ?></span>
    </div>
    <?php foreach (flash() ?? [] as $f): ?>
    <div class="msg <?= e($f['kind']) ?>"><?= icon('alert') ?><span><?= e($f['text']) ?></span></div>
    <?php endforeach; ?>
    <label class="f">Code aus der Authenticator-App <input class="in" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></label>
    <button class="btn pri block"><?= icon('key') ?>Bestätigen</button>
    <p class="hint" style="text-align:center">Handy nicht dabei? Ein Admin kann die Zwei-Faktor-Anmeldung zurücksetzen.</p>
  </form>
  <form method="post" action="<?= e(base_url('actions/logout')) ?>" style="text-align:center;margin-top:10px"><?= csrf_field() ?><button class="btn sm">Abbrechen</button></form>
</main>
