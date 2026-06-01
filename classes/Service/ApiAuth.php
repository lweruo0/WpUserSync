<?php

declare(strict_types=1);

namespace WpUserSync\classes\Service;

final class ApiAuth
{
    public static function assertAllowedIp(string $allowedIps, string $clientIp): void
    {
        $allowedIps = trim($allowedIps);
        if ($allowedIps === '') {
            return;
        }

        $allowed = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $allowedIps) ?: array()));
        if (!in_array($clientIp, $allowed, true)) {
            throw new ApiException('IP address is not allowed.', 'ip_not_allowed', 403);
        }
    }

    public static function assertBearerToken(string $authorizationHeader, string $expectedHash): void
    {
        if ($expectedHash === '') {
            throw new ApiException('API token hash is not configured.', 'token_not_configured', 500);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            throw new ApiException('Missing Bearer token.', 'missing_token', 401);
        }

        $incomingHash = hash('sha256', trim($matches[1]));
        if (!hash_equals(strtolower($expectedHash), strtolower($incomingHash))) {
            throw new ApiException('Invalid API token.', 'invalid_token', 401);
        }
    }
}
