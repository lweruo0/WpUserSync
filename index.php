<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap-admidio.php';
require_once __DIR__ . '/bootstrap-plugin.php';

use WpUserSync\classes\WpUserSyncPlugin;

try {
    $plugin = new WpUserSyncPlugin(__DIR__);
    $plugin->render();
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo $e->getMessage();
}
