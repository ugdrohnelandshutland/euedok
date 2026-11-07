<?php
use Euedok\Auth;

/** @var array $user @var ?array $person @var bool $forced */
$tfaActive = Auth::twoFactorActive($user);
$tfaRequired = Auth::twoFactorRequired($user);
?>
<main class="wrap">
  <div class="kick">Angemeldet als <?= e($user['username']) ?></div>
  <h1>Mein Zugang</h1>
  <p class="muted"><?= e($user['display_name']) ?><?= $person ? ' · Personal: ' . e($person['name']) . (!empty($person['email']) ? ' · ' . e($person['email']) : '') : '' ?></p>

  <?php if ($forced): ?>
  <div class="msg err"><?= icon('alert') ?><span>Dein Passwort wurde von einem Admin gesetzt. Bitte lege jetzt ein eigenes fest, bevor es weitergeht.</span></div>
  <?php elseif ($tfaRequired && !$tfaActive): ?>
  <div class="msg err"><?= icon('alert') ?><span>Für deinen Zugang ist die Zwei-Faktor-Anmeldung vorgeschrieben. Bitte richte sie jetzt ein, bevor es weitergeht.</span></div>
  <?php endif; ?>

  <div class="grid2" style="align-items:start">
  <form class="card stack" method="post" action="<?= e(base_url('account')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <h3><?= icon('key') ?>Passwort ändern</h3>
    <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
    <label class="f">Aktuelles Passwort <input class="in" type="password" name="current" autocomplete="current-password" required<?= $forced ? ' autofocus' : '' ?>></label>
    <label class="f">Neues Passwort (mind. 10 Zeichen) <input class="in" type="password" name="new" autocomplete="new-password" minlength="10" required></label>
    <label class="f">Neues Passwort wiederholen <input class="in" type="password" name="repeat" autocomplete="new-password" minlength="10" required></label>
    <p class="hint">Nach der Änderung bleibst du hier angemeldet. Andere Geräte und „angemeldet bleiben“ müssen sich neu anmelden.</p>
    <div><button class="btn pri"><?= icon('check') ?>Passwort ändern</button></div>
  </form>

  <section class="card stack">
    <h3><?= icon('shield') ?>Zwei-Faktor-Anmeldung <span class="r"><?= $tfaActive ? '<span class="bd ok">aktiv</span>' : '<span class="bd">aus</span>' ?><?= $tfaRequired ? ' <span class="bd">Pflicht</span>' : '' ?></span></h3>
    <?php if ($tfaActive): ?>
    <p class="hint">Eingerichtet am <?= e(fmt_datetime((string) $user['tfa_enabled_at'])) ?>. Nach dem Passwort fragt EÜDOK den 6-stelligen Code aus deiner Authenticator-App ab. Geräte mit „angemeldet bleiben“ fragen bis zum Ablauf nicht erneut.</p>
      <?php if ($tfaRequired): ?>
    <p class="hint">Ein Admin hat die Zwei-Faktor-Anmeldung für deinen Zugang vorgeschrieben. Neues Handy? Ein Admin kann sie zurücksetzen, danach richtest du sie hier neu ein.</p>
      <?php else: ?>
    <form method="post" action="<?= e(base_url('account')) ?>" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="tfa-disable">
      <label class="f">Aktueller Code zum Ausschalten <input class="in" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></label>
      <div><button class="btn danger" data-confirm="Zwei-Faktor-Anmeldung wirklich ausschalten?"><?= icon('x') ?>Ausschalten</button></div>
    </form>
      <?php endif; ?>
    <?php elseif ($forced): ?>
    <p class="hint">Zuerst das eigene Passwort festlegen, danach lässt sich die Zwei-Faktor-Anmeldung einrichten.</p>
    <?php else:
        $secret = Auth::twoFactorSetupSecret();
        $qr = (new \Mpdf\QrCode\Output\Svg())->output(new \Mpdf\QrCode\QrCode(Auth::twoFactorUri($user, $secret), 'M'), 200);
        ?>
    <p class="hint">Schützt deinen Zugang zusätzlich: Nach dem Passwort ist ein Code aus einer Authenticator-App nötig (z. B. Google Authenticator, Microsoft Authenticator, Aegis, 2FAS, Bitwarden).</p>
    <ol class="hint" style="margin:0;padding-left:18px">
      <li>In der App „Konto hinzufügen“ wählen und den QR-Code scannen.</li>
      <li>Kein Scannen möglich? Den Schlüssel von Hand eingeben (zeitbasiert, 6 Stellen).</li>
      <li>Den angezeigten Code unten eintragen – erst dann ist die Zwei-Faktor-Anmeldung aktiv.</li>
    </ol>
    <div class="tfa-qr" role="img" aria-label="QR-Code für die Authenticator-App"><?= preg_replace('/^<\?xml[^>]*>\s*/', '', (string) $qr) ?></div>
    <div class="f">Schlüssel <span class="mono tfa-secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></span></div>
    <form method="post" action="<?= e(base_url('account')) ?>" class="stack">
      <?= csrf_field() ?><input type="hidden" name="do" value="tfa-enable">
      <label class="f">Aktuelles Passwort <input class="in" type="password" name="current" autocomplete="current-password" required></label>
      <label class="f">Code aus der App <input class="in" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required<?= $tfaRequired ? ' autofocus' : '' ?>></label>
      <div><button class="btn pri"><?= icon('check') ?>Zwei-Faktor einschalten</button></div>
    </form>
    <?php endif; ?>
  </section>
  </div>
</main>
