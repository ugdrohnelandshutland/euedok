<?php
/** Eine Berichtskarte wie die Drohnen-Karten im Hub. @var array $r */
$type = \Euedok\Settings::reportType($r['type']) ?? ['style' => 'calm', 'label' => $r['type_label']];
$alert = ($type['style'] ?? 'calm') === 'alert';
$mail = $r['mail']['status'] ?? null;
ob_start(); ?>
    <?php if (!empty($r['qr_access_id'])): ?><span class="bd qr">QR</span><?php endif; ?>
    <?php if ($r['status'] === 'draft'): ?>
      <span class="bd dr">Entwurf</span>
    <?php elseif ($mail === 'failed'): ?>
      <span class="bd wa"><?= icon('alert') ?>Mail-Fehler</span>
    <?php else: ?>
      <span class="bd ok">Abgeschlossen</span>
    <?php endif; ?>
<?php $badges = ob_get_clean(); ?>
<a class="rc" href="<?= e(base_url('reports/' . rawurlencode($r['number']))) ?>">
  <span class="blk <?= $alert ? 'e' : 'u' ?>"><?= icon($alert ? 'siren' : 'target') ?><small><?= e($type['label'] ?? $r['type_label']) ?></small></span>
  <span class="tx">
    <b><?= e($r['subject'] !== '' ? $r['subject'] : '(noch ohne Thema)') ?></b>
    <span><?= e($r['number']) ?> · <?= e(fmt_datetime($r['start'] ?? null)) ?><?= ($r['location'] ?? '') !== '' ? ' · ' . e($r['location']) : '' ?></span>
    <span class="st-m"><?= $badges ?></span>
  </span>
  <span class="st"><?= $badges ?></span>
</a>
