<?php
/** Entwurf löschen (mit Rückfrage). @var string $number @var string $back */
$small = $small ?? true;
?>
<form method="post" action="<?= e(base_url('index.php?action=discard')) ?>" class="discard-form">
  <?= csrf_field() ?>
  <input type="hidden" name="n" value="<?= e($number) ?>">
  <input type="hidden" name="back" value="<?= e($back ?? 'index.php') ?>">
  <button class="btn danger<?= $small ? ' sm' : ' block' ?>" data-confirm="Entwurf <?= e($number) ?> endgültig löschen? Das lässt sich nicht rückgängig machen."><?= icon('trash') ?>Entwurf löschen</button>
</form>
