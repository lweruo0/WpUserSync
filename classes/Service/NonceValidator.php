<?php
declare(strict_types=1);

namespace WpUserSync\classes\Service;

final class NonceValidator
{
    public static function assertValidSignature(string $apiSecret, int $maxAgeSeconds, string $rawBody): void
    {
        if ($apiSecret === '') {
            throw new ApiException('API secret is not configured.', 'secret_not_configured', 500);
        }

        $clientId = self::getHeader('HTTP_X_API_CLIENT_ID');
        $timestamp = self::getHeader('HTTP_X_API_TIMESTAMP');
        $nonce = self::getHeader('HTTP_X_API_NONCE');
        $bodyHash = strtolower(self::getHeader('HTTP_X_API_BODY_SHA256'));
        $signature = strtolower(self::getHeader('HTTP_X_API_SIGNATURE'));

        if ($clientId === '') {
            throw new ApiException('Missing API client id.', 'missing_client_id', 401);
        }

        if ($timestamp === '') {
            throw new ApiException('Missing API timestamp.', 'missing_timestamp', 401);
        }

        if ($nonce === '') {
            throw new ApiException('Missing API nonce.', 'missing_nonce', 401);
        }

        if ($bodyHash === '') {
            throw new ApiException('Missing API body hash.', 'missing_body_hash', 401);
        }

        if ($signature === '') {
            throw new ApiException('Missing API signature.', 'missing_signature', 401);
        }

        $key = self::deriveKey($apiSecret);

        if (!ctype_digit($timestamp)) {
            throw new ApiException('Invalid API nonce timestamp.', 'invalid_nonce', 401);
        }

        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $clientId)) {
            throw new ApiException('Invalid API client id format.', 'invalid_client_id', 401);
        }

        if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)) {
            throw new ApiException('Invalid API nonce format.', 'invalid_nonce', 401);
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $bodyHash)) {
            throw new ApiException('Invalid API body hash format.', 'invalid_body_hash', 401);
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new ApiException('Invalid API signature format.', 'invalid_signature', 401);
        }

        $actualBodyHash = hash('sha256', $rawBody);
        if (!hash_equals($actualBodyHash, $bodyHash)) {
            throw new ApiException('Invalid API body hash.', 'invalid_body_hash', 401);
        }

        $age = time() - (int) $timestamp;
        if ($age < -3 || $age > $maxAgeSeconds) {
            throw new ApiException('API nonce expired.', 'nonce_expired '.$timestamp. ' '. time(), 402);
        }

        self::assertNonceUnused($clientId, $nonce, $timestamp, $maxAgeSeconds);

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = self::getCanonicalPath();
        $canonical = implode("\n", array($method, $path, $clientId, $timestamp, $nonce, $bodyHash));
        $expectedSignature = hash_hmac('sha256', $canonical, $key);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new ApiException('Invalid API signature.', 'invalid_signature', 401);
        }
    }

    private static function deriveKey(string $apiSecret): string
    {
        $normalized = trim($apiSecret);
        if ($normalized === '') {
            throw new ApiException('API secret is not configured.', 'secret_not_configured', 500);
        }

        return $normalized;
    }

    private static function getHeader(string $name): string
    {
        if (!empty($_SERVER[$name])) {
            return trim((string) $_SERVER[$name]);
        }

        return '';
    }

    private static function getCanonicalPath(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($requestUri, PHP_URL_PATH);

        return $path !== '' ? $path : '/';
    }

    private static function assertNonceUnused(string $clientId, string $nonce, string $timestamp, int $maxAgeSeconds): void
    {
        $storageDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wpusersync_nonces';
        if (!is_dir($storageDir) && !mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
            throw new ApiException('Nonce storage is not available.', 'nonce_storage_unavailable', 500);
        }

        $clientDir = $storageDir . DIRECTORY_SEPARATOR . hash('sha256', strtolower($clientId));
        if (!is_dir($clientDir) && !mkdir($clientDir, 0700, true) && !is_dir($clientDir)) {
            throw new ApiException('Nonce storage is not available.', 'nonce_storage_unavailable', 500);
        }

        if (random_int(1, 50) === 1) {
            self::cleanupNonceStore($clientDir, $maxAgeSeconds);
        }

        $file = $clientDir . DIRECTORY_SEPARATOR . hash('sha256', $nonce) . '.nonce';
        $handle = @fopen($file, 'x');
        if ($handle === false) {
            throw new ApiException('API nonce was already used.', 'replayed_nonce', 401);
        }

        $written = @fwrite($handle, $timestamp . PHP_EOL);
        @fclose($handle);

        if ($written === false) {
            @unlink($file);
            throw new ApiException('Nonce storage write failed.', 'nonce_storage_unavailable', 500);
        }
    }

    private static function cleanupNonceStore(string $storageDir, int $maxAgeSeconds): void
    {
        $files = glob($storageDir . DIRECTORY_SEPARATOR . '*.nonce') ?: array();
        $threshold = time() - max(1, $maxAgeSeconds);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (filemtime($file) !== false && (int) filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }
}
