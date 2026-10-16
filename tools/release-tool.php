<?php
declare(strict_types=1);

/*
 * EÜDOK – Release-Werkzeug (nur lokal auf dem Entwickler-PC, wird nicht ausgeliefert)
 *
 *   php tools/release-tool.php keygen               Signaturschlüssel einmalig erzeugen
 *   php tools/release-tool.php zip <ordner> <zip>   Release-ZIP bauen (Pfade mit „/“, feste Reihenfolge)
 *   php tools/release-tool.php sign <zip>           <zip>.sig erzeugen (Ed25519, Base64)
 *   php tools/release-tool.php verify <zip>         Signatur mit dem Schlüssel in app/Updater.php prüfen
 *
 * Der private Schlüssel liegt NUR unter %USERPROFILE%\.euedok\release-signing.key – nie ins Repo.
 * Ohne ihn lassen sich keine Updates mehr signieren (dann einmal per FTP austauschen und neuen Schlüssel erzeugen).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$keyFile = (getenv('USERPROFILE') ?: getenv('HOME')) . '/.euedok/release-signing.key';
$cmd = $argv[1] ?? '';

function fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function secretKey(string $keyFile): string
{
    if (!is_file($keyFile)) {
        fail("Signaturschlüssel fehlt: $keyFile – zuerst „php tools/release-tool.php keygen“.");
    }
    $sk = base64_decode(trim((string) file_get_contents($keyFile)), true);
    if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail("Signaturschlüssel ungültig: $keyFile");
    }
    return $sk;
}

switch ($cmd) {
    case 'keygen':
        if (is_file($keyFile)) {
            fail("Es gibt bereits einen Signaturschlüssel: $keyFile – wird nicht überschrieben.");
        }
        @mkdir(dirname($keyFile), 0700, true);
        $pair = sodium_crypto_sign_keypair();
        file_put_contents($keyFile, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
        echo "Privater Schlüssel: $keyFile  (sicher aufbewahren, nicht weitergeben)\n";
        echo "Öffentlicher Schlüssel für app/Updater.php (PUBLIC_KEY):\n" . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
        break;

    case 'zip':
        [$dir, $zipFile] = [$argv[2] ?? '', $argv[3] ?? ''];
        if (!is_dir($dir) || $zipFile === '') {
            fail('Aufruf: zip <ordner> <zip>');
        }
        $dir = rtrim(str_replace('\\', '/', (string) realpath($dir)), '/');
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[] = substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1);
            }
        }
        sort($files, SORT_STRING);
        @unlink($zipFile);
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            fail("ZIP nicht anlegbar: $zipFile");
        }
        foreach ($files as $rel) {
            $zip->addFile($dir . '/' . $rel, $rel);
        }
        $zip->close();
        echo count($files) . " Dateien → $zipFile\n";
        break;

    case 'sign':
        $zipFile = $argv[2] ?? '';
        if (!is_file($zipFile)) {
            fail('Aufruf: sign <zip>');
        }
        $sig = sodium_crypto_sign_detached((string) file_get_contents($zipFile), secretKey($keyFile));
        file_put_contents($zipFile . '.sig', base64_encode($sig) . "\n");
        echo "Signatur → $zipFile.sig\n";
        break;

    case 'verify':
        $zipFile = $argv[2] ?? '';
        if (!preg_match("/PUBLIC_KEY = '([^']+)'/", (string) file_get_contents(dirname(__DIR__) . '/app/Updater.php'), $m)) {
            fail('PUBLIC_KEY in app/Updater.php nicht gefunden.');
        }
        $ok = is_file($zipFile . '.sig') && sodium_crypto_sign_verify_detached(
            (string) base64_decode(trim((string) file_get_contents($zipFile . '.sig'))),
            (string) file_get_contents($zipFile),
            (string) base64_decode($m[1])
        );
        if (!$ok) {
            fail('Signatur passt NICHT zum Schlüssel in app/Updater.php.');
        }
        echo "Signatur gültig.\n";
        break;

    default:
        fail('Befehle: keygen | zip <ordner> <zip> | sign <zip> | verify <zip>');
}
