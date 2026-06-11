<?php
declare(strict_types=1);

/**
 * Bootstrap Admidio for classic 5.0.x plugin installations.
 * Supports both current adm_program structure and alternate example layouts.
 */

$pluginDir = __DIR__;
$pluginsDir = dirname($pluginDir);
$admidioRoot = dirname($pluginsDir);



$pathsToTry = array(
    $admidioRoot . '/adm_program/system/common.php',
    $admidioRoot . '/system/common.php'
);

foreach ($pathsToTry as $path) {
    if (is_file($path)) {
        require_once $path;
        return;
    }
}

if (
    is_file($admidioRoot . '/adm_my_files/config.php')
    && is_file($admidioRoot . '/adm_program/system/bootstrap/bootstrap.php')
) {
    require_once $admidioRoot . '/adm_my_files/config.php';
    require_once $admidioRoot . '/adm_program/system/bootstrap/bootstrap.php';
    return;
}

throw new RuntimeException('Admidio bootstrap could not be found.');
