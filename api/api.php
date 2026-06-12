<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-admidio.php';
require_once __DIR__ . '/../bootstrap-plugin.php';

use WpUserSync\classes\Service\ApiException;
use WpUserSync\classes\Service\ApiRouter;
use WpUserSync\classes\Service\NonceValidator;
use WpUserSync\classes\Service\JsonResponder;
use WpUserSync\classes\Service\UserReadService;
use WpUserSync\classes\Service\UserWriteService;

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
        $rawBody = '';
    }

    NonceValidator::assertValidSignature(
        (string) ($plg_wpusersync_api_secret ?? ''),
        (int) ($plg_wpusersync_nonce_max_age ?? 300),
        $rawBody
    );

    if ($plg_wpusersync_require_https && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
        throw new ApiException('HTTPS is required.', 'https_required', 400);
    }

    $payload = $rawBody !== '' ? json_decode($rawBody, true) : array();
    if (!is_array($payload)) {
        $payload = array();
    }    
    global $gDb, $gProfileFields; // Assuming $gDb is defined in the bootstrap files
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'GET') {
        $Service = new UserReadService($gDb, $gProfileFields, $_GET, $payload);
    } else if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
        $Service = new UserWriteService($gDb, $gProfileFields, $_POST, $payload);
    } else {
        throw new ApiException('Only GET and POST methods are allowed.', 'method_not_allowed', 405);
    }


    $result = null;
    $router = new ApiRouter();
    if ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/categories')) {
        $result = $Service->listCategories();
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/categories/{type}')) {
        $type = (string) $router->getPathParam('type');
        $result = $Service->listCategories($type);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/roles')) {
        $result = $Service->listRoles();
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users')) {
        $result = $Service->listUsers();
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{userId}')) {
        $userId = (int) $router->getPathParam('userId');
        $result = $Service->getUser($userId);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/fields')) {
        $userId = (int) $router->getPathParam('userId');
        $result = $Service->getUserFields($userId);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/fields/{name}')) {
        $userId = (int) $router->getPathParam('userId');
        $name = (string) $router->getPathParam('name');
        $result = $Service->getUserField($userId, $name);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/memberships')) {
        $userId = (int) $router->getPathParam('userId');
        $result = $Service->getUserMemberships($userId);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/memberships/{year}')) {
        $userId = (int) $router->getPathParam('userId');
        $year = (int) $router->getPathParam('year');
        $result = $Service->getUserMemberships($userId, $year);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/arbeitsdienst')) {
        $userId = (int) $router->getPathParam('userId');
        $result = $Service->getUserArbeitsdienst($userId);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/arbeitsdienst/{year}')) {
        $userId = (int) $router->getPathParam('userId');
        $year = (int) $router->getPathParam('year');
        $result = $Service->getUserArbeitsdienst($userId, $year);
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/fields')) {
        $userId = (int) $router->getPathParam('userId');
        $result = $Service->setUserField($userId);
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/fields/{name}')) {
        $userId = (int) $router->getPathParam('userId');
        $name = (string) $router->getPathParam('name');
        $result = $Service->setUserFieldByName($userId, $name);
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/{userId}/memberships/{year}')) {
        $userId = (int) $router->getPathParam('userId');
        $year = (int) $router->getPathParam('year');
        $result = $Service->setUserMemberships($userId, $year);

    } else {
        throw new ApiException('Endpoint not found.', 'not_found', 404);
    }

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