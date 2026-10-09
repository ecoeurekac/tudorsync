<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

/**
 * At-rest obfuscation for the TUDOR client secret before it goes into wp_options.
 *
 * WordPress has no built-in reversible secret storage (unlike Magento's Encrypted config
 * field, which is why the Magento module can simply mark the secret encrypted). This wraps
 * OpenSSL with a key derived from the site's own auth salts, so a leaked database dump does
 * not hand over the secret in clear text. It is deliberately modest: anyone who can already
 * read wp-config.php can recover the key, so this guards the database, not the server.
 *
 * Stored values carry a short version marker. Anything without it — a hand-entered or
 * migrated plain value — is returned unchanged, so decryption never corrupts an existing
 * setting. If OpenSSL or the salts are unavailable, the value is stored as-is.
 */
final class Crypto
{
    private const MARKER = 'tsenc1:';
    private const CIPHER = 'aes-256-cbc';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $key = self::key();

        if ($key === null || !function_exists('openssl_encrypt')) {
            return $plain;
        }

        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $cipherText = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($cipherText === false) {
            return $plain;
        }

        return self::MARKER . base64_encode($iv . $cipherText);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::MARKER)) {
            return $stored; // plain / migrated value, or empty
        }

        $key = self::key();

        if ($key === null || !function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::MARKER)), true);

        if ($raw === false) {
            return '';
        }

        $ivLength = (int) openssl_cipher_iv_length(self::CIPHER);

        if (strlen($raw) <= $ivLength) {
            return '';
        }

        $iv = substr($raw, 0, $ivLength);
        $cipherText = substr($raw, $ivLength);
        $plain = openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? '' : $plain;
    }

    private static function key(): ?string
    {
        // Derived from WordPress's own salts; both are defined in wp-config.php.
        $material = (defined('AUTH_KEY') ? (string) AUTH_KEY : '')
            . (defined('AUTH_SALT') ? (string) AUTH_SALT : '');

        if ($material === '') {
            return null;
        }

        return hash('sha256', $material, true);
    }
}
