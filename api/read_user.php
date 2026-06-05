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
        (string) ($config['api_token_hash'] ?? '')
    );

    $payload = RequestValidator::decodeJsonReadRequest((bool) ($config['require_https'] ?? true));
    $service = new UserProvisioningService($gDb, $gProfileFields, $config);
    $result = $service->read_userdata($payload);

    JsonResponder::send($result);
} catch (ApiException $e) {
    JsonResponder::sendError($e);
} catch (Throwable $e) {
    JsonResponder::send(array(
        'status' => 'error',
        'code' => 'server_error',
        'message' => $e->getMessage(),
    ), 500);
}
