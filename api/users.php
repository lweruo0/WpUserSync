<?php

declare(strict_types=1);

use WpUserSync\classes\WpUserSync;
use WpUserSync\classes\Service\ApiAuth;
use WpUserSync\classes\Service\ApiException;
use WpUserSync\classes\Service\JsonResponder;
use WpUserSync\classes\Service\RequestValidator;
use WpUserSync\classes\Service\UserProvisioningService;

require_once __DIR__ . '/../../../system/common.php';

try {
    $plugin = WpUserSync::getInstance();
    $config = $plugin::getPluginConfigValues();

    if (empty($config['wp_user_sync_enabled'])) {
        throw new ApiException('Plugin is disabled.', 'plugin_disabled', 403);
    }

    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    ApiAuth::assertAllowedIp((string) ($config['wp_user_sync_allowed_ips'] ?? ''), $clientIp);
    ApiAuth::assertBearerToken(
        (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        (string) ($config['wp_user_sync_api_token_hash'] ?? '')
    );

    $payload = RequestValidator::decodeJsonRequest((bool) ($config['wp_user_sync_require_https'] ?? true));
    $service = new UserProvisioningService($gDb, $gProfileFields, $config);
    $result = $service->upsert($payload);

    JsonResponder::send($result, $result['status'] === 'created' ? 201 : 200);
} catch (ApiException $e) {
    JsonResponder::sendError($e);
} catch (Throwable $e) {
    JsonResponder::send(array(
        'status' => 'error',
        'code' => 'server_error',
        'message' => $e->getMessage(),
    ), 500);
}
