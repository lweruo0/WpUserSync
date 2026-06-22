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
        $Service = new UserReadService($gDb, $gProfileFields, $_GET);
    } else if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
        $Service = new UserWriteService($gDb, $gProfileFields, $_POST, $payload);
    } else if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'DELETE') {
        $Service = new UserWriteService($gDb, $gProfileFields, $_POST, $payload);
    }
     else {
        throw new ApiException('Only GET/POST/DELETE methods are allowed.', 'method_not_allowed', 405);
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
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/search')) {
        $result = $Service->searchUser();
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users')) {
        $result = $Service->listUsers();
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}')) {
        $uuid = (string) $router->getPathParam('uuid');
        $result = $Service->getUser($uuid);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/fields')) {
        $uuid = (string) $router->getPathParam('uuid');
        $result = $Service->getUserFields($uuid);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/fields/{name}')) {
        $uuid = (string) $router->getPathParam('uuid');
        $name = (string) $router->getPathParam('name');
        $result = $Service->getUserField($uuid, $name);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/memberships')) {
        $uuid = (string) $router->getPathParam('uuid');
        $result = $Service->getUserMemberships($uuid);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/memberships/{year}')) {
        $uuid = (string) $router->getPathParam('uuid');
        $year = (int) $router->getPathParam('year');
        $result = $Service->getUserMemberships($uuid, $year);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/arbeitsdienst')) {
        $uuid = (string) $router->getPathParam('uuid');
        $result = $Service->getUserArbeitsdienst($uuid);
    } elseif ($router->match('GET', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/arbeitsdienst/{year}')) {
        $uuid = (string) $router->getPathParam('uuid');
        $year = (int) $router->getPathParam('year');
        $result = $Service->getUserArbeitsdienst($uuid, $year);
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/new')) {
        $result = $Service->createUser();
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/fields')) {
        $uuid = (string) $router->getPathParam('uuid');
        $result = $Service->setUserField($uuid);
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/fields/{name}')) {
        $uuid = (string) $router->getPathParam('uuid');
        $name = (string) $router->getPathParam('name');
        $result = $Service->setUserFieldByName($uuid, $name);
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/memberships')) {
        $uuid = (string) $router->getPathParam('uuid');
        $result = $Service->updateMemberships($uuid);
    } elseif ($router->match('POST', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/arbeitsdienst/{year}')) {
        $uuid = (string) $router->getPathParam('uuid');
        $year = (int) $router->getPathParam('year');
        $result = $Service->setUserArbeitsdienst($uuid, $year);
    } elseif ($router->match('DELETE', '/adm_plugins/wpusersync/api/v1/core/users/{uuid}/arbeitsdienst/{id}')) {
        $uuid = (string) $router->getPathParam('uuid');
        $id = (int) $router->getPathParam('id');
        $result = $Service->deleteUserArbeitsdienst($uuid, $id);
    } else {
        throw new ApiException('Endpoint not found.', 'not_found', 404, array('path' => $router->getPath(), 'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'));
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