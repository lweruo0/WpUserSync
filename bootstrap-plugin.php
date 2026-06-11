<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'WpUserSync\\classes\\';
    $baseDir = __DIR__ . '/classes/';

    $length = strlen($prefix);
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relativeClass = substr($class, $length);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

// Load functions from the Arbeitsdienst plugin if available
$arbeitsDienstFunctions = dirname(__DIR__) . '/arbeitsdienst/system/common_function.php';
if (is_file($arbeitsDienstFunctions)) {
    //require_once $arbeitsDienstFunctions;
}
