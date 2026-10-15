<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Settings;
use Euedok\Setup;

if (Settings::isInstalled()) {
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!Setup::requirementsMet()) {
        $error = 'Der Server erfüllt noch nicht alle Voraussetzungen.';
    } else {
        $error = Setup::install($_POST);
        if ($error === null) {
            flash('Eingerichtet. Weitere Einstellungen findest du unter „Einstellungen“.');
            redirect('settings.php');
        }
    }
}
$req = Setup::requirements();

render('partials/head', ['title' => 'Einrichtung', 'plain' => true]);
?>
<main class="wrap narrow">
  <div class="brand" style="gap:12px">
    <svg style="width:44px;height:44px"><use href="<?= e(base_url('assets/icons/sprite.svg')) ?>#logo"/></svg>
    <div><div class="wm" style="font-size:26px">EÜ<em>DOK</em></div><div class="kick">Einrichtung</div></div>
    <button type="button" class="ib" style="margin-left:auto" data-theme-toggle aria-label="Tag / Nacht umschalten"><?= icon('moon', 'theme-light') ?><?= icon('sun', 'theme-dark') ?></button>
  </div>
  <p class="muted">Einmalig: Name der Einheit, erster Zugang mit Einstellungsrecht und Startwerte. Alles lässt sich danach auf der Einstellungsseite ändern.</p>

  <?php if ($error): ?><div class="msg err"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

  <section class="card">
    <h3><?= icon('server') ?>1 · Server</h3>
    <div class="stack" style="gap:6px">
      <?php foreach ($req as $r): ?>
      <div class="row" style="align-items:center;gap:8px;flex-wrap:nowrap">
        <span class="bd <?= $r['ok'] ? 'ok' : 'er' ?>" style="flex:none;min-width:0"><?= $r['ok'] ? 'ok' : 'fehlt' ?></span>
        <span style="flex:1"><?= e($r['label']) ?> <span class="hint">· <?= e($r['hint']) ?></span></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <form method="post" class="stack">
    <?= csrf_field() ?>
    <section class="card stack">
      <h3><?= icon('shield') ?>2 · Einheit</h3>
      <label class="f">Name der Einheit <input class="in" name="unit_name" required value="<?= e(post_str('unit_name', 'UG-Drohne Landshut Land')) ?>"></label>
      <label class="f">Untertitel (optional) <input class="in" name="subtitle" value="<?= e(post_str('subtitle', 'Unterstützungsgruppe Drohne')) ?>"></label>
    </section>
    <section class="card stack">
      <h3><?= icon('user') ?>3 · Erster Zugang (darf Einstellungen ändern)</h3>
      <label class="f">Benutzername <input class="in" name="username" required autocomplete="username" autocapitalize="none" value="<?= e(post_str('username')) ?>"></label>
      <label class="f">Anzeigename <input class="in" name="display_name" required value="<?= e(post_str('display_name')) ?>" placeholder="z. B. Florian Hadersbeck"></label>
      <label class="f">Passwort (mind. 10 Zeichen) <input class="in" type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      <label class="f">Passwort wiederholen <input class="in" type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
    </section>
    <section class="card stack">
      <h3><?= icon('sparkle') ?>4 · Startwerte</h3>
      <label class="check"><input type="radio" name="preset" value="drone" checked> UG-Drohne: Alarmierungsquellen, Geräte, 5 Test-Personen, Flugminuten, Kilometer</label>
      <label class="check"><input type="radio" name="preset" value="empty"> Leer beginnen</label>
    </section>
    <button class="btn pri block"<?= Setup::requirementsMet() ? '' : ' disabled' ?>><?= icon('check') ?>Einrichten</button>
  </form>
</main>
<?php render('partials/foot');
