<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

final class NonceValidator
{
    private const CIPHER = 'aes-256-cbc';

    public static function create(string $apiTokenHash): string
    {
        $key = self::deriveKey($apiTokenHash);
        $iv = random_bytes(16);
        $plaintext = (string) time();
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new \RuntimeException('Nonce encryption failed.');
        }

        $payload = base64_encode($iv . $ciphertext);
        $signature = hash_hmac('sha256', $payload, $key);

        return $payload . '.' . $signature;
    }

    public static function assertValid(string $apiTokenHash, int $maxAgeSeconds): void
    {
        if ($apiTokenHash === '') {
            throw new ApiException('API token hash is not configured.', 'token_not_configured', 500);
        }

        $nonce = self::getNonceFromHeaders();
        if ($nonce === '') {
            throw new ApiException('Missing API nonce.', 'missing_nonce', 401);
        }

        $parts = explode('.', $nonce, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ApiException('Invalid API nonce format.', 'invalid_nonce', 401);
        }

        $payload = $parts[0];
        $signature = $parts[1];
        $key = self::deriveKey($apiTokenHash);

        if (!hash_equals(hash_hmac('sha256', $payload, $key), $signature)) {
            throw new ApiException('Invalid API nonce signature.', 'invalid_nonce', 401);
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            throw new ApiException('Invalid API nonce payload.', 'invalid_nonce', 401);
        }

        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $timestamp = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($timestamp === false || !ctype_digit($timestamp)) {
            throw new ApiException('Invalid API nonce timestamp.', 'invalid_nonce', 401);
        }

        $age = time() - (int) $timestamp;
        if ($age < 0 || $age > $maxAgeSeconds) {
            throw new ApiException('API nonce expired.', 'nonce_expired', 401);
        }
    }

    private static function deriveKey(string $apiTokenHash): string
    {
        $key = hex2bin(strtolower($apiTokenHash));

        if ($key === false || strlen($key) !== 32) {
            throw new ApiException('API token hash is invalid.', 'token_not_configured', 500);
        }

        return $key;
    }

    private static function getNonceFromHeaders(): string
    {
        if (!empty($_SERVER['HTTP_X_API_NONCE'])) {
            return trim((string) $_SERVER['HTTP_X_API_NONCE']);
        }

        return '';
    }
}
