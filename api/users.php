<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-admidio.php';
require_once __DIR__ . '/../bootstrap-plugin.php';

use WpUserSync\classes\Config;
use WpUserSync\classes\Service\ApiAuth;
use WpUserSync\classes\Service\ApiException;
use WpUserSync\classes\Service\JsonResponder;
use WpUserSync\classes\Service\RequestValidator;
use WpUserSync\classes\Service\UserProvisioningService;

try {

    $config = Config::load(dirname(__DIR__));

    if (empty($config['enabled'])) {
        throw new ApiException('Plugin is disabled.', 'plugin_disabled', 403);
    }

    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    ApiAuth::assertAllowedIp((string) ($config['allowed_ips'] ?? ''), $clientIp);
    ApiAuth::assertToken(
        (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        (string) ($config['api_token_hash'] ?? '')
    );

    $payload = RequestValidator::decodeJsonRequest((bool) ($config['require_https'] ?? true));
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
