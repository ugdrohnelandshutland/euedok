<?php
/*
 * Selbstcheck der Wiederholung im Update-Selbsttest (Updater::selfTestWithRetry), ohne Netz und ohne Warten:
 *   php tools/selftest-retry.php
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use Euedok\Updater;

$run = static function (array $results): array {
    $waits = [];
    $calls = 0;
    $r = Updater::selfTestWithRetry(
        static function () use (&$calls, $results) { return $results[$calls++]; },
        static function (int $s) use (&$waits): void { $waits[] = $s; }
    );
    return [$r, $calls, $waits];
};

$cases = [
    'Erfolg im 3. Versuch (Server erkennt den neuen Ordner verzögert)' => [[false, false, true], [true, 3, [2, 4]]],
    'alle 4 Versuche scheitern → zurückrollen' => [[false, false, false, false], [false, 4, [2, 4, 8]]],
    'nicht erreichbar → sofort abbrechen, kein Warten' => [[null], [null, 1, []]],
];
$failed = 0;
foreach ($cases as $name => [$in, $want]) {
    $got = $run($in);
    $ok = $got === $want;
    $failed += $ok ? 0 : 1;
    echo $ok ? 'ok    ' : 'FEHLER', ' ', $name, $ok ? '' : ' – erhalten: ' . json_encode($got), "\n";
}
exit($failed ? 1 : 0);
