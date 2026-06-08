<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-admidio.php';
require_once __DIR__ . '/../bootstrap-plugin.php';

use WpUserSync\classes\Service\ApiException;
use WpUserSync\classes\Service\NonceValidator;
use WpUserSync\classes\Service\JsonResponder;
use WpUserSync\classes\Service\RequestValidator;
use WpUserSync\classes\Service\UserProvisioningService;

global $plg_wpusersync_enabled;
global $plg_wpusersync_api_secret;
global $plg_wpusersync_nonce_max_age;
global $plg_wpusersync_require_https;

try {

    if (!($plg_wpusersync_enabled ?? true)) {
        throw new ApiException('Plugin is disabled.', 'plugin_disabled', 403);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false) {
        throw new ApiException('Unable to read request body.', 'invalid_request_body', 400);
    }

    NonceValidator::assertValidSignature(
        (string) ($plg_wpusersync_api_secret ?? ''),
        (int) ($plg_wpusersync_nonce_max_age ?? 300),
        $rawBody
    );

    $payload = RequestValidator::decodeJsonReadRequest((bool) ($plg_wpusersync_require_https ?? true), $rawBody);
    $service = new UserProvisioningService($gDb, $gProfileFields);
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
