<?php
declare(strict_types=1);

namespace Euedok;

/** Verschlüsselt Geheimnisse (SMTP-Passwort, Divera-Schlüssel) mit libsodium. */
final class Crypto
{
    private const KEY_FILE = 'keys/secret.key';

    public static function ensureKey(): void
    {
        $file = Storage::path(self::KEY_FILE);
        if (!is_file($file)) {
            Storage::writeRaw(self::KEY_FILE, base64_encode(sodium_crypto_secretbox_keygen()));
        }
    }

    private static function key(): string
    {
        $file = Storage::path(self::KEY_FILE);
        if (!is_file($file)) {
            self::ensureKey();
        }
        $key = base64_decode(trim((string) file_get_contents($file)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Verschlüsselungsschlüssel in data/keys/ ist beschädigt.');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }

    /** Zufälliger, gut lesbarer Schlüssel (Crockford-Base32, 130 Bit) in 4er-Blöcken. */
    public static function readableKey(int $chars = 26): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out = '';
        for ($i = 0; $i < $chars; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }
        return implode('-', str_split($out, 4));
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', strtoupper(str_replace('-', '', $token)), self::key());
    }
}
