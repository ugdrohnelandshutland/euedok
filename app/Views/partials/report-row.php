<?php
/** Eine Berichtskarte wie die Drohnen-Karten im Hub. @var array $r */
$type = \Euedok\Settings::reportType($r['type']) ?? ['style' => 'calm', 'label' => $r['type_label']];
$alert = ($type['style'] ?? 'calm') === 'alert';
$mail = $r['mail']['status'] ?? null;
?>
<a class="rc" href="<?= e(base_url('report.php?n=' . rawurlencode($r['number']))) ?>">
  <span class="blk <?= $alert ? 'e' : 'u' ?>"><?= icon($alert ? 'siren' : 'target') ?></span>
  <span class="tx">
    <b><?= e($r['subject'] !== '' ? $r['subject'] : '(noch ohne Thema)') ?></b>
    <span><?= e($r['number']) ?> · <?= e(fmt_datetime($r['start'] ?? null)) ?><?= ($r['location'] ?? '') !== '' ? ' · ' . e($r['location']) : '' ?></span>
  </span>
  <span class="st">
    <?php if (!empty($r['qr_access_id'])): ?><span class="bd qr">QR</span><?php endif; ?>
    <?php if ($r['status'] === 'draft'): ?>
      <span class="bd dr">Entwurf</span>
    <?php elseif ($mail === 'failed'): ?>
      <span class="bd wa"><?= icon('alert') ?>Mail-Fehler</span>
    <?php else: ?>
      <span class="bd ok">Abgeschlossen</span>
    <?php endif; ?>
  </span>
</a>
