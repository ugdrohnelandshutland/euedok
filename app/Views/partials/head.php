<?php
/** @var string $title */
$nav = $nav ?? null;
$bodyClass = $bodyClass ?? '';
$plain = $plain ?? false;
$unit = \Euedok\Settings::isInstalled() ? \Euedok\Settings::unit() : ['name' => ''];
$user = \Euedok\Auth::user();
$apiBase = $apiBase ?? base_url('api.php');
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#102031">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · EÜDOK</title>
<link rel="manifest" href="<?= e(base_url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(base_url('assets/icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(base_url('assets/icons/icon-192.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($scripts ?? [] as $s): ?>
<script src="<?= e(asset($s)) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>" data-api="<?= e($apiBase) ?>">
<?php if (!$plain): ?>
<header class="top">
  <a class="brand" href="<?= e(base_url('index.php')) ?>">
    <svg aria-hidden="true"><use href="<?= e(base_url('assets/icons/sprite.svg')) ?>#logo"/></svg>
    <span class="wm">EÜ<em>DOK</em></span>
    <?php if ($unit['name'] !== ''): ?><span class="unit-label"><?= e($unit['name']) ?></span><?php endif; ?>
  </a>
  <?php if ($user): ?>
  <nav class="nav" aria-label="Hauptnavigation">
    <a href="<?= e(base_url('index.php')) ?>" class="<?= $nav === 'home' ? 'on' : '' ?>"><?= icon('home') ?>Start</a>
    <a href="<?= e(base_url('index.php?view=reports')) ?>" class="<?= $nav === 'reports' ? 'on' : '' ?>"><?= icon('list') ?>Berichte</a>
    <?php if (!empty($user['can_manage_settings'])): ?>
    <a href="<?= e(base_url('settings.php')) ?>" class="<?= $nav === 'settings' ? 'on' : '' ?>"><?= icon('sliders') ?>Einstellungen</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
  <div class="right">
    <?php if ($user): ?><span class="usr"><?= e($user['display_name']) ?></span><?php endif; ?>
    <button type="button" class="ib" data-theme-toggle title="Tag / Nacht umschalten" aria-label="Tag / Nacht umschalten"><?= icon('moon', 'theme-light') ?><?= icon('sun', 'theme-dark') ?></button>
    <?php if ($user): ?>
    <form method="post" action="<?= e(base_url('index.php?action=logout')) ?>"><?= csrf_field() ?><button class="ib" title="Abmelden" aria-label="Abmelden"><?= icon('logout') ?></button></form>
    <?php endif; ?>
  </div>
</header>
<?php endif; ?>
<?php foreach (flash() ?? [] as $f): ?>
<div class="wrap" style="padding-bottom:0;flex:none"><div class="msg <?= e($f['kind']) ?>"><?= icon($f['kind'] === 'ok' ? 'check' : 'alert') ?><span><?= e($f['text']) ?></span></div></div>
<?php endforeach; ?>
