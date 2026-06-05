<?php
declare(strict_types=1);

namespace WpUserSync\classes;

final class Config
{
    public static function load(string $pluginDir): array
    {
        $defaults = array(
            'enabled' => true,
            'require_https' => true,
            'assign_default_roles' => true,
            'api_token_hash' => '',
            'nonce_max_age' => 300
        );

        $configFile = $pluginDir . '/config.php';
        if (!is_file($configFile)) {
            return $defaults;
        }

        require $configFile;

        return array(
            'enabled' => isset($plg_wpusersync_enabled) ? (bool) $plg_wpusersync_enabled : $defaults['enabled'],
            'require_https' => isset($plg_wpusersync_require_https) ? (bool) $plg_wpusersync_require_https : $defaults['require_https'],
            'assign_default_roles' => isset($plg_wpusersync_assign_default_roles) ? (bool) $plg_wpusersync_assign_default_roles : $defaults['assign_default_roles'],
            'api_token_hash' => isset($plg_wpusersync_api_token_hash) ? (string) $plg_wpusersync_api_token_hash : $defaults['api_token_hash'],
            'nonce_max_age' => isset($plg_wpusersync_nonce_max_age) ? (int) $plg_wpusersync_nonce_max_age : $defaults['nonce_max_age']
        );
    }
}
