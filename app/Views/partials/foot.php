<?php
$nav = $nav ?? null;
$user = \Euedok\Auth::user();
if ($user && empty($noBottomNav)): ?>
<nav class="bn" aria-label="Navigation">
  <a href="<?= e(base_url('index.php')) ?>" class="<?= $nav === 'home' ? 'on' : '' ?>"><?= icon('home') ?>Start</a>
  <a href="<?= e(base_url('index.php?view=reports')) ?>" class="<?= $nav === 'reports' ? 'on' : '' ?>"><?= icon('list') ?>Berichte</a>
  <?php if (!empty($user['can_manage_settings'])): ?>
  <a href="<?= e(base_url('settings.php')) ?>" class="<?= $nav === 'settings' ? 'on' : '' ?>"><?= icon('sliders') ?>Einstellungen</a>
  <?php endif; ?>
</nav>
<?php endif; ?>
</body>
</html>
