<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

final class NonceValidator
{
    public static function create(string $apiTokenHash): string
    {
        $key = self::deriveKey($apiTokenHash);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp, $key);

        return $timestamp . '.' . $signature;
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

        $timestamp = $parts[0];
        $signature = $parts[1];
        $key = self::deriveKey($apiTokenHash);

        if (!ctype_digit($timestamp)) {
            throw new ApiException('Invalid API nonce timestamp.', 'invalid_nonce', 401);
        }

        if (!hash_equals(hash_hmac('sha256', $timestamp, $key), $signature)) {
            throw new ApiException('Invalid API nonce signature.', 'invalid_nonce', 401);
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
