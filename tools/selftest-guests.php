<?php
/*
 * Selbstcheck Gäste im Bericht (Reports::applyInput): g-Kennung nur mit Gast-Flag, Gast nie Einheitsführer.
 *   php tools/selftest-guests.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Reports;

$base = ['personnel' => [], 'vehicles' => [], 'equipment' => []];
$out = Reports::applyInput($base, [
    'personnel' => [
        ['id' => 'g-abc123', 'name' => ' Muster, Max ', 'guest' => true],
        ['id' => 'g-abc124', 'name' => 'Ohne Flag'],
        ['id' => 'p1', 'name' => 'Fake', 'guest' => true],
        ['id' => 'g-', 'name' => 'Kurz', 'guest' => true],
    ],
    'lead' => 'g-abc123',
]);
$ids = array_column($out['personnel'], 'id');
$ok = $ids === ['g-abc123'] && $out['personnel'][0]['name'] === 'Muster, Max' && $out['personnel'][0]['guest'] === true && $out['lead'] === null;
echo $ok ? "OK\n" : "FEHLER\n";
exit($ok ? 0 : 1);
