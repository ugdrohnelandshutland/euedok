<?php
declare(strict_types=1);

/* Web-App-Manifest mit Namen aus den Einstellungen – damit mehrere Organisationen
   auf einem Handy unterscheidbar sind („zum Startbildschirm hinzufügen“). */

require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Settings;

$unit = Settings::isInstalled() ? Settings::unit() : ['name' => '', 'app_name' => ''];
$short = trim((string) ($unit['app_name'] ?? '')) ?: 'EÜDOK';
$full = trim((string) ($unit['name'] ?? '')) !== '' ? 'EÜDOK – ' . trim((string) $unit['name']) : 'EÜDOK – Einsatz- & Übungsdokumentation';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode([
    // eindeutig je Installation, damit Android zwei Organisationen nicht als dieselbe App ansieht
    'id' => base_url('index.php') . '?app=' . rawurlencode((string) ($_SERVER['HTTP_HOST'] ?? 'euedok')),
    'name' => $full,
    'short_name' => $short,
    'start_url' => base_url('index.php'),
    'scope' => base_url(),
    'display' => 'standalone',
    'background_color' => '#09111c',
    'theme_color' => '#102031',
    'lang' => 'de',
    'icons' => [
        ['src' => base_url('assets/icons/icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
        ['src' => base_url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => base_url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
