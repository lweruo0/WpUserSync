<?php

declare(strict_types=1);

require_once __DIR__ . '/../../system/common.php';
require_once __DIR__ . '/bootstrap.php';

use WpUserSync\classes\WpUserSync;

try {
    $plugin = WpUserSync::getInstance();
    $plugin->doRender(isset($page) ? $page : null);
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo $e->getMessage();
}
