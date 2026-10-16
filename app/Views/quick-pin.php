<main class="auth">
  <form class="card" method="post" id="pinform">
    <?= csrf_field() ?>
    <div class="brand">
      <svg aria-hidden="true"><use href="<?= e(base_url('assets/icons/sprite.svg')) ?>#logo"/></svg>
      <span class="wm">EÜ<em>DOK</em></span>
      <span class="kick">Schnellerfassung · <?= e($entry['label']) ?></span>
    </div>
    <?php if ($locked): ?>
      <div class="msg err"><?= icon('lock') ?><span><?= e(\Euedok\QrAccess::lockText($locked)) ?></span></div>
    <?php else: ?>
      <p style="text-align:center;font-weight:700">PIN eingeben</p>
      <?php if ($error): ?><div class="msg err"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
      <input type="hidden" name="pin" id="pin">
      <div class="pindots" id="pindots"><i></i><i></i><i></i><i></i></div>
      <div class="pinpad" id="pinpad">
        <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $d): ?><button type="button" data-d="<?= $d ?>"><?= $d ?></button><?php endforeach; ?>
        <button type="button" data-d="del" aria-label="Löschen">⌫</button><button type="button" data-d="0">0</button><button type="submit" aria-label="Bestätigen" style="background:var(--fill);color:#fff;border-color:var(--fill)">OK</button>
      </div>
      <?php if ((int) $entry['pin_remember_days'] > 0): ?><p class="hint" style="text-align:center">Dieses Gerät merkt sich die PIN für <?= (int) $entry['pin_remember_days'] ?> Tage.</p><?php endif; ?>
    <?php endif; ?>
  </form>
</main>
<script src="<?= e(asset('js/pin.js')) ?>" defer></script>
