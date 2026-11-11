<?php
declare(strict_types=1);

/*
 * PDF-Viewer in der App (pdf.js, lokal): in der installierten PWA hätte ein neues Fenster mit dem
 * nativen PDF-Viewer keinen Zurück-Weg. ?src= ist ein Pfad dieser Installation zu einer PDF-Route.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Auth;

Auth::requireLogin();

/** Prüft src gegen die bekannten PDF-Routen; liefert [Pfad relativ zur Basis, Titel, Rücksprung] oder null. */
function pdf_view_target(mixed $src): ?array
{
    $base = rtrim(base_url(), '/');
    if (!is_string($src) || strlen($src) > 300 || !str_starts_with($src, $base . '/') || preg_match('#[\x00-\x20\x7f\x5c]#', $src)
        || str_contains($src, '//') || str_contains($src, '..')) {
        return null;
    }
    $rel = substr($src, strlen($base) + 1);
    $seg = '[A-Za-z0-9._%-]+';
    if (preg_match("#^reports/($seg)/pdf$#", $rel, $m)) {
        return [$rel, rawurldecode($m[1]) . '.pdf', 'reports/' . $m[1]];
    }
    if (preg_match("#^reports/($seg)/confirmations/($seg\.pdf)$#", $rel, $m)) {
        return [$rel, rawurldecode($m[2]), 'reports/' . $m[1]];
    }
    if (preg_match("#^settings/confirmation\?preview=1(&grid=1)?(&t=$seg)?$#", $rel)) {
        return [$rel, 'Vorschau Einsatzbestätigung.pdf', 'settings/confirmation'];
    }
    if (preg_match("#^settings/qr\?sticker=$seg$#", $rel)) {
        return [$rel, 'QR-Aufkleber.pdf', 'settings/qr'];
    }
    return null;
}

$target = pdf_view_target($_GET['src'] ?? null);
if ($target === null) {
    http_response_code(400);
    render('partials/head', ['title' => 'PDF nicht verfügbar']);
    echo '<main class="wrap"><div class="msg err">' . icon('alert') . '<span>Dieses PDF kann nicht angezeigt werden.</span></div></main>';
    render('partials/foot', ['noBottomNav' => true]);
    exit;
}
[$rel, $name, $back] = $target;
$url = base_url($rel);

render('partials/head', ['title' => $name, 'plain' => true, 'bodyClass' => 'pdfv', 'scripts' => ['js/pdf-view.js']]);
?>
<div class="tb">
  <a class="ib" id="pv-back" href="<?= e(base_url($back)) ?>" title="Zurück" aria-label="Zurück"><?= icon('cl') ?></a>
  <div class="ttl"><b class="pv-name"><?= e($name) ?></b></div>
  <button type="button" class="ib" id="pv-out" title="Verkleinern" aria-label="Verkleinern"><?= icon('minus') ?></button>
  <button type="button" class="ib" id="pv-in" title="Vergrößern" aria-label="Vergrößern"><?= icon('plus') ?></button>
  <a class="ib" href="<?= e($url) ?>" download="<?= e($name) ?>" title="Herunterladen" aria-label="Herunterladen"><?= icon('download') ?></a>
</div>
<main id="pv" class="pv" data-src="<?= e($url) ?>">
  <noscript><div class="msg err"><?= icon('alert') ?><span>Für die Anzeige wird JavaScript benötigt. <a href="<?= e($url) ?>">PDF öffnen</a></span></div></noscript>
</main>
<?php render('partials/foot', ['noBottomNav' => true]);
