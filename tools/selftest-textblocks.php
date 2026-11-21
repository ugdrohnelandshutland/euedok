<?php
/*
 * Selbstcheck der Textbaustein-Aussaat (Settings::merge): nur bei fehlendem Schlüssel, nie bei leerer Liste.
 *   php tools/selftest-textblocks.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Defaults;
use Euedok\Settings;

$merge = new ReflectionMethod(Settings::class, 'merge');
$merge->setAccessible(true);
$d = Defaults::masterData();
$seeded = $merge->invoke(null, $d, ['personnel' => []]);
$kept = $merge->invoke(null, $d, ['text_blocks' => []]);
$ok = count($seeded['text_blocks']) === count(Defaults::textBlocks()) && $kept['text_blocks'] === [];
echo $ok ? "OK\n" : "FEHLER\n";
exit($ok ? 0 : 1);
